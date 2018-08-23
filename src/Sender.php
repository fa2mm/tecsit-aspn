<?php

namespace tecsvit\apns\src;

use \yii\base\Component;
use \yii\base\ErrorException;

/**
 * Class Sender
 * @package tecsvit\apns
 * @author Aleksandr Mokhonko
 * @version 1.1.1
 *
 * @property string     $apnsHost
 * @property string     $apnsHostProd
 * @property string     $apnsHostTest
 * @property integer    $apnsPort
 * @property string     $apnsCert
 * @property string     $apnsCertProd
 * @property string     $apnsCertTest
 * @property string     $apnsPassphrase
 * @property integer    $timeout
 * @property string     $mode
 * @property array      $errorResponse
 *
 * @property resource   $socketClient
 * @property resource   $context
 * @property string     $error
 * @property string     $errorString
 */
class Sender extends Component
{
    const MODE_DEV                  = 'dev';
    const MODE_TEST                 = 'test';
    const MODE_PROD                 = 'prod';

    public $apnsHost                = '';
    public $apnsHostProd            = '';
    public $apnsHostTest            = '';

    public $apnsPort;

    public $apnsCert                = '';
    public $apnsCertProd            = '';
    public $apnsCertTest            = '';

    public $apnsPassphrase;
    public $timeout                 = 0;
    public $mode                    = self::MODE_DEV;
    public $errorResponse           = [];
    public $defaultBody = ['badge' => +1, 'sound' => 'default'];

    private $socketClient;
    private $context;
    private $error;
    private $errorString;

    /**
     * @return void
     * @throws \Exception
     */
    public function init()
    {
        $this->checkConfig();
        parent::init();
    }

    /**
     * @param array     $alert Example: ['alert' => 'Push Message']
     * @param string    $token
     * @param bool      $closeAfterPush
     * @return bool
     */
    public function push(array $alert, $token, $closeAfterPush = true)
    {
        if (self::MODE_DEV === $this->mode) {
            return true;
        }

        $this->setCert();
        $this->setHost();
        $this->setContext();

        if (false === $this->createStreamSocket()) {
            return false;
        }

        //$alert = ['alert' => 'Push Message'];
        $body['aps'] = array_merge($this->defaultBody, $alert);

        try {
            $payload    = json_encode($body);
            $tokenPack  = pack('H*', $token);
            $message    = chr(0) . pack('n', 32) . $tokenPack . pack('n', strlen($payload)) . $payload;
        } catch (\Exception $e) {
            $this->errorResponse['message'] = $e->getMessage();
            return false;
        }

        stream_set_blocking($this->socketClient, 0);

        fwrite($this->socketClient, $message, strlen($message));

        usleep($this->timeout);

        $errorAppleResponse = fread($this->socketClient, 6);

        if (!empty($errorAppleResponse)) {
            /**
                'command' => 8
                'status_code' => 7
                'identifier' => 0
             */
            $this->errorResponse = unpack('Ccommand/Cstatus_code/Nidentifier', $errorAppleResponse);
            $this->createErrorMessage();
            $this->closeConnection();
            return false;
        }

        if (true === $closeAfterPush) {
            $this->closeConnection();
        }

        return true;
    }

    /**
     * @return bool
     */
    public function closeConnection()
    {
        return fclose($this->socketClient);
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
    private function setContext()
    {
        $this->context = stream_context_create();
        stream_context_set_option($this->context, 'ssl', 'local_cert', $this->apnsCert);

        if (!empty($this->apnsPassphrase)) {
            stream_context_set_option($this->context, 'ssl', 'passphrase', $this->apnsPassphrase);
        }
    }

    /**
     * @return bool
     */
    private function createStreamSocket()
    {
        try {
            $this->socketClient = stream_socket_client(
                'ssl://' . $this->apnsHost . ':' . $this->apnsPort,
                $this->error,
                $this->errorString,
                60,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
                $this->context
            );
        } catch (ErrorException $e) {
            $this->errorResponse['message'] = $e->getMessage();
            return false;
        }

        if ($this->socketClient) {
            return true;
        } else {
            $this->errorResponse['message'] = $this->errorString;
            return false;
        }
    }

    /**
     * @return void
     */
    private function createErrorMessage()
    {
        if (isset($this->errorResponse['status_code'])) {
            switch ($this->errorResponse['status_code']) {
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
        } else {
            $this->errorResponse['message'] = 'CHECK';
        }
    }

    /**
     * @return void
     */
    private function setCert()
    {
        if ($this->mode === self::MODE_PROD) {
            $this->apnsCert = $this->apnsCertProd;
        } elseif ($this->mode === self::MODE_TEST) {
            $this->apnsCert = $this->apnsCertTest;
        }
    }

    /**
     * @return void
     */
    private function setHost()
    {
        if ($this->mode === self::MODE_PROD) {
            $this->apnsHost = $this->apnsHostProd;
        } elseif ($this->mode === self::MODE_TEST) {
            $this->apnsHost = $this->apnsHostTest;
        }
    }

    /**
     * @throws \Exception
     */
    private function checkConfig()
    {
        if ($this->mode === self::MODE_PROD) {
            if (empty($this->apnsHostProd)) {
                throw new \Exception('Apns Host Prod is empty');
            }

            if (empty($this->apnsCertProd)) {
                throw new \Exception('Apns Certificate Prod is empty');
            }
        } elseif ($this->mode === self::MODE_TEST) {
            if (empty($this->apnsHostTest)) {
                throw new \Exception('Apns Host Test is empty');
            }

            if (empty($this->apnsCertTest)) {
                throw new \Exception('Apns Certificate Test is empty');
            }
        }

        if (empty($this->apnsPort)) {
            throw new \Exception('Apns Port is empty');
        }
    }
}
