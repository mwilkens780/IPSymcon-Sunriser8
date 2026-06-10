<?php

declare(strict_types=1);

require_once __DIR__ . '/msgpack.php';

/**
 * HTTP client for the SunRiser 8 LED controller API.
 * All data is exchanged as MessagePack over HTTP/1.1.
 */
class Sunriser8API
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct(string $host, int $port = 80, int $timeout = 5)
    {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->timeout = $timeout;
    }

    // ─── Availability ─────────────────────────────────────────────────────────

    public function ping(): bool
    {
        $result = $this->rawRequest('GET', '/ok');
        return $result !== null && trim((string) $result) === 'OK';
    }

    // ─── State ────────────────────────────────────────────────────────────────

    /** Returns nested state hash: pwms, maintenance, temperature, etc. */
    public function getState(): array
    {
        $data = $this->request('GET', '/state');
        return is_array($data) ? $data : [];
    }

    /**
     * Directly set PWM values (1-minute override / function test).
     * $pwms = ['1' => 200, '2' => 100, ...]  values 0–255
     */
    public function setPwms(array $pwms): void
    {
        $this->request('PUT', '/state', ['pwms' => $pwms]);
    }

    /**
     * Set maintenance mode.
     * The Sunriser state supports a 'maintenance' key.
     */
    public function setMaintenance(bool $active): void
    {
        $this->request('PUT', '/state', ['maintenance' => $active]);
    }

    // ─── Configuration ────────────────────────────────────────────────────────

    /**
     * Read one or more config keys.
     * $keys = ['pwm#1#name', 'pwm#1#color', 'dayplanner#marker#1', ...]
     * Returns associative array key → value.
     */
    public function getConfig(array $keys): array
    {
        $data = $this->request('POST', '/', $keys);
        return is_array($data) ? $data : [];
    }

    /**
     * Write config keys.
     * $data = ['pwm#1#name' => 'Weiss', 'dayplanner#marker#1' => [0,0,720,100,1440,0]]
     */
    public function setConfig(array $data): void
    {
        $this->request('PUT', '/', $data);
    }

    /** Full device backup (all config values as associative array). */
    public function getBackup(): array
    {
        $data = $this->request('GET', '/backup');
        return is_array($data) ? $data : [];
    }

    // ─── Convenience helpers ──────────────────────────────────────────────────

    /**
     * Read all config keys relevant for the IPS module:
     * channel names, colors, managers, weather programs + day curves.
     */
    public function getModuleConfig(int $channelCount): array
    {
        $keys = [];
        for ($i = 1; $i <= $channelCount; $i++) {
            $keys[] = "pwm#{$i}#name";
            $keys[] = "pwm#{$i}#color";
            $keys[] = "pwm#{$i}#manager";
            $keys[] = "pwm#{$i}#max";
            $keys[] = "pwm#{$i}#weather";
            $keys[] = "dayplanner#marker#{$i}";
        }
        return $this->getConfig($keys);
    }

    /**
     * Read weather effect toggles for a given weather program name.
     * Returns ['thunder' => bool, 'moon' => bool, 'clouds' => bool, 'rain' => bool]
     */
    public function getWeatherToggles(string $program): array
    {
        $keys   = [];
        $effects = ['thunder', 'moon', 'clouds', 'rain'];
        foreach ($effects as $effect) {
            $keys[] = "weather#setup#{$program}#{$effect}#activated";
        }
        $raw    = $this->getConfig($keys);
        $result = [];
        foreach ($effects as $effect) {
            $key            = "weather#setup#{$program}#{$effect}#activated";
            $result[$effect] = (bool) ($raw[$key] ?? false);
        }
        return $result;
    }

    /**
     * Enable or disable a weather effect for a given program.
     * $effect = 'thunder' | 'moon' | 'clouds' | 'rain'
     */
    public function setWeatherEffect(string $program, string $effect, bool $active): void
    {
        $this->setConfig([
            "weather#setup#{$program}#{$effect}#activated" => $active,
        ]);
    }

    /** Set the weather program for a single channel. */
    public function setChannelWeatherProgram(int $channel, string $program): void
    {
        $this->setConfig(["pwm#{$channel}#weather" => $program]);
    }

    // ─── HTTP transport ───────────────────────────────────────────────────────

    /** Sends a MessagePack request and returns the decoded response. */
    private function request(string $method, string $path, mixed $body = null): mixed
    {
        $rawResponse = $this->rawRequest($method, $path, $body);
        if ($rawResponse === null) {
            return null;
        }
        if (strlen($rawResponse) === 0) {
            return [];
        }
        return MsgPack::decode($rawResponse);
    }

    /** Sends a raw HTTP request and returns the raw response body. */
    private function rawRequest(string $method, string $path, mixed $body = null): ?string
    {
        $ch = curl_init();

        $headers = [
            'Content-Type: application/x-msgpack',
            'Accept: application/x-msgpack',
            'Connection: close',
        ];

        $opts = [
            CURLOPT_URL            => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'POST') {
            $encoded = ($body !== null) ? MsgPack::encode($body) : '';
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $encoded;
        } elseif ($method === 'PUT') {
            $encoded = ($body !== null) ? MsgPack::encode($body) : '';
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = $encoded;
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $opts);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException("SunRiser cURL error: {$curlError}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("SunRiser HTTP {$httpCode} on {$method} {$path}");
        }

        return $response;
    }
}
