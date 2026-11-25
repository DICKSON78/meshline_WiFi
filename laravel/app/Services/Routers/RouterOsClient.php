<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\Http;

/**
 * MikroTik RouterOS API client (and optional REST transport).
 *
 * Protocol notes (utils/mikrotik.js / node-routeros):
 *  - every word is prefixed by its encoded byte length and terminated by a
 *    zero-length word;
 *  - "=name=value" are command arguments, "?name=value" are query filters;
 *  - a command finishes with !done, !trap, !empty or !fatal.  !empty means
 *    "no rows" and must never be treated as an error (the legacy port had a
 *    crash loop around this sentence).
 *
 * When ROUTER_REST_PORT (or the router's rest_port) is present the same
 * primitives are translated to HTTP basic auth + /rest/<path>, matching
 * utils/mikrotik-rest.js.
 */
class RouterOsClient
{
    private mixed $socket = null;

    private bool $loggedIn = false;

    private string $host;

    private int $port;

    private string $user;

    private string $password;

    private bool $rest;

    private bool $tls;

    private int $timeout;

    private int $restPort;

    public function __construct(array $router)
    {
        $this->host = (string) ($router['host'] ?? '');
        $this->user = (string) ($router['user'] ?? $router['username'] ?? 'admin');
        $this->password = (string) ($router['password'] ?? '');
        $this->port = (int) ($router['port'] ?? 8728);
        $this->timeout = max(1, (int) ($router['timeout'] ?? env('MIKROTIK_TIMEOUT', 5)));
        $restPort = (int) (($router['rest_port'] ?? env('ROUTER_REST_PORT')) ?: 0);
        $this->rest = $restPort > 0;
        $this->restPort = $restPort > 0 ? $restPort : (int) env('ROUTER_REST_PORT_FALLBACK', 443);
        $this->tls = (bool) ($router['tls'] ?? ($this->port === 8729)
            || strcasecmp((string) env('ROUTER_TLS', 'false'), 'true') === 0);
    }

    public function isRest(): bool
    {
        return $this->rest;
    }

