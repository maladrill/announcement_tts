<?php
namespace FreePBX\modules\Announcementtts;
require_once __DIR__ . "/RealtimeSpeech.php";

class TtsGenerator {
    public const VOICES = ['alloy', 'ash', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer'];
    public const REALTIME_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];
    public const ENGINES = ['tts-1' => 'tts-1', 'realtime' => 'Realtime API'];

    public static function voices(string $backend): array {
        return match ($backend) {
            'tts-1' => self::VOICES,
            'realtime' => self::REALTIME_VOICES,
            default => throw new \InvalidArgumentException('Unsupported TTS engine.'),
        };
    }
    public static function defaultVoice(string $backend): string {
        self::voices($backend);
        return $backend === 'realtime' ? 'marin' : 'fable';
    }
    private const MAX_AUDIO_BYTES = 16777216;

    public function __construct(
        private string $keyFile = '/etc/asterisk/openai.key',
        private string $ffmpeg = '/usr/bin/ffmpeg'
    ) {}

    public function generate(string $text, string $voice, string $directory, string $backend = 'tts-1'): string {
        if (!in_array($voice, self::voices($backend), true)) { throw new \InvalidArgumentException('Unsupported voice for selected TTS engine.'); }
        if (!is_executable($this->ffmpeg)) {
            throw new \RuntimeException('FFmpeg is not installed or executable: ' . $this->ffmpeg);
        }
        $input = tempnam($directory, '.announcementtts-input-');
        $output = tempnam($directory, '.announcementtts-output-');
        if ($input === false || $output === false) {
            if ($input !== false) { @unlink($input); }
            if ($output !== false) { @unlink($output); }
            throw new \RuntimeException('Unable to create temporary audio files.');
        }
        try {
            if ($backend === 'realtime') { $this->requestRealtime($text, $voice, $input); }
            else { $this->request($text, $voice, $input); }
            $this->convert($input, $output);
            self::assertWav($output);
            $name = 'announcementtts_' . bin2hex(random_bytes(16)) . '.wav';
            if (!chmod($output, 0640) || !rename($output, $directory . '/' . $name)) {
                throw new \RuntimeException('Unable to publish generated audio.');
            }
            return $name;
        } finally {
            @unlink($input);
            if (is_file($output)) { @unlink($output); }
        }
    }

    private function key(): string {
        if (!is_readable($this->keyFile)) {
            throw new \RuntimeException('OpenAI key file is missing or unreadable.');
        }
        if ((fileperms($this->keyFile) & 0007) !== 0) {
            throw new \RuntimeException('OpenAI key file must not be accessible to other users; use mode 0640.');
        }
        $key = trim((string) file_get_contents($this->keyFile));
        if ($key === '' || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new \RuntimeException('OpenAI key file is empty or invalid.');
        }
        return $key;
    }

    protected function requestRealtime(string $text, string $voice, string $output): void {
        (new RealtimeSpeech())->request($this->key(), $text, $voice, $output);
    }

    protected function request(string $text, string $voice, string $output): void {
        $key = $this->key();
        $file = fopen($output, 'wb');
        if ($file === false) { throw new \RuntimeException('Unable to open temporary audio file.'); }
        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        $bytes = 0;
        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'tts-1', 'input' => $text, 'voice' => $voice, 'response_format' => 'wav',
                ], JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$bytes): int {
                    $bytes += strlen($chunk);
                    if ($bytes > self::MAX_AUDIO_BYTES) { return 0; }
                    return (int) fwrite($file, $chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($ok === false) {
                throw new \RuntimeException('OpenAI connection failed (cURL ' . curl_errno($ch) . ').');
            }
            if ($status !== 200) {
                // Do not put provider response bodies, input text or credentials into logs/UI.
                throw new \RuntimeException('OpenAI TTS returned HTTP ' . $status . '.');
            }
            if ($bytes === 0) { throw new \RuntimeException('OpenAI returned empty audio.'); }
        } finally {
            curl_close($ch);
            fclose($file);
        }
    }

    private function convert(string $input, string $output): void {
        $process = proc_open([
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-protocol_whitelist', 'file', '-i', $input, '-vn', '-ar', '8000', '-ac', '1',
            '-c:a', 'pcm_s16le', '-f', 'wav', $output,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Unable to start FFmpeg.'); }
        $deadline = microtime(true) + 30;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('FFmpeg conversion timed out.');
                }
                usleep(50000);
            } while (true);
            if ($status['exitcode'] !== 0) { throw new \RuntimeException('FFmpeg audio conversion failed.'); }
        } finally {
            proc_close($process);
        }
    }

    public static function assertWav(string $path): void {
        $size = filesize($path);
        if ($size === false || $size < 44 || $size > self::MAX_AUDIO_BYTES) {
            throw new \RuntimeException('Invalid WAV file size.');
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) { throw new \RuntimeException('Audio file is unreadable.'); }
        try {
            $header = fread($fh, 12);
            if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                throw new \RuntimeException('Audio is not a RIFF/WAVE file.');
            }
            $format = false;
            $data = false;
            while (ftell($fh) + 8 <= $size) {
                $chunk = fread($fh, 8);
                $length = unpack('V', substr($chunk, 4, 4))[1];
                $remaining = $size - ftell($fh);
                if ($length > $remaining) { throw new \RuntimeException('Truncated WAV file.'); }
                if (substr($chunk, 0, 4) === 'fmt ' && $length >= 16) {
                    $fmt = unpack('vcodec/vchannels/Vrate/Vbytes/valign/vbits', fread($fh, 16));
                    $format = $fmt['codec'] === 1 && $fmt['channels'] === 1 && $fmt['rate'] === 8000
                        && $fmt['bits'] === 16 && $fmt['align'] === 2 && $fmt['bytes'] === 16000;
                    fseek($fh, $length - 16 + ($length % 2), SEEK_CUR);
                } else {
                    if (substr($chunk, 0, 4) === 'data') { $data = $length > 0 && $length % 2 === 0; }
                    fseek($fh, $length + ($length % 2), SEEK_CUR);
                }
            }
            if (!$format || !$data) { throw new \RuntimeException('Audio must be PCM 16-bit mono at 8000 Hz.'); }
        } finally {
            fclose($fh);
        }
    }
}
