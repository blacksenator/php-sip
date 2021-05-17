<?php

namespace level7systems;

use level7systems\Exceptions\PhpSipException;

class PhpSip
{
    private bool $debug = false;
    private int $minPort = 5065;
    private int $maxPort = 5265;
    private int $frTimer = 10000;
    private ?string $lockFile = '/tmp/PhpSIP.lock';
    private bool $persistentLockFile = true;
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
    private bool $serverMode = false;
    private bool|string $dialog = false;
    private $socket;
    private ?string $srcIp;
    private string $userAgent = 'PHP SIP';
    private int $cSeq = 20;
    private string $srcPort;
    private ?string $callId;
    private string $contact;
    private string $uri;
    private string $host;
    private int $port = 5060;
    private string $proxy;
    private string $method;
    private string $username;
    private string $password;
    private ?string $to;
    private ?string $toTag;
    private ?string $from;
    private string $fromUser;
    private ?string $fromTag;
    private string $via;
    private ?string $contentType;
    private ?string $body;
    private ?string $rxMsg;
    private ?string $resCode;
    private ?string $resContact;
    private ?string $resCseqMethod;
    private ?string $reqMethod;
    private array $reqVia;
    private ?string $reqCseqMethod;
    private ?string $reqCseqNumber;
    private ?string $reqFrom;
    private ?string $reqFromTag;
    private ?string $reqTo;
    private ?string $reqToTag;
    private ?string $auth;
    private array $routes = [];
    private array $recordRoute = [];
    private array $extraHeaders = [];

    public function __construct(?string $srcIp = null, ?string $srcPort = null, ?int $frTimer = null)
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

        if ($srcPort) {
            if (!preg_match('/^[\d]+$/', $srcPort)) {
                throw new PhpSipException('Invalid src_port ' . $srcPort);
            }

            $this->srcPort = $srcPort;
            $this->lockFile = null;
        }

        if ($frTimer) {
            if (!preg_match('/^[\d]+$/', $frTimer)) {
                throw new PhpSipException('Invalid fr_timer ' . $frTimer);
            }

            $this->frTimer = $frTimer;
        }

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

