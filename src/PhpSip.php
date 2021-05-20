<?php

namespace EasybellLibs;

use EasybellLibs\Exceptions\PhpSipException;
use EasybellLibs\Sockets\SocketProxy;

class PhpSip
{
    private bool $debug = false;
    private int $minPort = 5065;
    private int $maxPort = 5265;
    private int $frTimer = 10000;
    private ?string $lockFile = '/tmp/PhpSip.lock';
    private array $allowedMethods = [
        'CANCEL',
        'NOTIFY',
        'INVITE',
        'BYE',
        'REFER',
        'OPTIONS',
        'SUBSCRIBE',
        'MESSAGE',
        'PUBLISH',
        'REGISTER'
    ];
    private $socket;
    private $socketClient;
    private ?string $srcIp = null;
    private string $userAgent = 'PHP SIP';
    private int $cSeq = 20;
    private ?string $srcPort = null;
    private string $uri;
    private string $host;
    private int $port = 5060;
    private ?string $proxy = null;
    private string $method;
    private string $username;
    private string $password;
    private ?string $to = null;
    private ?string $from = null;
    private string $fromUser;
    private string $via;
    private ?string $body = null;
    private ?string $rxMsg = null;
    private ?string $resCode = null;
    private ?string $auth = null;
    private array $extraHeaders = [];

    public function __construct(?string $srcIp = null)
    {
        if (!function_exists('socket_create')) {
            throw new PhpSipException('socket_create() function missing.');
        }

        if ($srcIp) {
            if (!preg_match('/^[\d]+\.[\d]+\.[\d]+\.[\d]+$/', $srcIp)) {
                throw new PhpSipException('Invalid src_ip ' . $srcIp);
            }
        } elseif (isset($_SERVER['SERVER_ADDR'])) {
            $srcIp = $_SERVER['SERVER_ADDR'];
        } else {
            $addr = gethostbynamel(php_uname('n'));

            if (!is_array($addr) || !isset($addr[0]) || str_starts_with($addr[0], '127')) {
                throw new PhpSipException('Failed to obtain IP address to bind. Please set bind address manually.');
            }

            $srcIp = $addr[0];
        }

        $this->srcIp = $srcIp;
        $this->socketClient = new SocketProxy;

        $this->createSocket();
    }

    public function __destruct()
    {
        $this->closeSocket();
    }

    public function setDebug(bool $status = false): void
    {
        $this->debug = $status;
    }

    public function addHeader(string $header): void
    {
        $this->extraHeaders[] = $header;
    }

    public function setFrom(string $from): void
    {
        $this->from = '<' . $from . '>';

        if (preg_match('/<.*>$/', $from)) {
            $this->from = $from;
        }

        $matches = [];
        if (!preg_match('/sip:(.*)@/i', $this->from, $matches)) {
            throw new PhpSipException('Failed to parse From username.');
        }

        $this->fromUser = $matches[1];
    }

    public function setMethod(string $method): void
    {
        if (!in_array($method, $this->allowedMethods, true)) {
            throw new PhpSipException('Invalid method.');
        }

        $this->method = $method;
    }

