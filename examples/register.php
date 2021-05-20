<?php

require_once '../src/Exceptions/PhpSipException.php';
require_once('../src/Sockets/SocketProxy.php');
require_once('../src/PhpSip.php');

use EasybellLibs\PhpSip;

try {
    $api = new PhpSip();

    $api->setDebug(true);
    $api->setUsername('username'); // authentication username
    $api->setPassword('...'); // authentication password
    $api->setMethod('REGISTER');
    $api->setFrom('sip:username@sipprovider.com');
    $api->setUri('sip:username@sipprovider.com');

    $res = $api->send();

    echo 'response: ' . $res . PHP_EOL;
} catch (Exception $e) {
    echo $e;
}
