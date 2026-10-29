<?php

namespace App\Voice\FreeSwitch;

use RuntimeException;

/** Minimal FreeSWITCH event socket client: authenticate, run API commands. */
class EventSocket
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
        private readonly float $timeout = 5.0,
    ) {
    }

    public function api(string $command): string
    {
        if (preg_match('/[\r\n]/', $command)) {
            throw new RuntimeException('Invalid event socket command.');
        }

        $this->connect();
        $this->write("api {$command}\n\n");
        [$headers, $body] = $this->read();

        if (($headers['Content-Type'] ?? '') !== 'api/response') {
            throw new RuntimeException('Unexpected event socket reply.');
        }

        return $body;
    }

    public function close(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, "exit\n\n");
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(): void
    {
        if ($this->socket) {
            return;
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("Cannot reach the voice server event socket: {$error}");
        }
        stream_set_timeout($socket, (int) ceil($this->timeout));
        $this->socket = $socket;

        try {
            [$headers] = $this->read();
        } catch (RuntimeException) {
            throw new RuntimeException("Something other than FreeSWITCH is on {$this->host}:{$this->port}. Check VOICE_ESL_PORT.");
        }
        if (($headers['Content-Type'] ?? '') !== 'auth/request') {
            throw new RuntimeException('The voice server did not ask for authentication.');
        }

        $this->write("auth {$this->password}\n\n");
        [$headers] = $this->read();
        if (! str_starts_with($headers['Reply-Text'] ?? '', '+OK')) {
            $this->close();

            throw new RuntimeException('The voice server rejected the event socket password.');
        }
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Lost the voice server event socket.');
        }
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function read(): array
    {
        $headers = [];
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) {
                throw new RuntimeException('Lost the voice server event socket.');
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($headers === []) {
                    continue;
                }
                break;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[$name] = trim($value);
        }

        $body = '';
        $length = (int) ($headers['Content-Length'] ?? 0);
        while (strlen($body) < $length) {
            $chunk = fread($this->socket, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Lost the voice server event socket.');
            }
            $body .= $chunk;
        }

        return [$headers, $body];
    }
}