    public function setUri(string $uri): void
    {
        if (!str_contains($uri, 'sip:')) {
            throw new PhpSipException('Only sip: URI supported.');
        }

        if (!$this->proxy && str_contains($uri, 'transport=tcp')) {
            throw new PhpSipException('Only UDP transport supported.');
        }

        $this->uri = $uri;

        if (!$this->to) {
            $this->to = '<' . $uri . '>';
        }

        $uri = ($tPos = strpos($uri, ';')) ? substr($uri, 0, $tPos) : $uri;

        $url = str_replace('sip:', 'sip://', $uri);

        if (!$url = @parse_url($url)) {
            throw new PhpSipException('Failed to parse URI "' . $url . '".');
        }

        $this->host = $url['host'];
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function setUserAgent(string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function send(): string
    {
        if (!$this->from) {
            throw new PhpSipException('Missing From.');
        }

        if (!$this->method) {
            throw new PhpSipException('Missing Method.');
        }

        if (!$this->uri) {
            throw new PhpSipException('Missing URI.');
        }

        $data = $this->formatRequest();

        $this->sendData($data);

        $this->readMessage();

        if ($this->resCode === '401') {
            $this->cSeq++;

            $this->authWWW();

            $data = $this->formatRequest();

            $this->sendData($data);

            $this->readMessage();
        }

        $this->extraHeaders = [];
        $this->cSeq++;

        return $this->resCode;
    }

    private function getPort(): void
    {
        if ($this->srcPort) {
            return;
        }

        if ($this->minPort > $this->maxPort) {
            throw new PhpSipException ('Min port is bigger than max port.');
        }

        $filePointer = @fopen($this->lockFile, 'a+');

        if (!$filePointer) {
            throw new PhpSipException ('Failed to open lock file ' . $this->lockFile);
        }

        $canWrite = flock($filePointer, LOCK_EX);

        if (!$canWrite) {
            throw new PhpSipException ('Failed to lock a file in 1000 ms.');
        }

        clearstatcache();
        $size = filesize($this->lockFile);

        $ports = false;
        if ($size) {
            $contents = fread($filePointer, $size);

            $ports = explode(',', $contents);
        }

        ftruncate($filePointer, 0);
        rewind($filePointer);

        if (!$ports) {
            if (!fwrite($filePointer, $this->minPort)) {
                throw new PhpSipException('Fail to write data to a lock file.');
            }

            $this->srcPort = $this->minPort;
        } else {
            $srcPort = null;

            for ($i = $this->minPort; $i <= $this->maxPort; $i++) {
                if (!in_array($i, $ports)) {
                    $srcPort = $i;

                    break;
                }
            }

            if (!$srcPort) {
                throw new PhpSipException('No more ports left to bind.');
            }

            $ports[] = $srcPort;

            if (!fwrite($filePointer, implode(',', $ports))) {
                throw new PhpSipException('Failed to write data to lock file.');
            }

            $this->srcPort = $srcPort;
        }

        if (!fclose($filePointer)) {
            throw new PhpSipException('Failed to close lock_file');
        }
    }

    private function releasePort(): void
    {
        if ($this->lockFile === null) {
            return;
        }

        $filePointer = fopen($this->lockFile, 'r+');

        if (!$filePointer) {
            throw new PhpSipException('Can\'t open lock file.');
        }

        $canWrite = flock($filePointer, LOCK_EX);

        if (!$canWrite) {
            throw new PhpSipException('Failed to lock a file in 1000 ms.');
        }

        clearstatcache();

        $size = filesize($this->lockFile);
        $content = fread($filePointer, $size);

        $ports = explode(',', $content);

        $key = array_search($this->srcPort, $ports);

        unset($ports[$key]);

        ftruncate($filePointer, 0);
        rewind($filePointer);

        if ($ports && !fwrite($filePointer, implode(',', $ports))) {
            throw new PhpSipException('Failed to save data in lock_file');
        }

        flock($filePointer, LOCK_UN);

        if (!fclose($filePointer)) {
            throw new PhpSipException('Failed to close lock_file');
        }
    }

    private function sendData($data): void
    {
        if (!$this->host || !$this->port) {
            throw new PhpSipException('Can\'t send data, host undefined');
        }

        if (!$data) {
            throw new PhpSipException('Can\'t send - empty data');
        }

        $ipAddress = $this->host;
        if (!preg_match('/^[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}$/', $this->host)) {
            $ipAddress = gethostbyname($this->host);

            if ($ipAddress === $this->host) {
                throw new PhpSipException('DNS resolution of ' . $this->host . ' failed');
            }
        }

        if (!@$this->socketClient->sendTo($this->socket, $data, strlen($data), 0, $ipAddress, $this->port)) {
            $errorNumber = $this->socketClient->getLastError($this->socket);
            throw new PhpSipException('Failed to send data to ' . $ipAddress . ':' . $this->port .
                '. Source IP ' . $this->srcIp . ', source port: ' . $this->srcPort . '. ' .
                $this->socketClient->getErrorString($errorNumber));
        }

        if ($this->debug) {
            $temp = explode("\r" . PHP_EOL, $data);

            echo '--> ' . $temp[0] . PHP_EOL;
        }
    }

    private function readMessage(): void
    {
        $from = '';
        $port = 0;
        $this->rxMsg = $this->socketClient->receiveFrom($this->socket, 10000, 0, $from, $port);

        if (!$this->rxMsg) {
            $this->resCode = 'No final response in ' . round($this->frTimer / 1000, 3) . ' seconds. ' .
                '(' . $this->socketClient->getLastError($this->socket) . ')';

            return;
        }

        if ($this->debug) {
            $temp = explode("\r" . PHP_EOL, $this->rxMsg);

            echo '<-- ' . $temp[0] . PHP_EOL;
        }

        $matches = [];
        if (preg_match('/^SIP\/2\.0 ([\d]{3})/', $this->rxMsg, $matches)) {
            $this->resCode = trim($matches[1]);
        }
    }

    private function formatRequest(): string
    {
        $request = $this->method . ' ' . $this->uri . ' SIP/2.0' . "\r" . PHP_EOL;

        $this->setVia();
        $request .= 'Via: ' . $this->via . "\r" . PHP_EOL;

        $request .= 'From: ' . $this->from . ';tag=' . random_int(10000, 99999) . "\r" . PHP_EOL;
        $request .= 'To: ' . $this->to . "\r" . PHP_EOL;

        if ($this->auth) {
            $request .= $this->auth . "\r" . PHP_EOL;
            $this->auth = null;
        }

        $request .= 'Call-ID: ' . md5(uniqid('', true)) . '@' . $this->srcIp . "\r" . PHP_EOL;
        $request .= 'CSeq: ' . $this->cSeq . ' ' . $this->method . "\r" . PHP_EOL;
        $request .= 'Contact: <sip:' . $this->fromUser . '@' . $this->srcIp . ':' . $this->srcPort . '>' . "\r" . PHP_EOL;
        $request .= 'Max-Forwards: 70' . "\r" . PHP_EOL;
        $request .= 'User-Agent: ' . $this->userAgent . "\r" . PHP_EOL;

        foreach ($this->extraHeaders as $header) {
            $request .= $header . "\r" . PHP_EOL;
        }

        $request .= 'Content-Length: ' . strlen($this->body) . "\r" . PHP_EOL;
        $request .= "\r" . PHP_EOL;
        $request .= $this->body;

        return $request;
    }

    private function setVia(): void
    {
        $this->via = 'SIP/2.0/UDP ' . $this->srcIp . ':' . $this->srcPort .
            ';rport;branch=z9hG4bK' . random_int(100000, 999999);
    }

    private function authWWW(): void
    {
        if (!$this->username) {
            throw new PhpSipException('Missing auth username');
        }

        if (!$this->password) {
            throw new PhpSipException('Missing auth password');
        }

        $matches = [];
        if (!preg_match('/^WWW-Authenticate: .* realm="(.*)"/imU', $this->rxMsg, $matches)) {
            throw new PhpSipException('Can\'t find realm in www-auth');
        }

        $realm = $matches[1];

        $matches = [];
        if (!preg_match('/^WWW-Authenticate: .* nonce="(.*)"/imU', $this->rxMsg, $matches)) {
            throw new PhpSipException('Can\'t find nonce in www-auth');
        }

        [$nonce, $response] = $this->calculateResponse($matches[1], $realm);

        $this->auth = 'Authorization: Digest username="' . $this->username . '", realm="' . $realm . '", nonce="' . $nonce . '", uri="' . $this->uri . '", response="' . $response . '", algorithm=MD5';
    }

    private function createSocket(): void
    {
        $this->getPort();

        if (!$this->srcIp) {
            throw new PhpSipException('Source IP not defined.');
        }

        if (!$this->socket = @$this->socketClient->create(AF_INET, SOCK_DGRAM, SOL_UDP)) {
            $errorCode = $this->socketClient->getLastError($this->socket);
            throw new PhpSipException ($this->socketClient->getErrorString($errorCode));
        }

        if (!@$this->socketClient->bind($this->socket, $this->srcIp, $this->srcPort)) {
            $errorCode = $this->socketClient->getLastError($this->socket);
            throw new PhpSipException ('Failed to bind ' . $this->srcIp . ':' . $this->srcPort . ' ' . $this->socketClient->getErrorString($errorCode));
        }

        $microseconds = $this->frTimer * 1000;
        $usec = $microseconds % 1000000;
        $sec = floor($microseconds / 1000000);

        if (!@$this->socketClient->setOption($this->socket, SOL_SOCKET, SO_RCVTIMEO,
            ['sec' => $sec, 'usec' => $usec])) {
            $errorCode = $this->socketClient->getLastError($this->socket);
            throw new PhpSipException ($this->socketClient->getErrorString($errorCode));
        }

        if (!@$this->socketClient->setOption($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0])) {
            $errorCode = $this->socketClient->getLastError($this->socket);
            throw new PhpSipException ($this->socketClient->getErrorString($errorCode));
        }
    }

    private function closeSocket(): void
    {
        $this->socketClient->close($this->socket);

        $this->releasePort();
    }

    private function calculateResponse(string $nonce, string $realm): array
    {
        $ha1 = md5($this->username . ':' . $realm . ':' . $this->password);
        $ha2 = md5($this->method . ':' . $this->uri);

        $response = md5($ha1 . ':' . $nonce . ':' . $ha2);

        return [$nonce, $response];
    }
}
