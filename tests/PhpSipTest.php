<?php

use EasybellLibs\PhpSip;
use EasybellLibs\Sockets\SocketProxy;
use PHPUnit\Framework\TestCase;

/**
 * @runClassInSeparateProcess
 */
class PhpSipTest extends TestCase
{
    public function testRegister(): void
    {
        $api = new PhpSip;

        $api->setUsername('00493050931632');
        $api->setPassword('secret');
        $api->setMethod('REGISTER');

        $api->setFrom('sip:00493050931632@sip.easybell.de');
        $api->setUri('sip:00493050931632@sip.easybell.de');

        $this->assertEquals('200', $api->send());
    }

    public function testRegisterSipwise(): void
    {
        $api = new PhpSip;

        $api->setUsername('3195388t0');
        $api->setPassword('secret');
        $api->setMethod('REGISTER');

        $api->setFrom('sip:3195388t0@sipconnect.sipgate.de');
        $api->setUri('sip:3195388t0@sipconnect.sipgate.de');

        $this->assertEquals('200', $api->send());
    }

    public function testDeregister(): void
    {
        $api = new PhpSip;

        $api->setUsername('00493050931632');
        $api->setPassword('secret');
        $api->setMethod('REGISTER');

        $api->addHeader('Expires: 0');

        $api->setFrom('sip:00493050931632@sip.easybell.de');
        $api->setUri('sip:00493050931632@sip.easybell.de');

        $this->assertEquals('200', $api->send());
    }

    public function testDeregisterSipwise(): void
    {
        $api = new PhpSip;

        $api->setUsername('3195388t0');
        $api->setPassword('secret');
        $api->setMethod('REGISTER');

        $api->addHeader('Expires: 0');

        $api->setFrom('sip:3195388t0@sipconnect.sipgate.de');
        $api->setUri('sip:3195388t0@sipconnect.sipgate.de');

        $this->assertEquals('200', $api->send());
    }

    protected function assertPreConditions(): void
    {
        $socketMock = Mockery::mock('overload:' . SocketProxy::class);

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $socketMock->shouldReceive('create')->andReturn($socket);
        $socketMock->shouldReceive('bind')->andReturn(true);
        $socketMock->shouldReceive('setOption')->andReturn(true);
        $socketMock->shouldReceive('sendTo')->andReturn(true);
        $socketMock->shouldReceive('receiveFrom')->once()->andReturn(
            'SIP/2.0 401 Unauthorized' . PHP_EOL .
            'Via: SIP/2.0/UDP 192.168.144.2:5079;rport=50451;branch=z9hG4bK702430;received=79.140.179.49' . PHP_EOL .
            'From: <sip:00493050931632@sip.easybell.de>;tag=48814' . PHP_EOL .
            'To: <sip:00493050931632@sip.easybell.de>;tag=95c37a12bff1a2c36d72bf8333176544.7855' . PHP_EOL .
            'Call-ID: d67c6497f77c62e3d704aed605b6d374@192.168.144.2' . PHP_EOL .
            'CSeq: 20 REGISTER' . PHP_EOL .
            'P-NGCP-Auth-IP: 192.168.251.44' . PHP_EOL .
            'P-NGCP-Auth-UA: PHP SIP' . PHP_EOL .
            'WWW-Authenticate: Digest realm="sip.easybell.de", nonce="YKUKemClCU7hC7TQYJoISCtbXfDuXV5P"' . PHP_EOL .
            'Server: Sipwise NGCP Proxy 7.X' . PHP_EOL .
            'Content-Length: 0' . PHP_EOL .
            PHP_EOL . PHP_EOL
        );
        $socketMock->shouldReceive('receiveFrom')->once()->andReturn(
            'SIP/2.0 200 OK' . PHP_EOL .
            'Via: SIP/2.0/UDP 192.168.144.2:5079;rport=60144;branch=z9hG4bK180478;received=79.140.179.49' . PHP_EOL .
            'From: <sip:00493050931632@sip.easybell.de>;tag=52230' . PHP_EOL .
            'To: <sip:00493050931632@sip.easybell.de>;tag=95c37a12bff1a2c36d72bf8333176544.7855' . PHP_EOL .
            'Call-ID: e646f16784bb364d93d6a9aa21e5375f@192.168.144.2' . PHP_EOL .
            'CSeq: 21 REGISTER' . PHP_EOL .
            'P-NGCP-Auth-IP: 192.168.251.44' . PHP_EOL .
            'P-NGCP-Auth-UA: PHP SIP' . PHP_EOL .
            'Server: Sipwise NGCP Proxy 7.X' . PHP_EOL .
            'Contact: <sip:00493050931632@192.168.144.2:5079>;expires=90' . PHP_EOL .
            'Content-Length: 0' . PHP_EOL .
            PHP_EOL . PHP_EOL
        );
        $socketMock->shouldReceive('close');
    }
}
