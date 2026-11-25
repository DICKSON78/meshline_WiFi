<?php

namespace App\Console\Commands;

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Services\Routers\CollectorClient;
use App\Services\Routers\ProvisioningService;
use Illuminate\Console\Command;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

use function Amp\trapSignal;

/**
 * Reverse WebSocket tunnel for customer-LAN collectors + cross-process HTTP
 * command proxy.  Port of utils/collectorTunnel.js and the fleet proxy.
 *
 *   GET  /api/collector/ws?key=&name=   collector WebSocket endpoint
 *   POST /send                          loopback-only proxy to a collector
 *   GET  /health                        liveness probe
 */
class CollectorServe extends Command
{
    protected $signature = 'collector:serve {--host=0.0.0.0} {--port=}';

    protected $description = 'Run the Amp collector WebSocket tunnel and HTTP /send proxy';

    /** collectorKey => websocket client id */
    private array $keys = [];

    /** websocket client id => collectorKey */
    private array $clients = [];

    /** websocket client id => WebsocketClient (for 1001 replacement) */
    private array $socketClients = [];

    /** command id => DeferredFuture (HTTP /send requests) */
    private array $waiters = [];

    private int $port;

    public function handle(): int
    {
        $this->port = (int) ($this->option('port') ?: env('COLLECTOR_HTTP_PORT', 8081));
        // Stale "connected" state from a previous process must not survive.
        foreach (glob(storage_path('app/collector/state/*.json') ?: []) ?: [] as $stale) {
            @unlink($stale);
        }
        $logger = new Logger('collector', [new NullHandler()]);
        $server = SocketHttpServer::createForDirectAccess($logger, enableCompression: false);
        $server->expose(new InternetAddress((string) $this->option('host'), $this->port));

        $gateway = new WebsocketClientGateway();
        $handler = new class($this, $logger, $gateway) implements WebsocketClientHandler {
            public function __construct(
                private readonly CollectorServe $owner,
                private readonly LoggerInterface $logger,
                private readonly WebsocketClientGateway $gateway,
            ) {}

            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                $this->owner->onCollector($client, $request, $this->gateway);
            }
        };
        $websocket = new Websocket($server, $logger, new Rfc6455Acceptor(), $handler);
        $dispatcher = new class($this, $websocket) implements RequestHandler {
            public function __construct(
                private readonly CollectorServe $owner,
                private readonly Websocket $websocket,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return $this->owner->dispatch($request, $this->websocket);
            }
        };

        // Cross-process request queue (Laravel FPM workers -> this process).
        EventLoop::repeat(0.1, function () use ($gateway): void {
            $this->pumpRequests($gateway);
        });

        $defaultErrors = new DefaultErrorHandler();
        $errorHandler = new class($defaultErrors) implements \Amp\Http\Server\ErrorHandler {
            public function __construct(private readonly DefaultErrorHandler $delegate) {}

            #[\Override]
            public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
            {
                if ($status >= 500) {
                    fwrite(STDERR, '[collector] HTTP '.$status.': '.$reason."\n");
                }

                return $this->delegate->handleError($status, $reason, $request);
            }
        };
        $server->start($dispatcher, $errorHandler);
        $this->info("Collector tunnel listening on {$this->option('host')}:{$this->port}");
        trapSignal([\SIGINT, \SIGTERM]);
        $server->stop();

