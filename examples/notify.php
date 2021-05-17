<?php

require_once '../src/Exceptions/PhpSipException.php';
require_once('../src/PhpSip.php');

use level7systems\PhpSip;

/* Sends NOTIFY to reset Linksys phone */

try {
    $api = new PhpSip();

    $api->setUsername('10000'); // authentication username
    $api->setPassword('secret'); // authentication password
    // $api->setProxy('some_ip_here');
    $api->addHeader('Event: resync');
    $api->setMethod('NOTIFY');
    $api->setFrom('sip:10000@sip.domain.com');
    $api->setUri('sip:10000@sip.domain.com');

    $res = $api->send();

    echo 'response: ' . $res . PHP_EOL;
} catch (Exception $e) {
    echo $e;
}
