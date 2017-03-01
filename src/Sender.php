<?php

namespace tecsvit\apns\src;

use \yii\base\Component;

/**
 * Class Sender
 * @package tecsvit\apns
 * @author Aleksandr Mokhonko
 * @version 1.0.0
 *
 * @property string $apnsHost
 * @property string $apnsHostProd
 * @property string $apnsHostTest
 * @property integer $apnsPort
 * @property string $apnsCert
 * @property string $apnsCertProd
 * @property string $apnsCertTest
 * @property string $apnsPassphrase
 * @property integer $timeout
 * @property bool $prodMode
 * @property array $errorResponse
 *
 * @property resource $_socketClient
 * @property resource $_context
 * @property string $_error
 * @property string $_errorString
 */
class Sender extends Component
{
	public $apnsHost				= '';
	public $apnsHostProd			= '';
	public $apnsHostTest			= '';

	public $apnsPort;

	public $apnsCert				= '';
	public $apnsCertProd			= '';
	public $apnsCertTest			= '';

	public $apnsPassphrase;
	public $timeout					= 0;
	public $prodMode				= true;
	public $errorResponse			= [];

	private $_socketClient;
	private $_context;
	private $_error;
	private $_errorString;

	public function init()
	{
		$this->_checkConfig();
		parent::init();
	}

	/**
	 * @param array $alert Example: ['alert' => 'Push Message']
	 * @param string $token
	 * @param bool $closeAfterPush
	 * @return bool
	 */
	public function push(array $alert, $token, $closeAfterPush = true)
	{
		if(true !== YII_ENV_PROD && true === $this->prodMode) {
			return true;
		}

		$this->_setCert();
		$this->_setHost();
		$this->_setContext();
		$this->_createStreamSocket();

		//$alert = ['alert' => 'Push Message'];
		$body['aps'] = ['badge' => +1, 'sound' => 'default'];

		$body['aps'] = array_merge($body['aps'], $alert);

		try {
			$payload = json_encode($body);
			$tokenPack = pack('H*', $token);
			$message = chr(0) . pack('n', 32) . $tokenPack . pack('n', strlen($payload)) . $payload;
		}
		catch(\Exception $e) {
			$this->errorResponse['message'] = $e->getMessage();
			return false;
		}

		stream_set_blocking($this->_socketClient, 0);

		fwrite($this->_socketClient, $message, strlen($message));

		usleep($this->timeout);

		$errorAppleResponse = fread($this->_socketClient, 6);

		if(!empty($errorAppleResponse)) {
			/**
				'command' => 8
				'status_code' => 7
				'identifier' => 0
			 */
			$this->errorResponse = unpack('Ccommand/Cstatus_code/Nidentifier', $errorAppleResponse);
			$this->_createErrorMessage();
			$this->closeConnection();
			return false;
		}

		if(true === $closeAfterPush) {
			$this->closeConnection();
		}

		return true;
	}

	/**
	 * @return bool
	 */
	public function closeConnection()
	{
		return fclose($this->_socketClient);
	}

	/**
	 * @return array
	 */
	public function getErrors()
	{
		return $this->errorResponse;
	}

	/**
	 * @return bool
	 */
	public function hasErrors()
	{
		return !empty($this->errorResponse);
	}

	/**
	 * @return void
	 */
	private function _setContext()
	{
		$this->_context = stream_context_create();
		stream_context_set_option($this->_context, 'ssl', 'local_cert', $this->apnsCert);

		if(!empty($this->apnsPassphrase)) {
			stream_context_set_option($this->_context, 'ssl', 'passphrase', $this->apnsPassphrase);
		}
	}

	/**
	 * @return bool
	 */
	private function _createStreamSocket()
	{
		$this->_socketClient = stream_socket_client(
			'ssl://' . $this->apnsHost . ':' . $this->apnsPort,
			$this->_error,
			$this->_errorString,
			60,
			STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT,
			$this->_context
		);

		if(!$this->_socketClient) {
			$this->errorResponse['message'] = $this->_errorString;
			return false;
		}
		else {
			return true;
		}
	}

	/**
	 * @return void
	 */
	private function _createErrorMessage()
	{
		if(isset($this->errorResponse['status_code'])) {
			switch($this->errorResponse['status_code']) {
				case 0:
					$this->errorResponse['message'] = '0-No errors encountered';
					break;
				case 1:
					$this->errorResponse['message'] = '1-Processing error';
					break;
				case 2:
					$this->errorResponse['message'] = '2-Missing device token';
					break;
				case 3:
					$this->errorResponse['message'] = '3-Missing topic';
					break;
				case 4:
					$this->errorResponse['message'] = '4-Missing payload';
					break;
				case 5:
					$this->errorResponse['message'] = '5-Invalid token size';
					break;
				case 6:
					$this->errorResponse['message'] = '6-Invalid topic size';
					break;
				case 7:
					$this->errorResponse['message'] = '7-Invalid payload size';
					break;
				case 8:
					$this->errorResponse['message'] = '8-Invalid token';
					break;
				case 255:
					$this->errorResponse['message'] = '255-None (unknown)';
					break;
				default:
					$this->errorResponse['message'] = $this->errorResponse['status_code'] . '-Not listed';
					break;
			}
		}
		else {
			$this->errorResponse['message'] = 'CHECK';
		}
	}

	/**
	 * @return void
	 */
	private function _setCert()
	{
		$this->apnsCert = YII_ENV_PROD ? $this->apnsCertProd : $this->apnsCertTest;
	}

	/**
	 * @return void
	 */
	private function _setHost()
	{
		$this->apnsHost = YII_ENV_PROD ? $this->apnsHostProd : $this->apnsHostTest;
	}

	/**
	 * @throws \Exception
	 */
	private function _checkConfig()
	{
		if(YII_ENV_PROD && empty($this->apnsHostProd)) {
			throw new \Exception('Apns Host Prod is empty');
		}

		if(!YII_ENV_PROD && empty($this->apnsHostTest) && false === $this->prodMode) {
			throw new \Exception('Apns Host Test is empty');
		}

		if(empty($this->apnsPort)) {
			throw new \Exception('Apns Port is empty');
		}

		if(YII_ENV_PROD && empty($this->apnsCertProd)) {
			throw new \Exception('Apns Certificate Prod is empty');
		}

		if(!YII_ENV_PROD && empty($this->apnsCertTest)) {
			throw new \Exception('Apns Certificate Test is empty');
		}
	}
}
