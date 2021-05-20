<?php

namespace EasybellLibs\Sockets;

use Socket;

class SocketProxy
{
    public function create(int $domain, int $type, int $protocol): Socket|false
    {
        return socket_create($domain, $type, $protocol);
    }

    public function setOption(Socket $socket, int $level, int $option, $value): bool
    {
        return socket_set_option($socket, $level, $option, $value);
    }

    public function bind(Socket $socket, string $address, int $port = 0): bool
    {
        return socket_bind($socket, $address, $port);
    }

    public function sendTo(Socket $socket, string $data, int $length, int $flags, string $address, ?int $port = 0): int|false
    {
        return socket_sendto($socket, $data, $length, $flags, $address, $port);
    }

    public function receiveFrom(Socket $socket, int $length, int $flags, &$address, &$port = null): ?string
    {
        $data = null;
        if (!@socket_recvfrom($socket, $data, $length, $flags, $address, $port)) {
            return null;
        }

        return $data;
    }

    public function getErrorString(int $errorCode): string
    {
        return socket_strerror($errorCode);
    }

    public function getLastError(?Socket $socket = null): int
    {
        return socket_last_error($socket);
    }

    public function close(Socket $socket): void
    {
        socket_close($socket);
    }
}
