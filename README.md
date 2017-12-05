Yii2 APNs Extension
===================
Extension for sending Apple push notification

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist tecsvit/yii2-apns "*"
```

or add

```
"tecsvit/yii2-apns": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by:

into config file:
```php
'components' => [
    ...
    'apns' => [
        'class' => '\tecsvit\apns\src\Sender',
        'apnsHostProd'  => 'gateway.push.apple.com',
        'apnsHostTest'  => 'gateway.sandbox.push.apple.com',
        'apnsPort'      => 2195,
        'apnsCertProd'  => dirname(__DIR__) . '/path/to/prod-serc/apple_push_notification_production.pem',
        'apnsCertTest'  => dirname(__DIR__) . '/path/to/test-serc/apple_push_notification_test.pem',
        'apnsPassphrase'=> dirname(__DIR__) . '/path/to/passphare',
        'timeout'       => 500000, //microseconds,
        'mode'          => 'prod' //'prod', 'dev' or 'test', default 'dev'
    ],
    ...
]

```
into your code:
```php
/**
 * @param array $alert Example: ['alert' => 'Push Message'] 
 * @param string $token Apple token device
 * @param bool $closeAfterPush Close the connection after the push?
 */
Yii::$app->apns->send($alert, $token, $closeAfterPush); ?>

```