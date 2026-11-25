<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\Http;

/**
 * TP-Link Omada OpenAPI client.  One implementation handles both:
 *
 *  - on-prem: https://<controller>:8043/api/v2, POST /login with
 *    username/password, TPOMADA_SESSIONID cookie + X-CSRFToken + Referer;
 *  - cloud:   https://omada.tplinknetwork.com/api/v2, clientId/clientSecret
 *    token exchange and Authorization: Bearer.
 *
 * NOTE: Omada's OpenAPI surface differs a lot between controller firmware
 * versions and the cloud portal.  Endpoints marked UNCERTAIN below are best
 * effort: they are attempted and, on failure, return an empty result so the
 * caller can degrade gracefully rather than crash the request.
 */
class OmadaClient
{
    private string $base;

    private string $mode;

    private string $siteId;

    private array $router;

    private ?string $token = null;

    private ?string $cookie = null;

    private bool $authenticated = false;

    private ?string $lastError = null;

    private int $timeout;

    public function __construct(array $router)
    {
        $this->router = $router;
        $this->mode = strtolower((string) ($router['omada_mode'] ?? env('OMADA_MODE', 'onprem')));
        $this->siteId = (string) ($router['omada_site_id'] ?? env('OMADA_SITE_ID', ''));
        $this->timeout = max(2, (int) env('OMADA_TIMEOUT', 10));
        $this->base = $this->mode === 'cloud' ? $this->cloudBase() : $this->onPremBase();
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** Last HTTP/API error seen by safe()/firstSuccess(), for diagnostics. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function host(): string
    {
        return (string) ($this->router['host'] ?? $this->router['omada_host'] ?? '');
    }

    private function cloudBase(): string
    {
        $url = (string) (env('OMADA_CLOUD_URL') ?: env('OMADA_BASE_URL') ?: 'https://omada.tplinknetwork.com');

        return rtrim($url, '/').'/api/v2';
    }

    private function onPremBase(): string
    {
        $url = (string) ($this->router['omada_controller_url'] ?? env('OMADA_CONTROLLER_URL', ''));
        if ($url !== '') {
            $url = rtrim($url, '/');

            return str_ends_with($url, '/api/v2') ? $url : $url.'/api/v2';
        }

        $host = $this->host();
        if ($host === '') {
            throw new \RuntimeException('Omada controller is not configured: set OMADA_CONTROLLER_URL or the router host.');
        }

        return 'https://'.$host.':'.(int) ($this->router['omada_port'] ?? env('OMADA_PORT', 8043)).'/api/v2';
    }

    private function verifyTls(): bool
    {
        $value = $this->router['omada_verify_tls'] ?? env('OMADA_VERIFY_TLS', 'true');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function authenticate(): void
    {
        if ($this->authenticated) {
            return;
        }
        $payload = $this->mode === 'cloud'
            ? [
                'clientId' => (string) ($this->router['omada_client_id'] ?? env('OMADA_CLIENT_ID', '')),
                'clientSecret' => (string) ($this->router['omada_client_secret'] ?? env('OMADA_CLIENT_SECRET', '')),
            ]
            : [
                'username' => (string) ($this->router['omada_username'] ?? $this->router['user']
                    ?? $this->router['omada_user'] ?? env('OMADA_USERNAME', 'admin')),
                'password' => (string) ($this->router['omada_password'] ?? $this->router['password']
                    ?? env('OMADA_PASSWORD', '')),
            ];
        $response = Http::withOptions([
            'verify' => $this->verifyTls(),
            'timeout' => $this->timeout,
        ])->acceptJson()->asJson()->post($this->base.'/login', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Omada login failed ('.$response->status().'): '.$response->body());
        }
        $json = is_array($response->json()) ? $response->json() : [];
        if ($this->mode === 'cloud' && ($this->router['omada_client_id'] ?? env('OMADA_CLIENT_ID', '')) === '') {
            throw new \RuntimeException('Omada cloud requires OMADA_CLIENT_ID / OMADA_CLIENT_SECRET.');
        }
        $this->token = (string) (
            $json['result']['token']
            ?? $json['result']['accessToken']
            ?? $json['token']
            ?? $json['accessToken']
            ?? $response->header('X-CSRFToken')
            ?? ''
        );
        $this->cookie = $this->cookiesFromHeader($response->header('Set-Cookie'));
        $this->authenticated = true;
    }

    /** Extract real cookies from a Set-Cookie header, dropping attributes. */
    private function cookiesFromHeader(mixed $header): ?string
    {
        if (! $header) {
            return null;
        }
        $raw = is_array($header) ? implode("\n", $header) : (string) $header;
        $parts = preg_split('/;\s*|,\s*(?=[A-Za-z_][A-Za-z0-9_-]*=)/', $raw) ?: [];
        $skip = ['expires', 'max-age', 'path', 'domain', 'secure', 'httponly', 'samesite', 'priority'];
        $cookies = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (! preg_match('/^([^=;\s]+)=(.*)$/', $part, $m)) {
                continue;
            }
            if (in_array(strtolower($m[1]), $skip, true)) {
                continue;
            }
            $cookies[$m[1]] = $m[2];
        }
        if (! $cookies) {
            return null;
        }
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return implode('; ', $pairs);
    }

    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        $this->authenticate();
        $url = $this->base.$path;
        if ($query) {
            $url .= '?'.http_build_query($query);
        }
        $headers = [
            'Accept' => 'application/json',
            'Referer' => preg_replace('#/api/v2$#', '/', $this->base),
        ];
        if ($this->mode === 'cloud') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        } else {
            if ($this->cookie) {
                $headers['Cookie'] = $this->cookie;
            }
            if ($this->token) {
                $headers['X-CSRFToken'] = $this->token;
            }
        }
        $http = Http::withOptions(['verify' => $this->verifyTls(), 'timeout' => $this->timeout])
            ->withHeaders($headers);
        $response = match ($method) {
            'POST' => $http->asJson()->post($url, $data),
            'PUT' => $http->asJson()->put($url, $data),
            'PATCH' => $http->asJson()->patch($url, $data),
            'DELETE' => $http->delete($url, $data),
            default => $http->get($url),
        };
        if ($response->failed()) {
            throw new \RuntimeException("Omada {$method} {$path} failed ({$response->status()}): ".$response->body());
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** Best-effort wrapper: Omada version differences must not crash callers. */
    private function safe(string $method, string $path, array $data = [], array $query = [], mixed $fallback = []): mixed
    {
        try {
            $response = $this->request($method, $path, $data, $query);
            if ((int) ($response['errorCode'] ?? 0) !== 0) {
                $this->lastError = $method.' '.$path.': '.(string) ($response['msg'] ?? $response['message'] ?? 'API error '.$response['errorCode']);

                return $fallback;
            }
            $this->lastError = null;

            return $response['result'] ?? $response;
        } catch (\Throwable $e) {
            $this->lastError = $method.' '.$path.': '.$e->getMessage();
            report($e);

            return $fallback;
        }
    }

    /**
     * Try alternate OpenAPI paths (firmware/cloud differences) and return the
     * first successful result. Failures are recorded in lastError().
     */
    private function firstSuccess(array $attempts, mixed $fallback = []): mixed
    {
        foreach ($attempts as $attempt) {
            [$method, $path] = $attempt;
            $data = $attempt[2] ?? [];
            $query = $attempt[3] ?? [];
            try {
                $response = $this->request($method, $path, $data, $query);
                if ((int) ($response['errorCode'] ?? 0) === 0) {
                    $this->lastError = null;

                    return $response['result'] ?? $response;
                }
                $this->lastError = $method.' '.$path.': '.(string) ($response['msg'] ?? 'API error '.$response['errorCode']);
            } catch (\Throwable $e) {
                $this->lastError = $method.' '.$path.': '.$e->getMessage();
            }
        }

        return $fallback;
    }

    private function sitePath(string $suffix = ''): string
    {
        $site = $this->siteId !== '' ? $this->siteId : $this->defaultSiteId();

        return '/sites/'.rawurlencode($site).$suffix;
    }

    private function defaultSiteId(): string
    {
        $sites = $this->sites();

        return (string) ($sites[0]['id'] ?? $sites[0]['siteId'] ?? '');
    }

    // ------------------------------------------------------------------
    // OpenAPI primitives
    // ------------------------------------------------------------------

    public function sites(): array
    {
        return (array) $this->safe('GET', '/sites', [], [], []);
    }

    public function devices(): array
    {
        return (array) $this->safe('GET', $this->sitePath('/devices'), [], [], []);
    }

    public function ports(string $deviceId): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/devices/'.rawurlencode($deviceId).'/ports')],
            ['GET', $this->sitePath('/devices/'.rawurlencode($deviceId))],
        ]);
    }

    public function wlans(): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/wlans')],
            ['GET', $this->sitePath('/wlan/ssids')],
            ['GET', $this->sitePath('/ssids')],
        ]);
    }

    public function updateSsid(string $wlanId, string $ssid): bool
    {
        $id = rawurlencode($wlanId);

        return $this->firstSuccess([
            ['PATCH', $this->sitePath('/wlans/'.$id), ['name' => $ssid]],
            ['PATCH', $this->sitePath('/wlan/ssids/'.$id), ['ssid' => $ssid, 'name' => $ssid]],
        ], null) !== null;
    }

    public function portals(): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/portals')],
            ['GET', $this->sitePath('/portal')],
        ]);
    }

    public function createPortal(array $spec): array
    {
        return (array) $this->firstSuccess([
            ['POST', $this->sitePath('/portals'), $spec],
            ['POST', $this->sitePath('/portal'), $spec],
        ]);
    }

    public function vouchers(): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/vouchers')],
            ['GET', $this->sitePath('/hotspot/vouchers')],
            ['GET', $this->sitePath('/portal/vouchers')],
        ]);
    }

    public function createVoucher(array $spec): array
    {
        return (array) $this->firstSuccess([
            ['POST', $this->sitePath('/vouchers'), $spec],
            ['POST', $this->sitePath('/hotspot/vouchers'), $spec],
        ]);
    }

    public function deleteVoucher(string $voucherId): bool
    {
        $id = rawurlencode($voucherId);

        return $this->firstSuccess([
            ['DELETE', $this->sitePath('/vouchers/'.$id)],
            ['DELETE', $this->sitePath('/hotspot/vouchers/'.$id)],
        ], null) !== null;
    }

    public function clients(): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/clients')],
            ['GET', $this->sitePath('/hotspot/clients')],
        ]);
    }

    public function disconnectClient(string $clientId): bool
    {
        $id = rawurlencode($clientId);

        return $this->firstSuccess([
            ['POST', $this->sitePath('/clients/'.$id.'/disconnect')],
            ['POST', $this->sitePath('/hotspot/clients/'.$id.'/disconnect')],
            ['POST', $this->sitePath('/clients/'.$id.'/kick')],
        ], null) !== null;
    }

    public function setClientBlocked(string $clientId, bool $blocked): bool
    {
        $id = rawurlencode($clientId);
        $data = ['block' => $blocked];

        return $this->firstSuccess([
            ['PATCH', $this->sitePath('/clients/'.$id), $data],
            ['POST', $this->sitePath('/clients/'.$id.'/block'), $data],
        ], null) !== null;
    }

    public function radiusServers(): array
    {
        return (array) $this->firstSuccess([
            ['GET', $this->sitePath('/authentication/servers')],
            ['GET', $this->sitePath('/aaa/servers')],
            ['GET', $this->sitePath('/radius/servers')],
        ]);
    }

    public function configureRadius(array $spec): bool
    {
        return $this->firstSuccess([
            ['POST', $this->sitePath('/authentication/servers'), $spec],
            ['POST', $this->sitePath('/aaa/servers'), $spec],
        ], null) !== null;
    }

    public function walledGarden(array $spec): bool
    {
        return $this->firstSuccess([
            ['POST', $this->sitePath('/portals/free-rules'), $spec],
            ['POST', $this->sitePath('/portal/free-rules'), $spec],
            ['POST', $this->sitePath('/hotspot/free-rules'), $spec],
        ], null) !== null;
    }

    public function logs(int $limit = 50): array
    {
        $rows = (array) $this->firstSuccess([
            ['GET', $this->sitePath('/logs'), [], ['limit' => $limit]],
            ['GET', $this->sitePath('/events'), [], ['limit' => $limit]],
        ]);

        return array_slice($rows, -max(1, $limit));
    }

    public function rebootDevice(string $deviceId): bool
    {
        $id = rawurlencode($deviceId);

        return $this->firstSuccess([
            ['POST', $this->sitePath('/devices/'.$id.'/reboot')],
            ['POST', $this->sitePath('/devices/'.$id.'/power/reboot')],
        ], null) !== null;
    }
}
