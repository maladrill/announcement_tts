<?php
// Provider fixtures exercise Realtime event handling; socket pairs exercise the actual WebSocket codec.
use FreePBX\modules\Announcementtts\RealtimeSpeech;
use FreePBX\modules\Announcementtts\RealtimeWebSocket;
use FreePBX\modules\Announcementtts\TtsGenerator;

class RealtimeFixture {
    public array $sent = [];
    public bool $closed = false;
    public function __construct(private array $events) {}
    public function send(array $event): void { $this->sent[] = $event; }
    public function receive(): array {
        if (!$this->events) { throw new RuntimeException('Fixture connection ended.'); }
        return array_shift($this->events);
    }
    public function close(): void { $this->closed = true; }
}
$pcm = str_repeat("\x01\0", 2400);
$identity = ['response_id' => 'resp_test', 'output_index' => 0, 'content_index' => 0];
$events = [
    ['type' => 'session.created'],
    ['type' => 'response.created', 'response' => ['id' => 'resp_test']],
    ['type' => 'response.output_audio.delta', 'delta' => base64_encode(substr($pcm, 0, 2000))] + $identity,
    ['type' => 'response.output_audio.delta', 'delta' => base64_encode(substr($pcm, 2000))] + $identity,
    ['type' => 'response.output_audio.done'] + $identity,
    ['type' => 'response.done', 'response' => ['id' => 'resp_test', 'status' => 'completed']],
];
$realtimeOutput = $root . '/realtime-test.wav';
$fixture = new RealtimeFixture($events);
$client = new RealtimeSpeech(static fn($key) => $fixture);
$client->request('offline-marker', 'Dzień dobry. Proszę czekać.', 'cedar', $realtimeOutput);
check($fixture->closed && count($fixture->sent) === 1, 'Realtime closes the socket after one completed response');
$request = $fixture->sent[0];
check($request['type'] === 'response.create' && $request['response']['audio']['output']['voice'] === 'cedar'
    && $request['response']['audio']['output']['format'] === ['type' => 'audio/pcm', 'rate' => 24000], 'Realtime requests the selected voice and PCM16 audio using the GA event schema');
check($request['response']['input'][0]['content'][0]['text'] === 'Dzień dobry. Proszę czekać.'
    && $request['response']['output_modalities'] === ['audio'] && $request['response']['conversation'] === 'none', 'Realtime sends the exact source text in an isolated audio-only response');
check(substr(file_get_contents($realtimeOutput), 44) === $pcm && filesize($realtimeOutput) === 44 + strlen($pcm), 'Realtime assembles ordered PCM chunks into a correctly sized WAV');
$header = unpack('Vsize', substr(file_get_contents($realtimeOutput), 4, 4));
check($header['size'] === 36 + strlen($pcm), 'Streamed WAV has a finalized RIFF length');

$cases = [];
$cases['provider error'] = [['type' => 'error', 'error' => ['message' => 'secret-provider-body']]];
$cases['disconnect'] = array_slice($events, 0, 3);
$bad = $events; $bad[2]['delta'] = '!!!'; $cases['invalid base64'] = $bad;
$bad = $events; $bad[2]['response_id'] = 'other'; $cases['mismatched response'] = $bad;
$bad = $events; $bad[2]['output_index'] = 1; $cases['multiple output items'] = $bad;
$bad = $events; $bad[2]['delta'] = base64_encode('a'); unset($bad[3]); $cases['odd PCM length'] = array_values($bad);
$bad = $events; unset($bad[2], $bad[3]); $cases['empty audio'] = array_values($bad);
$bad = $events; unset($bad[4]); $cases['missing audio completion'] = array_values($bad);
foreach (['failed', 'cancelled', 'incomplete'] as $status) {
    $bad = $events; $bad[5]['response']['status'] = $status; $cases[$status] = $bad;
}
$bad = $events; $bad[5]['response']['id'] = 'other'; $cases['wrong final response'] = $bad;
$bad = $events; [$bad[3], $bad[4]] = [$bad[4], $bad[3]]; $cases['audio after completion'] = $bad;
$bad = $events; unset($bad[1]); $cases['missing response creation'] = array_values($bad);
foreach ($cases as $name => $invalidEvents) {
    $mock = new RealtimeFixture($invalidEvents);
    $client = new RealtimeSpeech(static fn($key) => $mock);
    try { $client->request('offline-marker', 'Test', 'marin', $realtimeOutput); throw new LogicException('Expected protocol failure'); }
    catch (RuntimeException $e) { check(!str_contains($e->getMessage(), 'secret-provider-body') && $mock->closed, 'Realtime rejects ' . $name . ', closes the connection and hides provider text'); }
}

$redactionClient = new RealtimeSpeech(static fn($key) => new RealtimeFixture([['type' => 'error']]));
try { $redactionClient->request('key-redaction-test-marker', 'Test', 'marin', $realtimeOutput); }
catch (RuntimeException $e) {
    check(!str_contains($e->getTraceAsString(), 'key-redaction-test-marker'), 'Realtime errors redact credentials from PHP exception traces');
}
foreach ([[RealtimeSpeech::class, 'request', 'key'], [RealtimeWebSocket::class, 'connect', 'key'], [RealtimeWebSocket::class, 'write', 'data']] as [$class, $method, $parameter]) {
    $parameters = (new ReflectionMethod($class, $method))->getParameters();
    $sensitive = array_filter($parameters, static fn($p) => $p->getName() === $parameter && count($p->getAttributes(SensitiveParameter::class)) === 1);
    check(count($sensitive) === 1, 'Credential-bearing parameters are marked sensitive: ' . $method);
}