        return self::SUCCESS;
    }

    public function dispatch(Request $request, Websocket $websocket): Response
    {
        try {
            return $this->dispatchInner($request, $websocket);
        } catch (\Throwable $e) {
            fwrite(STDERR, '[collector] dispatch error: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");

            return $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function dispatchInner(Request $request, Websocket $websocket): Response
    {
        $path = parse_url((string) $request->getUri(), PHP_URL_PATH) ?: '/';
        if ($path === '/api/collector/ws') {
            return $websocket->handleRequest($request);
        }
        if ($path === '/health') {
            return $this->jsonResponse(['ok' => true, 'collectors' => count($this->keys), 'port' => $this->port]);
        }
        if ($path === '/send' && $request->getMethod() === 'POST') {
            if (! $this->isLoopback($request)) {
                return $this->jsonResponse(['ok' => false, 'error' => 'forbidden'], 403);
            }
            try {
                $body = json_decode($request->getBody()->buffer(), true) ?: [];
            } catch (\Throwable $e) {
                $body = [];
            }
            $key = (string) ($body['collectorKey'] ?? '');
            $command = (string) ($body['path'] ?? '');
            if ($key === '' || $command === '') {
                return $this->jsonResponse(['ok' => false, 'error' => 'collectorKey and path are required'], 400);
            }

            return $this->proxy($key, [
                'type' => 'mikrotik',
                'id' => 'fleet_'.bin2hex(random_bytes(8)),
                'path' => $command,
                'args' => $body['args'] ?? [],
                'router' => $body['router'] ?? null,
            ]);
        }

        return $this->jsonResponse(['error' => 'not found'], 404);
    }

    private function proxy(string $key, array $message, bool $wait = true): Response
    {
        if (! isset($this->keys[$key])) {
            return $this->jsonResponse(['ok' => false, 'data' => null, 'error' => 'collector not connected'], 409);
        }
        $deferred = new DeferredFuture();
        $this->waiters[$message['id']] = $deferred;
        $this->send($this->keys[$key], $message);
        if (! $wait) {
            unset($this->waiters[$message['id']]);

            return $this->jsonResponse(['ok' => true, 'queued' => true]);
        }
        try {
            $result = $deferred->getFuture()->await(new \Amp\TimeoutCancellation(20.0, 'collector timeout'));

            return $this->jsonResponse(is_array($result) ? $result : ['ok' => false, 'error' => 'invalid collector response']);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['ok' => false, 'data' => null, 'error' => $e->getMessage()]);
        } finally {
            unset($this->waiters[$message['id']]);
        }
    }

    private function send(int $clientId, array $message): void
    {
        try {
            $future = $this->gatewayForClient($clientId)?->sendText(json_encode($message, JSON_UNESCAPED_SLASHES) ?: '{}', $clientId);
            $future?->await();
        } catch (\Throwable $e) {
            $this->warn('collector send failed: '.$e->getMessage());
        }
    }

    /** The gateway is held on the anonymous handler; use the shared registry instead. */
    private ?WebsocketClientGateway $gateway = null;

    private function gatewayForClient(int $clientId): ?WebsocketClientGateway
    {
        return $this->gateway;
    }

    public function onCollector(WebsocketClient $client, Request $request, WebsocketClientGateway $gateway): void
    {
        $this->gateway = $gateway;
        $key = (string) ($request->getQueryParameter('key') ?? '');
        $name = (string) ($request->getQueryParameter('name') ?? 'collector');
        if ($key === '') {
            $client->close(1008, 'missing collector key');

            return;
        }
        // Same key reconnecting replaces the previous connection.
        if (isset($this->keys[$key])) {
            $oldId = $this->keys[$key];
            unset($this->keys[$key], $this->clients[$oldId]);
            $oldClient = $this->socketClients[$oldId] ?? null;
            unset($this->socketClients[$oldId]);
            try {
                $oldClient?->close(1001, 'replaced by new connection');
            } catch (\Throwable $e) {
                // the old socket may already be gone
            }
        }
        $gateway->addClient($client);
        $id = $client->getId();
        $this->keys[$key] = $id;
        $this->clients[$id] = $key;
        $this->socketClients[$id] = $client;
        $this->publishState($key, $name, true, null);
        $this->info("collector connected: {$name} ({$key})");

        try {
            while ($message = $client->receive()) {
                $data = json_decode($message->buffer(), true);
                if (! is_array($data)) {
                    continue;
                }
                $type = (string) ($data['type'] ?? '');
                if ($type === 'mikrotik_response') {
                    $this->onResponse($data);
                } elseif ($type === 'heartbeat') {
                    $this->onHeartbeat($key, $name, $data);
                } elseif ($type === 'announce') {
                    $name = (string) ($data['collector'] ?? $name);
                    $this->publishState($key, $name, true, $this->state($key)['heartbeat'] ?? null);
                }
            }
        } catch (\Throwable $e) {
            $this->warn("collector {$key} error: ".$e->getMessage());
        } finally {
            unset($this->clients[$id], $this->socketClients[$id]);
            // A replaced connection's finally block must not evict its successor.
            if (($this->keys[$key] ?? null) === $id) {
                unset($this->keys[$key]);
                $this->publishState($key, $name, false, $this->state($key)['heartbeat'] ?? null);
            }
            $this->info("collector disconnected: {$name} ({$key})");
        }
    }

    private function onResponse(array $data): void
    {
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return;
        }
        // File-backed response for HTTP workers using CollectorClient.
        $file = storage_path('app/collector/responses/'.$id.'.json');
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);
        if (isset($this->waiters[$id])) {
            $this->waiters[$id]->complete($data);
        }
    }

    private function onHeartbeat(string $key, string $name, array $data): void
    {
        $heartbeat = [
            'at' => $data['at'] ?? gmdate('c'),
            'routers' => $data['routers'] ?? [],
            'collector' => $data['collector'] ?? $name,
        ];
        $this->publishState($key, $name, true, $heartbeat);
        $provisioning = new ProvisioningService();
        foreach ($heartbeat['routers'] as $router) {
            if (! is_array($router)) {
                continue;
            }
            try {
                $provisioning->autoRegisterDetected($router, $key);
            } catch (\Throwable $e) {
                $this->warn('auto-register failed: '.$e->getMessage());
            }
        }
    }

    /** Publish collector state to disk so FPM workers can list it. */
    private function publishState(string $key, string $name, bool $connected, ?array $heartbeat): void
    {
        $dir = storage_path('app/collector/state');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir.'/'.sha1($key).'.json';
        $tmp = $file.'.tmp';
        @file_put_contents($tmp, json_encode([
            'key' => $key,
            'name' => $name,
            'connected' => $connected,
            'lastHeartbeat' => $heartbeat['at'] ?? null,
            'routers' => $heartbeat['routers'] ?? [],
            'heartbeat' => $heartbeat,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $file);
    }

    private function state(string $key): array
    {
        $data = CollectorClient::collectorState($key);

        return $data ?? [];
    }

    /** Pick up request files written by FPM workers and forward them. */
    private function pumpRequests(WebsocketClientGateway $gateway): void
    {
        $this->gateway = $gateway;
        $dir = storage_path('app/collector/requests');
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $processing = $file.'.processing';
            if (! @rename($file, $processing)) {
                continue;
            }
            $message = json_decode((string) file_get_contents($processing), true);
            @unlink($processing);
            if (! is_array($message)) {
                continue;
            }
            $key = (string) ($message['collectorKey'] ?? '');
            $id = (string) ($message['id'] ?? '');
            if ($key === '' || ! isset($this->keys[$key])) {
                $this->writeFileResponse($id, ['id' => $id, 'ok' => false, 'data' => null, 'error' => 'collector not connected']);
                continue;
            }
            unset($message['collectorKey']);
            // onResponse() always writes <id>.json, so HTTP workers waiting
            // in CollectorClient will see the reply regardless of transport.
            $this->send($this->keys[$key], $message);
        }
    }

    private function writeFileResponse(string $id, mixed $result): void
    {
        if ($id === '') {
            return;
        }
        $dir = storage_path('app/collector/responses');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($dir.'/'.$id.'.json', json_encode($result), LOCK_EX);
    }

    private function isLoopback(Request $request): bool
    {
        $address = $request->getClient()->getRemoteAddress();
        if ($address === null) {
            return false;
        }
        $host = trim((string) $address->getAddress(), '[]');

        return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response($status, [
            'content-type' => 'application/json; charset=utf-8',
            'access-control-allow-origin' => '*',
        ], json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