    public function getSrcIp(): string
    {
        return $this->srcIp;
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

    public function setTo(string $to): void
    {
        $this->to = '<' . $to . '>';

        if (preg_match('/<.*>$/', $to)) {
            $this->to = $to;
        }
    }

    public function setMethod(string $method): void
    {
        if (!in_array($method, $this->allowedMethods, true)) {
            throw new PhpSipException('Invalid method.');
        }

        $this->method = $method;

        if ($method === 'INVITE') {
            $this->body = implode("\r" . PHP_EOL, [
                'v=0',
                'o=click2dial 0 0 IN IP4 ' . $this->srcIp,
                's=click2dial call',
                'c=IN IP4 ' . $this->srcIp,
                't=0 0',
                'm=audio 8000 RTP/AVP 0 8 18 3 4 97 98',
                'a=rtpmap:0 PCMU/8000',
                'a=rtpmap:18 G729/8000',
                'a=rtpmap:97 ilbc/8000',
                'a=rtpmap:98 speex/8000'
            ]);

            $this->setContentType();
        }

        if ($method === 'REFER') {
            $this->setBody('');
        }

        if ($method === 'CANCEL') {
            $this->setBody('');
            $this->setContentType();
        }

        if ($method === 'MESSAGE' && !$this->contentType) {
            $this->setContentType();
        }
    }

    public function setProxy(string $proxy): void
    {
        $this->host = $this->proxy = $proxy;

        if (strpos($this->proxy, ':')) {
            $temp = explode(':', $this->proxy);

            if (!preg_match('/^[\d]+$/', $temp[1])) {
                throw new PhpSipException('Invalid port number ' . $temp[1]);
            }

            $this->host = $temp[0];
            $this->port = $temp[1];
        }
    }

    public function setContact(string $value): void
    {
        $this->contact = $value;
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

        if ($this->proxy) {
            $this->host = $this->proxy;

            if (str_contains($this->proxy, ':')) {
                $temp = explode(':', $this->proxy);

                $this->host = $temp[0];
                $this->port = $temp[1];
            }

            return;
        }

        $uri = ($t_pos = strpos($uri, ';')) ? substr($uri, 0, $t_pos) : $uri;

        $url = str_replace('sip:', 'sip://', $uri);

        if (!$url = @parse_url($url)) {
            throw new PhpSipException('Failed to parse URI "' . $url . '".');
        }

        $this->host = $url['host'];

        if (isset($url['port'])) {
            $this->port = $url['port'];
        }
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

        if ($this->method === 'CANCEL' && $this->resCode === '200') {
            $i = 0;
            while (!str_starts_with($this->resCode, '4') && $i < 2) {
                $this->readMessage();
                $i++;
            }
        }

        if ($this->resCode === '407') {
            $this->cSeq++;

            $this->auth();

            $data = $this->formatRequest();

            $this->sendData($data);

            $this->readMessage();
        }

        if ($this->resCode === '401') {
            $this->cSeq++;

            $this->authWWW();

            $data = $this->formatRequest();

            $this->sendData($data);

            $this->readMessage();
        }

        if (str_starts_with($this->resCode, '1')) {
            $i = 0;
            while (str_starts_with($this->resCode, '1') && $i < 4) {
                $this->readMessage();
                $i++;
            }
        }

        $this->extraHeaders = [];
        $this->cSeq++;

        return $this->resCode;
    }

    public function listen($methods): void
    {
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        if ($this->debug) {
            echo 'Listening for ' . implode(', ', $methods) . PHP_EOL;
        }

        if ($this->serverMode) {
            while (!in_array($this->reqMethod, $methods)) {
                $this->readMessage();

                if ($this->rxMsg && !in_array($this->reqMethod, $methods)) {
                    $this->reply(200, 'OK');
                }
            }

            return;
        }

        $i = 0;
        $this->reqMethod = null;

        while (!in_array($this->reqMethod, $methods)) {
            $this->readMessage();

            $i++;

            if ($i > 5) {
                throw new PhpSipException('Unexpected request ' . $this->reqMethod . ' received.');
            }
        }
    }

    public function setServerMode(string $value): void
    {
        if (!@socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 0])) {
            $errorNumber = socket_last_error($this->socket);
            throw new PhpSipException (socket_strerror($errorNumber));
        }

