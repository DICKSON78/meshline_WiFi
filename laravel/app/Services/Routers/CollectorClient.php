<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\Http;

/**
 * Tiny helper for HTTP workers to talk to the Amp collector server.
 *
 * The collector keeps the WebSocket connections in its own process, so all
 * cross-process traffic goes through POST /send on 127.0.0.1 (default 8081).
 * A file-backed request/response queue is used as a fallback and for
 * fire-and-forget messages (e.g. {"type":"scan"}).
 */
final class CollectorClient
{
    public static function url(): string
    {
        return rtrim((string) env('COLLECTOR_SEND_URL', 'http://127.0.0.1:'.env('COLLECTOR_HTTP_PORT', 8081)), '/');
    }

    private static function dir(string $sub = ''): string
    {
        $root = storage_path('app/collector');
        foreach (['', 'state', 'requests', 'responses'] as $part) {
            if ($part !== '' && ! is_dir($root.'/'.$part)) {
                @mkdir($root.'/'.$part, 0700, true);
            }
        }

        return $sub !== '' ? $root.'/'.$sub : $root;
    }

    /**
     * Forward {type:'mikrotik',id,path,args,router} and wait <= $timeout sec.
     *
     * @return array{ok:bool,data:mixed,error:?string}
     */
    public static function send(string $collectorKey, string $path, array $args = [], mixed $router = null, int $timeout = 20): array
    {
        $payload = [
            'collectorKey' => $collectorKey,
            'path' => $path,
            'args' => $args,
            'router' => $router,
        ];
        try {
            $response = Http::timeout($timeout + 2)->asJson()->post(self::url().'/send', $payload);
            if ($response->successful()) {
                $json = $response->json();

                return [
                    'ok' => (bool) ($json['ok'] ?? false),
                    'data' => $json['data'] ?? null,
                    'error' => $json['error'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // fall through to the file queue
        }

        return self::sendViaFiles($collectorKey, $path, $args, $router, $timeout);
    }

    /** File-queue equivalent of send(), used when HTTP is not reachable. */
    public static function sendViaFiles(string $collectorKey, string $path, array $args = [], mixed $router = null, int $timeout = 20): array
    {
        $id = 'cmd_'.bin2hex(random_bytes(10));
        $request = [
            'type' => 'mikrotik', 'id' => $id, 'path' => $path,
            'args' => $args, 'router' => $router, 'collectorKey' => $collectorKey,
        ];
        self::writeRequest($collectorKey, $request);
        $responseFile = self::dir('responses').'/'.$id.'.json';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (is_file($responseFile)) {
                $json = json_decode((string) file_get_contents($responseFile), true);
                @unlink($responseFile);

                return [
                    'ok' => (bool) ($json['ok'] ?? false),
                    'data' => $json['data'] ?? null,
                    'error' => $json['error'] ?? 'collector timeout',
                ];
            }
            usleep(50000);
        }
        @unlink($responseFile);

        return ['ok' => false, 'data' => null, 'error' => 'collector timeout'];
    }

    /** Fire-and-forget (used by /router-fleet/detect to send {"type":"scan"}). */
    public static function sendRaw(string $collectorKey, array $message): bool
    {
        $message['collectorKey'] = $collectorKey;
        $message['id'] ??= 'msg_'.bin2hex(random_bytes(8));

        return self::writeRequest($collectorKey, $message);
    }

    private static function writeRequest(string $collectorKey, array $message): bool
    {
        $id = (string) ($message['id'] ?? bin2hex(random_bytes(8)));
        $file = self::dir('requests').'/'.$id.'.json';
        $tmp = $file.'.tmp';

        return @file_put_contents($tmp, json_encode($message), LOCK_EX) !== false
            && @rename($tmp, $file);
    }

    /** Collector state published by the Amp process (file-backed). */
    public static function collectors(): array
    {
        $out = [];
        foreach (glob(self::dir('state').'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ! empty($data['key'])) {
                $out[] = $data;
            }
        }

        return $out;
    }

    public static function heartbeat(string $collectorKey): ?array
    {
        $data = self::collectorState($collectorKey);

        return $data ? ($data['heartbeat'] ?? null) : null;
    }

    public static function isConnected(string $collectorKey): bool
    {
        $data = self::collectorState($collectorKey);

        return ! empty($data['connected']);
    }

    public static function collectorState(string $collectorKey): ?array
    {
        $file = self::dir('state').'/'.sha1($collectorKey).'.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
