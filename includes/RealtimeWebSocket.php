<?php
namespace FreePBX\modules\Announcementtts;

// A bounded RFC 6455 client for server-side JSON events; no browser or additional runtime is needed.
class RealtimeWebSocket {
    private const MAX_MESSAGE_BYTES = 1048576;
    private $stream;
    private float $deadline;

    public function __construct($stream, float $deadline) {
        $this->stream = $stream;
        $this->deadline = $deadline;
    }

    public static function connect(string $key): self {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => 'api.openai.com', 'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $stream = @stream_socket_client('tls://api.openai.com:443', $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) { throw new \RuntimeException('OpenAI Realtime TLS connection failed. Check outbound HTTPS and the system CA certificates.'); }
        $client = new self($stream, microtime(true) + 60);
        try {
            $nonce = base64_encode(random_bytes(16));
            $client->write("GET /v1/realtime?model=" . RealtimeSpeech::MODEL . " HTTP/1.1\r\nHost: api.openai.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $nonce\r\nSec-WebSocket-Version: 13\r\nAuthorization: Bearer $key\r\n\r\n");
            $client->handshake($nonce);
            return $client;
        } catch (\Throwable $e) { $client->close(); throw $e; }
    }

    public function handshake(string $nonce): void {
        // Read only through the header terminator; the first frame may arrive in the same packet.
        $header = '';
        while (!str_ends_with($header, "\r\n\r\n")) {
            if (strlen($header) >= 16384) { throw new \RuntimeException('Invalid OpenAI Realtime handshake.'); }
            $header .= $this->read(1);
        }
        $lines = explode("\r\n", $header);
        preg_match('/^HTTP\/1\.[01] ([0-9]{3})\b/', array_shift($lines), $status);
        if (($status[1] ?? '') !== '101') {
            throw new \RuntimeException('OpenAI Realtime returned HTTP ' . ($status[1] ?? 'unknown') . '.');
        }
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower($name)] = trim($value); }
        }
        $expected = base64_encode(sha1($nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!hash_equals($expected, $headers['sec-websocket-accept'] ?? '')
            || strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || !in_array('upgrade', array_map('trim', explode(',', strtolower($headers['connection'] ?? ''))), true)) {
            throw new \RuntimeException('Invalid OpenAI Realtime handshake.');
        }
    }

    private function timeout(): void {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) { throw new \RuntimeException('OpenAI Realtime request timed out.'); }
        $seconds = (int) $remaining;
        stream_set_timeout($this->stream, $seconds, max(1, (int) (($remaining - $seconds) * 1000000)));
    }

    private function read(int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $this->timeout();
            $chunk = @fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') { throw new \RuntimeException('OpenAI Realtime connection closed or timed out before completion.'); }
            $data .= $chunk;
        }
        return $data;
    }

    private function write(string $data): void {
        $offset = 0;
        while ($offset < strlen($data)) {
            $this->timeout();
            $written = @fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) { throw new \RuntimeException('OpenAI Realtime connection write failed.'); }
            $offset += $written;
        }
    }

    private function frame(int $opcode, string $payload): void {
        $length = strlen($payload);
        if ($length > self::MAX_MESSAGE_BYTES) { throw new \RuntimeException('OpenAI Realtime message is too large.'); }
        $header = chr(0x80 | $opcode);
        $header .= $length < 126 ? chr(0x80 | $length) : ($length <= 65535 ? chr(0xfe) . pack('n', $length) : chr(0xff) . pack('NN', 0, $length));
        $mask = random_bytes(4);
        $masked = $payload ^ substr(str_repeat($mask, (int) ceil($length / 4)), 0, $length);
        $this->write($header . $mask . $masked);
    }

    public function send(array $event): void {
        $this->frame(1, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function receive(): array {
        $message = '';
        $fragmented = false;
        while (true) {
            $header = $this->read(2);
            $first = ord($header[0]); $second = ord($header[1]);
            $final = (bool) ($first & 0x80); $opcode = $first & 0x0f;
            if (($first & 0x70) || ($second & 0x80)) { throw new \RuntimeException('Invalid OpenAI Realtime frame.'); }
            $length = $second & 0x7f;
            if ($length === 126) { $length = unpack('n', $this->read(2))[1]; }
            elseif ($length === 127) {
                $parts = unpack('Nhigh/Nlow', $this->read(8));
                if ($parts['high'] !== 0) { throw new \RuntimeException('OpenAI Realtime frame is too large.'); }
                $length = $parts['low'];
            }
            if ($length > self::MAX_MESSAGE_BYTES || (($opcode & 8) && (!$final || $length > 125))) {
                throw new \RuntimeException('Invalid or oversized OpenAI Realtime frame.');
            }
            $payload = $length ? $this->read($length) : '';
            if ($opcode === 8) { throw new \RuntimeException('OpenAI Realtime closed before the recording completed.'); }
            if ($opcode === 9) { $this->frame(10, $payload); continue; }
            if ($opcode === 10) { continue; }
            if (($opcode !== 1 && $opcode !== 0) || ($opcode === 0) !== $fragmented) {
                throw new \RuntimeException('Invalid OpenAI Realtime message sequence.');
            }
            $message .= $payload;
            if (strlen($message) > self::MAX_MESSAGE_BYTES) { throw new \RuntimeException('OpenAI Realtime message is too large.'); }
            if (!$final) { $fragmented = true; continue; }
            try { $event = json_decode($message, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \RuntimeException('Invalid JSON from OpenAI Realtime.'); }
            if (!is_array($event) || !is_string($event['type'] ?? null)) { throw new \RuntimeException('Invalid OpenAI Realtime event.'); }
            return $event;
        }
    }

    public function close(): void {
        if (is_resource($this->stream)) { fclose($this->stream); }
    }
}