        $this->serverMode = $value;
    }

    public function reply(int $code, string $text): void
    {
        $reply = 'SIP/2.0 ' . $code . ' ' . $text . "\r" . PHP_EOL;

        foreach ($this->reqVia as $via) {
            $reply .= 'Via: ' . $via . "\r" . PHP_EOL;
        }

        foreach ($this->recordRoute as $recordRoute) {
            $reply .= 'Record-Route: ' . $recordRoute . "\r" . PHP_EOL;
        }

        $reply .= implode("\r" . PHP_EOL, [
                'From: ' . $this->reqFrom . ';tag=' . $this->reqFromTag,
                'To: ' . $this->reqTo . ';tag=' . $this->reqToTag,
                'Call-ID: ' . $this->callId,
                'CSeq: ' . $this->reqCseqNumber . ' ' . $this->reqCseqMethod,
                'Max-Forwards: 70',
                'User-Agent: ' . $this->userAgent,
                'Content-Length: 0'
            ]) . "\r" . PHP_EOL;

        $this->sendData($reply);
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function setContentType(?string $contentType = null): void
    {
        $this->contentType = match ($this->method) {
            'INVITE' => 'application/sdp',
            'MESSAGE' => 'text/html; charset=utf-8',
            default => null,
        };

        if ($contentType !== null) {
            $this->contentType = $contentType;
        }
    }

    public function setFromTag(string $value): void
    {
        $this->fromTag = $value;
    }

    public function setToTag(string $value): void
    {
        $this->toTag = $value;
    }

    public function setCSeq(string $value): void
    {
        $this->cSeq = $value;
    }

    public function setCallId(?string $value = null): void
    {
        $this->callId = md5(uniqid('', true)) . '@' . $this->srcIp;

        if ($value) {
            $this->callId = $value;
        }
    }

    public function getHeader(string $name): bool|string
    {
        $matches = [];

        if (preg_match('/^' . $name . ': (.*)$/im', $this->rxMsg, $matches)) {
            return trim($matches[1]);
        }

        return false;
    }

    public function getBody(): string
    {
        $temp = explode("\r" . PHP_EOL . "\r" . PHP_EOL, $this->rxMsg);

        return $temp[1] ?? '';
    }

    public function newCall(): void
    {
        $this->cSeq = 20;
        $this->callId = null;
        $this->to = null;
        $this->toTag = null;
        $this->from = null;
        $this->fromTag = null;

        $this->body = null;

        $this->rxMsg = null;
        $this->resCode = null;
        $this->resContact = null;
        $this->resCseqMethod = null;

        $this->reqVia = [];
        $this->reqMethod = null;
        $this->reqCseqMethod = null;
        $this->reqCseqNumber = null;
        $this->reqFrom = null;
        $this->reqFromTag = null;
        $this->reqTo = null;
        $this->reqToTag = null;

        $this->routes = [];
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

        if (!$this->persistentLockFile && empty($ports)) {
            if (!fclose($filePointer)) {
                throw new PhpSipException('Failed to close lock_file');
            }

            if (!unlink($this->lockFile)) {
                throw new PhpSipException('Failed to delete lock_file.');
            }

            return;
        }

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
        if (!$this->host) {
            throw new PhpSipException('Can\'t send data, host undefined');
        }

        if (!$this->port) {
            throw new PhpSipException('Can\'t send data, host undefined');
        }

        if (!$data) {
            throw new PhpSipException('Can\'t send - empty data');
        }

        if (preg_match('/^[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}$/', $this->host)) {
            $ipAddress = $this->host;
        } else {
            $ipAddress = gethostbyname($this->host);

            if ($ipAddress === $this->host) {
                throw new PhpSipException('DNS resolution of ' . $this->host . ' failed');
            }
        }

        if (!@socket_sendto($this->socket, $data, strlen($data), 0, $ipAddress, $this->port)) {
            $errorNumber = socket_last_error($this->socket);
            throw new PhpSipException('Failed to send data to ' . $ipAddress . ':' . $this->port .
                '. Source IP ' . $this->srcIp . ', source port: ' . $this->srcPort . '. ' .
                socket_strerror($errorNumber));
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
        $this->rxMsg = null;

        if (!@socket_recvfrom($this->socket, $this->rxMsg, 10000, 0, $from, $port)) {
            $this->resCode = 'No final response in ' . round($this->frTimer / 1000, 3) . ' seconds. ' .
                '(' . socket_last_error($this->socket) . ')';

            return;
        }

        if ($this->debug) {
            $temp = explode("\r" . PHP_EOL, $this->rxMsg);

            echo '<-- ' . $temp[0] . PHP_EOL;
        }

        $matches = [];
        if (preg_match('/^SIP\/2\.0 ([\d]{3})/', $this->rxMsg, $matches)) {
            $this->resCode = trim($matches[1]);

            $this->parseResponse();
        } else {
            $this->parseRequest();
        }

        if (in_array($this->resCode[0], ['1', '2']) && $this->fromTag && $this->toTag && $this->callId) {
            if ($this->debug && !$this->dialog) {
                echo '  New dialog: ' . $this->fromTag . '.' . $this->toTag . '.' . $this->callId . PHP_EOL;
            }

            $this->dialog = $this->fromTag . '.' . $this->toTag . '.' . $this->callId;
        }
    }

    private function parseResponse(): void
    {
        $matches = [];
        $this->reqVia = [];

        if (preg_match_all('/^Via: (.*)$/im', $this->rxMsg, $matches)) {
            foreach ($matches[1] as $via) {
                $this->reqVia[] = trim($via);
            }
        }

        $this->parseRecordRoute();

        $matches = [];
        if (preg_match('/^To: .*;tag=(.*)$/im', $this->rxMsg, $matches)) {
            $this->toTag = trim($matches[1]);
        }

        $this->resContact = $this->parseContact();

        $this->resCseqMethod = $this->parseCSeqMethod();

        if ($this->resCseqMethod === 'INVITE' && in_array($this->resCode[0], ['2', '3', '4', '5', '6'])) {
            $this->ack();
        }
    }

    private function parseRequest(): void
    {
        $temp = explode("\r" . PHP_EOL, $this->rxMsg);
        $temp = explode(' ', $temp[0]);

        $this->reqMethod = trim($temp[0]);

        $this->parseRecordRoute();

        $matches = [];
        $this->reqVia = [];
        if (preg_match_all('/^Via: (.*)$/im', $this->rxMsg, $matches)) {
            if ($this->serverMode) {
                $matches2 = [];
                if (preg_match('/[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}/', $matches[1][0], $matches2)) {
                    $this->host = $matches2[0];
                }
            }

            foreach ($matches[1] as $via) {
                $this->reqVia[] = trim($via);
            }
        }

        $this->reqCseqMethod = $this->parseCSeqMethod();

        $matches = [];

        if (preg_match('/^CSeq: ([\d]+)/im', $this->rxMsg, $matches)) {
            $this->reqCseqNumber = trim($matches[1]);
        }

        $matches = [];
        if (preg_match('/^From: (.*)/im', $this->rxMsg, $matches)) {
            $this->reqFrom = ($pos = strpos($matches[1], ';'))
                ? substr($matches[1], 0, $pos)
                : $matches[1];
        }

        $matches = [];
        if (preg_match('/^From:.*;tag=(.*)$/im', $this->rxMsg, $matches)) {
            $this->reqFromTag = trim($matches[1]);
        }

        $matches = [];
        if (preg_match('/^To: (.*)/im', $this->rxMsg, $matches)) {
            $this->reqTo = ($pos = strpos($matches[1], ';'))
                ? substr($matches[1], 0, $pos)
                : $matches[1];
        }

        $matches = [];
        $this->reqToTag = random_int(10000, 99999);
        if (preg_match('/^To:.*;tag=(.*)$/im', $this->rxMsg, $matches)) {
            $this->reqToTag = trim($matches[1]);
        }

        if (!$this->callId) {
            $matches = [];
            if (preg_match('/^Call-ID:(.*)$/im', $this->rxMsg, $matches)) {
                $this->callId = trim($matches[1]);
            }
        }
    }

    private function ack(): void
    {
        $acknowledge = 'ACK ' . $this->uri . ' SIP/2.0' . "\r" . PHP_EOL;
        if ($this->resCseqMethod === 'INVITE' && $this->resCode === '200') {
            $acknowledge = 'ACK ' . $this->resContact . ' SIP/2.0' . "\r" . PHP_EOL;
        }

        $acknowledge .= 'Via: ' . $this->via . "\r" . PHP_EOL;

        if ($this->routes) {
            $acknowledge .= 'Route: ' . implode(',', array_reverse($this->routes)) . "\r" . PHP_EOL;
        }

        if (!$this->fromTag) {
            $this->fromTag = random_int(10000, 99999);
        }

        $acknowledge .= 'From: ' . $this->from . ';tag=' . $this->fromTag . "\r" . PHP_EOL;

        $acknowledge .= 'To: ' . $this->to;
        if ($this->toTag) {
            $acknowledge .= ';tag=' . $this->toTag;
        }
        $acknowledge .= "\r" . PHP_EOL;

        if (!$this->callId) {
            $this->setCallId();
        }

        $proxyAuthorization = null;
        if ($this->resCode === '200' && $this->auth) {
            $proxyAuthorization = 'Proxy-Authorization: ' . $this->auth . "\r" . PHP_EOL;
        }

        $acknowledge .= implode("\r" . PHP_EOL, array_filter([
                'Call-ID: ' . $this->callId,
                'CSeq: ' . $this->cSeq . ' ACK',
                $proxyAuthorization,
                'Max-Forwards: 70',
                'User-Agent: ' . $this->userAgent,
                'Content-Length: 0',
            ])) . "\r" . PHP_EOL;

        $this->sendData($acknowledge);
    }

    private function formatRequest(): string
    {
        $request = $this->method . ' ' . $this->uri . ' SIP/2.0' . "\r" . PHP_EOL;
        if ($this->resContact && in_array($this->method, ['BYE', 'REFER', 'SUBSCRIBE'])) {
            $request = $this->method . ' ' . $this->resContact . ' SIP/2.0' . "\r" . PHP_EOL;
        }

        if ($this->method !== 'CANCEL') {
            $this->setVia();
        }

        $request .= 'Via: ' . $this->via . "\r" . PHP_EOL;

        if ($this->method !== 'CANCEL' && $this->routes) {
            $request .= 'Route: ' . implode(',', array_reverse($this->routes)) . "\r" . PHP_EOL;
        }

        if (!$this->fromTag) {
            $this->fromTag = random_int(10000, 99999);
        }

        $request .= 'From: ' . $this->from . ';tag=' . $this->fromTag . "\r" . PHP_EOL;

        $request .= 'To: ' . $this->to;
        if ($this->toTag && !in_array($this->method, ['INVITE', 'CANCEL', 'NOTIFY', 'REGISTER'])) {
            $request .= ';tag=' . $this->toTag;
        }
        $request .= "\r" . PHP_EOL;

        if ($this->auth) {
            $request .= $this->auth . "\r" . PHP_EOL;
            $this->auth = null;
        }

        if (!$this->callId) {
            $this->setCallId();
        }

        $request .= 'Call-ID: ' . $this->callId . "\r" . PHP_EOL;

        if ($this->method === 'CANCEL') {
            $this->cSeq--;
        }

        $request .= 'CSeq: ' . $this->cSeq . ' ' . $this->method . "\r" . PHP_EOL;

        if ($this->contact) {
            $contact = $this->contact;
            if (!str_starts_with($this->contact, '<')) {
                $contact = '<' . $this->contact . '>';
            }

            $request .= 'Contact: ' . $contact . "\r" . PHP_EOL;
        } elseif ($this->method !== 'MESSAGE') {
            $request .= 'Contact: <sip:' . $this->fromUser . '@' . $this->srcIp . ':' . $this->srcPort . '>' . "\r" . PHP_EOL;
        }

        if ($this->contentType) {
            $request .= 'Content-Type: ' . $this->contentType . "\r" . PHP_EOL;
        }

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

    private function auth(): void
    {
        if (!$this->username) {
            throw new PhpSipException('Missing username');
        }

        if (!$this->password) {
            throw new PhpSipException('Missing password');
        }

        $matches = [];
        if (!preg_match('/^Proxy-Authenticate: .* realm="(.*)"/imU', $this->rxMsg, $matches)) {
            throw new PhpSipException('Can\'t find realm in proxy-auth');
        }

        $realm = $matches[1];

        $matches = [];
        if (!preg_match('/^Proxy-Authenticate: .* nonce="(.*)"/imU', $this->rxMsg, $matches)) {
            throw new PhpSipException('Can\'t find nonce in proxy-auth');
        }

        [$nonce, $response] = $this->calculateResponse($matches[1], $realm);

        $this->auth = 'Proxy-Authorization: Digest username="' . $this->username . '", realm="' . $realm . '", nonce="' . $nonce . '", uri="' . $this->uri . '", response="' . $response . '", algorithm=MD5';
    }

    private function authWWW(): void
    {
        if (!$this->username) {
            throw new PhpSipException('Missing auth username');
        }

        if (!$this->password) {
            throw new PhpSipException('Missing auth password');
        }

        $qopPresent = false;
        if (str_contains($this->rxMsg, 'qop=')) {
            $qopPresent = true;

            if (!str_contains($this->rxMsg, 'qop="auth"')) {
                throw new PhpSipException('Only qop="auth" digest authentication supported.');
            }
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

        $cnonce = $qopPresent ? md5(time()) : null;

        [$nonce, $response] = $this->calculateResponse($matches[1], $realm, $cnonce);

        $this->auth = 'Authorization: Digest username="' . $this->username . '", realm="' . $realm . '", nonce="' . $nonce . '", uri="' . $this->uri . '", response="' . $response . '", algorithm=MD5';

        if ($qopPresent) {
            $this->auth .= ', qop="auth", nc="00000001", cnonce="' . $cnonce . '"';
        }
    }

    private function createSocket(): void
    {
        $this->getPort();

        if (!$this->srcIp) {
            throw new PhpSipException('Source IP not defined.');
        }

        if (!$this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)) {
            $err_no = socket_last_error($this->socket);
            throw new PhpSipException (socket_strerror($err_no));
        }

        if (!@socket_bind($this->socket, $this->srcIp, $this->srcPort)) {
            $err_no = socket_last_error($this->socket);
            throw new PhpSipException ('Failed to bind ' . $this->srcIp . ':' . $this->srcPort . ' ' . socket_strerror($err_no));
        }

        $microseconds = $this->frTimer * 1000;

        $usec = $microseconds % 1000000;

        $sec = floor($microseconds / 1000000);

        if (!@socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $sec, 'usec' => $usec])) {
            $err_no = socket_last_error($this->socket);
            throw new PhpSipException (socket_strerror($err_no));
        }

        if (!@socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0])) {
            $err_no = socket_last_error($this->socket);
            throw new PhpSipException (socket_strerror($err_no));
        }
    }

    private function closeSocket(): void
    {
        socket_close($this->socket);

        $this->releasePort();
    }

    private function parseRecordRoute(): void
    {
        $this->recordRoute = [];

        $matches = [];

        if (preg_match_all('/^Record-Route: (.*)$/im', $this->rxMsg, $matches)) {
            foreach ($matches[1] as $route_header) {
                $this->recordRoute[] = $route_header;

                foreach (explode(',', $route_header) as $route) {
                    if (!in_array(trim($route), $this->routes)) {
                        $this->routes[] = trim($route);
                    }
                }
            }
        }
    }

    private function parseContact(): ?string
    {
        $output = null;

        $matches = [];

        if (preg_match('/^Contact:.*<(.*)>/im', $this->rx_msg, $matches)) {
            $output = trim($matches[1]);

            $semicolon = strpos($output, ";");

            if ($semicolon !== false) {
                $output = substr($output, 0, $semicolon);
            }
        }

        return $output;
    }

    private function parseCSeqMethod(): ?string
    {
        $output = null;

        $matches = [];

        if (preg_match('/^CSeq: [\d]+ (.*)$/im', $this->rxMsg, $matches)) {
            $output = trim($matches[1]);
        }

        return $output;
    }

    private function calculateResponse(string $nonce, string $realm, ?string $cnonce = null): array
    {
        $ha1 = md5($this->username . ':' . $realm . ':' . $this->password);
        $ha2 = md5($this->method . ':' . $this->uri);

        $response = md5($ha1 . ':' . $nonce . ':' . $ha2);

        if ($cnonce) {
            $response = md5($ha1 . ':' . $nonce . ':00000001:' . $cnonce . ':auth:' . $ha2);
        }

        return [$nonce, $response];
    }
}