    /** Connect and authenticate. Idempotent. */
    public function connect(): self
    {
        if ($this->loggedIn) {
            return $this;
        }
        if ($this->rest) {
            // A cheap identity read validates credentials and reachability.
            $this->restRequest('GET', '/rest/system/identity');

            $this->loggedIn = true;

            return $this;
        }

        if ($this->host === '') {
            throw new \RuntimeException('Router host is missing');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => (bool) env('ROUTER_TLS_VERIFY', true),
                'verify_peer_name' => (bool) env('ROUTER_TLS_VERIFY', true),
                'allow_self_signed' => ! env('ROUTER_TLS_VERIFY', true),
            ],
        ]);
        $scheme = $this->tls ? 'tls' : 'tcp';
        $socket = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (! $socket) {
            throw new \RuntimeException("Cannot connect to {$this->host}:{$this->port}: {$errstr}");
        }
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        try {
            $this->login();
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
        $this->loggedIn = true;

        return $this;
    }

    private function login(): void
    {
        // RouterOS 6 challenge/response, then the newer plain-text form as a
        // fallback (RouterOS 7 accepts both).
        $challenge = $this->raw('/login', [], false);
        $ret = $this->firstValue($challenge['meta'], 'ret');
        if ($ret !== null) {
            $response = '00'.md5("\x00".$this->password.hex2bin($ret));
            $reply = $this->raw('/login', ['name' => $this->user, 'response' => $response], false);
            if ($reply['status'] === 'done') {
                return;
            }
        }

        $reply = $this->raw('/login', ['name' => $this->user, 'password' => $this->password], false);
        if ($reply['status'] !== 'done') {
            throw new \RuntimeException('RouterOS login failed: '.($reply['message'] ?? $reply['status']));
        }
    }

    /** @return array{status:string,rows:array<int,array>,message:?string} */
    public function raw(string $path, array $args = [], bool $ensureLogin = true): array
    {
        if ($ensureLogin) {
            $this->connect();
        }
        if ($this->rest) {
            return $this->restCommand($path, $args);
        }

        $words = [$path];
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                $words[] = (string) $value;
                continue;
            }
            $key = (string) $key;
            if (str_starts_with($key, '?') || str_starts_with($key, '.') || str_starts_with($key, '!')) {
                $words[] = $key.'='.$value;
            } else {
                $words[] = '='.$key.'='.$value;
            }
        }

        $payload = '';
        foreach ($words as $word) {
            $payload .= $this->encode((string) $word);
        }
        $payload .= "\x00";
        $this->writeAll($payload);

        $rows = [];
        $meta = [];
        $status = '';
        $message = null;
        while (true) {
            $sentence = $this->readSentence();
            foreach ($sentence as $word) {
                if (str_starts_with($word, '!')) {
                    $status = substr($word, 1);
                    continue;
                }
                if (str_starts_with($word, '=')) {
                    $pair = substr($word, 1);
                    $pos = strpos($pair, '=');
                    if ($pos !== false) {
                        $meta[substr($pair, 0, $pos)] = substr($pair, $pos + 1);
                        if (substr($pair, 0, $pos) === 'message') {
                            $message = substr($pair, $pos + 1);
                        }
                    }
                }
            }
            $row = $this->sentenceToRow($sentence);
            if ($row !== []) {
                $rows[] = $row;
            }
            if ($status !== '') {
                break;
            }
        }

        if ($status === 'empty') {
            // RouterOS sends !empty for an empty print. Normalise it.
            return ['status' => 'empty', 'rows' => [], 'meta' => $meta, 'message' => $message];
        }
        if ($status === 'fatal') {
            $this->close();
            throw new \RuntimeException('RouterOS fatal: '.($message ?: 'connection closed'));
        }
        if ($status === 'trap') {
            throw new \RuntimeException('RouterOS error: '.($message ?: 'unknown trap'));
        }

        return ['status' => $status ?: 'done', 'rows' => $rows, 'meta' => $meta, 'message' => $message];
    }

    /** Execute a command and return its rows ([] for !empty). */
    public function command(string $path, array $args = []): array
    {
        return $this->raw($path, $args)['rows'];
    }

    /** Print a menu, optionally with ?name=value query filters. */
    public function printRows(string $path, array $query = []): array
    {
        $args = [];
        foreach ($query as $key => $value) {
            $args[str_starts_with((string) $key, '?') ? $key : '?'.$key] = $value;
        }

        return $this->command(rtrim($path, '/').'/print', $args);
    }

    public function firstRow(string $path, array $query = []): array
    {
        $rows = $this->printRows($path, $query);

        return $rows[0] ?? [];
    }

    /** Remove every row whose comment matches (RouterOS has no "[find]"). */
    public function removeByComment(string $path, string $comment): int
    {
        $removed = 0;
        foreach ($this->printRows($path) as $row) {
            if (($row['comment'] ?? '') === $comment && isset($row['.id'])) {
                $this->command(rtrim($path, '/').'/remove', ['.id' => $row['.id']]);
                $removed++;
            }
        }

        return $removed;
    }

    public function setByQuery(string $path, array $query, array $values): bool
    {
        $row = $this->firstRow($path, $query);
        if (! isset($row['.id'])) {
            return false;
        }
        $this->command(rtrim($path, '/').'/set', array_merge(['.id' => $row['.id']], $values));

        return true;
    }

    public function close(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->loggedIn = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    // ------------------------------------------------------------------
    // Wire protocol helpers
    // ------------------------------------------------------------------

    private function encode(string $word): string
    {
        $length = strlen($word);
        if ($length < 128) {
            return chr($length).$word;
        }
        if ($length < 16384) {
            $n = $length | 0x8000;

            return chr($n >> 8).chr($n & 0xff).$word;
        }
        if ($length < 2097152) {
            $n = $length | 0xC00000;

            return chr($n >> 16).chr(($n >> 8) & 0xff).chr($n & 0xff).$word;
        }
        if ($length < 268435456) {
            $n = $length | 0xE0000000;

            return chr($n >> 24).chr(($n >> 16) & 0xff).chr(($n >> 8) & 0xff).chr($n & 0xff).$word;
        }

        return "\xff\xff\xff\xff".$word;
    }

    private function readLength(): int
    {
        $first = $this->readBytes(1);
        if ($first === '') {
            return -1;
        }
        $b = ord($first[0]);
        if (($b & 0x80) === 0) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            return (($b & 0x3f) << 8) + ord($this->readBytes(1)[0] ?? "\x00");
        }
        if (($b & 0xE0) === 0xC0) {
            $rest = $this->readBytes(2);

            return (($b & 0x1f) << 16) + (ord($rest[0] ?? "\x00") << 8) + ord($rest[1] ?? "\x00");
        }
        if (($b & 0xF0) === 0xE0) {
            $rest = $this->readBytes(3);

            return (($b & 0x0f) << 24) + (ord($rest[0] ?? "\x00") << 16)
                + (ord($rest[1] ?? "\x00") << 8) + ord($rest[2] ?? "\x00");
        }
        $rest = $this->readBytes(4);

        return (ord($rest[0] ?? "\x00") << 24) + (ord($rest[1] ?? "\x00") << 16)
            + (ord($rest[2] ?? "\x00") << 8) + ord($rest[3] ?? "\x00");
    }

    /** @return array<int,string> */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len <= 0) {
                return $words;
            }
            $words[] = $this->readBytes($len);
        }
    }

    /** @param array<int,string> $sentence */
    private function sentenceToRow(array $sentence): array
    {
        $row = [];
        foreach ($sentence as $word) {
            if (str_starts_with($word, '=')) {
                $pair = substr($word, 1);
                $pos = strpos($pair, '=');
                if ($pos !== false) {
                    $row[substr($pair, 0, $pos)] = substr($pair, $pos + 1);
                } elseif ($pair !== '') {
                    $row[$pair] = '';
                }
            }
        }

        return $row;
    }

    /** @param array<int,array> $rows */
    private function firstValue(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            if (isset($row[$key])) {
                return (string) $row[$key];
            }
        }

        return null;
    }

    private function writeAll(string $data): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('RouterOS socket write failed');
            }
            $written += $n;
        }
    }

    private function readBytes(int $count): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $chunk = @fread($this->socket, $count - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (! empty($meta['timed_out'])) {
                    throw new \RuntimeException('RouterOS request timed out');
                }
                throw new \RuntimeException('RouterOS socket closed while reading');
            }
            $data .= $chunk;
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // REST transport
    // ------------------------------------------------------------------

    /** @return array{status:string,rows:array,message:?string} */
    private function restCommand(string $path, array $args): array
    {
        $path = trim($path, '/');
        $suffix = '';
        if (preg_match('#/([a-z-]+)$#', $path, $m) && in_array($m[1], [
            'print', 'add', 'set', 'remove', 'enable', 'disable', 'reboot', 'shutdown',
        ], true)) {
            $suffix = $m[1];
            $path = substr($path, 0, -strlen($m[1]) - 1);
        }
        $id = $args['.id'] ?? $args['id'] ?? null;
        $body = $args;
        unset($body['.id'], $body['id']);

        $query = [];
        foreach ($body as $key => $value) {
            if (str_starts_with((string) $key, '?')) {
                $query[substr((string) $key, 1)] = $value;
                unset($body[$key]);
            }
        }

        if ($suffix === 'add') {
            $result = $this->restRequest('PUT', '/rest/'.$path, $body);
            $rows = is_array($result) && isset($result[0]) ? $result : [$result];
        } elseif ($suffix === 'set' && $id !== null) {
            $result = $this->restRequest('PATCH', '/rest/'.$path.'/'.rawurlencode((string) $id), $body);
            $rows = is_array($result) && isset($result[0]) ? $result : [$result];
        } elseif (in_array($suffix, ['remove', 'enable', 'disable'], true) && $id !== null) {
            $result = $this->restRequest('POST', '/rest/'.$path.'/'.rawurlencode((string) $id).'/'.$suffix);
            $rows = is_array($result) && isset($result[0]) ? $result : [$result];
        } elseif (in_array($suffix, ['reboot', 'shutdown'], true)) {
            $result = $this->restRequest('POST', '/rest/'.$path);
            $rows = [];
        } else {
            $result = $this->restRequest('GET', '/rest/'.$path, $query);
            $rows = is_array($result) ? $result : [];
            $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && ! isset($row['!empty'])));
        }

        return ['status' => 'done', 'rows' => $rows, 'message' => null];
    }

    private function restRequest(string $method, string $path, array $data = []): mixed
    {
        $url = ($this->tls ? 'https' : 'http').'://'.$this->host.':'.$this->restPort.$path;
        if ($method === 'GET' && $data) {
            $url .= '?'.http_build_query($data);
        }
        $request = Http::withBasicAuth($this->user, $this->password)
            ->withOptions([
                'verify' => (bool) env('ROUTER_REST_VERIFY', env('NODE_ENV', 'local') === 'production'),
                'timeout' => $this->timeout,
            ])
            ->acceptJson();
        $response = match ($method) {
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => $request->get($url),
        };
        if ($response->failed()) {
            throw new \RuntimeException('REST API error '.$response->status().': '.$response->body());
        }
        $json = $response->json();

        return $json === null ? $response->body() : $json;
    }
}
