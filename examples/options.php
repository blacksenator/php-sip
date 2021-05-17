<?php

require_once '../src/Exceptions/PhpSipException.php';
require_once('../src/PhpSip.php');

use level7systems\PhpSip;

/* Sends Anonymous OPTIONS to eu.sip.ssl7.net */

$bindIP = '192.168.5.65'; // <-- change this to your own IP

try {
    $api = new PhpSip($bindIP);

    $api->setDebug(true);
    $api->setMethod('OPTIONS');
    $api->setFrom('sip:anonymous@localhost');
    $api->setUri('sip:test@eu.sip.ssl7.net');

    $res = $api->send();

    echo 'response: ' . $res . PHP_EOL;
} catch (Exception $e) {
    echo $e;
}