class OfflineRealtimeGenerator extends TtsGenerator {
    public function __construct(private array $events) { parent::__construct(); }
    protected function requestRealtime(string $text, string $voice, string $output): void {
        (new RealtimeSpeech(fn($key) => new RealtimeFixture($this->events)))->request('offline-marker', $text, $voice, $output);
    }
    protected function request(string $text, string $voice, string $output): void { throw new RuntimeException('Wrong engine selected.'); }
}
$name = (new OfflineRealtimeGenerator($events))->generate('Test', 'marin', $root, 'realtime');
TtsGenerator::assertWav($root . '/' . $name);
check((fileperms($root . '/' . $name) & 0777) === 0640, 'Realtime generation uses real FFmpeg and publishes verified 8 kHz mono audio with mode 0640');
$filesBefore = glob($root . '/announcementtts_*.wav');
fails(static fn() => (new OfflineRealtimeGenerator($cases['incomplete']))->generate('Test', 'cedar', $root, 'realtime'), 'Incomplete Realtime output cannot be published');
check($filesBefore === glob($root . '/announcementtts_*.wav') && glob($root . '/.announcementtts-input-*') === []
    && glob($root . '/.announcementtts-output-*') === [], 'Realtime failure removes partial temporary audio and publishes no replacement');

function serverFrame(string $payload, int $opcode = 1, bool $final = true): string {
    $length = strlen($payload);
    return chr(($final ? 0x80 : 0) | $opcode) . ($length < 126 ? chr($length) : ($length <= 65535 ? chr(126) . pack('n', $length) : chr(127) . pack('NN', 0, $length))) . $payload;
}
function fixtureSocket(string $wire, ?float $deadline = null): array {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($pair[1], $wire);
    return [new RealtimeWebSocket($pair[0], $deadline ?? microtime(true) + 2), $pair[1]];
}
$nonce = base64_encode(str_repeat('n', 16));
$accept = base64_encode(sha1($nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
$header = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: keep-alive, Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
[$socket, $peer] = fixtureSocket($header . serverFrame('{"type":"session.created"}'));
$socket->handshake($nonce);
check($socket->receive()['type'] === 'session.created', 'WebSocket validates the upgrade without consuming the first coalesced event frame');
$socket->close(); fclose($peer);
foreach ([str_replace($accept, 'invalid', $header), "HTTP/1.1 401 Unauthorized\r\n\r\n", str_replace('Upgrade: websocket', 'Upgrade: other', $header)] as $invalidHeader) {
    [$socket, $peer] = fixtureSocket($invalidHeader);
    fails(static fn() => $socket->handshake($nonce), 'WebSocket rejects an invalid or unauthorized handshake');
    $socket->close(); fclose($peer);
}
[$socket, $peer] = fixtureSocket(serverFrame('{"type":', 1, false) . serverFrame('ping', 9) . serverFrame('"session.created"}', 0));
check($socket->receive()['type'] === 'session.created', 'WebSocket reassembles fragmented JSON with interleaved ping frames');
$socket->close();
$pong = stream_get_contents($peer); fclose($peer);
check(ord($pong[0]) === 0x8a && (ord($pong[1]) & 0x80) !== 0 && (substr($pong, 6) ^ substr($pong, 2, 4)) === 'ping', 'Client responds to ping with a masked pong frame');
foreach ([10, 200, 66000] as $length) {
    [$socket, $peer] = fixtureSocket(serverFrame('{"type":"unused"}'));
    $payload = ['type' => 'test', 'text' => str_repeat('x', $length)];
    $socket->send($payload); $socket->close();
    $wire = stream_get_contents($peer); fclose($peer);
    $code = ord($wire[1]) & 0x7f; $offset = $code < 126 ? 2 : ($code === 126 ? 4 : 10);
    $mask = substr($wire, $offset, 4); $encoded = substr($wire, $offset + 4);
    $decoded = $encoded ^ substr(str_repeat($mask, (int) ceil(strlen($encoded) / 4)), 0, strlen($encoded));
    check(ord($wire[0]) === 0x81 && (ord($wire[1]) & 0x80) && json_decode($decoded, true) === $payload,
        'Client masks outbound JSON with the correct length encoding: ' . $length);
}
$invalidFrames = [
    serverFrame('{}', 0), serverFrame('{}', 2), serverFrame('{}', 1, false) . serverFrame('{}', 1),
    serverFrame('ping', 9, false), serverFrame('', 8), "\xC1\x02{}", "\x81\x82", "\x81\x7f" . pack('NN', 0, 1048577),
    serverFrame('{invalid'), serverFrame('[]'), serverFrame('{"type":1}'),
];
foreach ($invalidFrames as $wire) {
    [$socket, $peer] = fixtureSocket($wire);
    fails(static fn() => $socket->receive(), 'WebSocket rejects malformed, oversized or prematurely closed frames');
    $socket->close(); fclose($peer);
}
[$socket, $peer] = fixtureSocket(serverFrame('{"type":"late"}'), microtime(true) - 1);
fails(static fn() => $socket->receive(), 'An absolute deadline bounds the entire Realtime request');
$socket->close(); fclose($peer);
