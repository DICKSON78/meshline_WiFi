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

    private int $timeout;

    public function __construct(array $router)
    {
        $this->router = $router;
        $this->mode = strtolower((string) ($router['omada_mode'] ?? env('OMADA_MODE', 'onprem')));
        $this->siteId = (string) ($router['omada_site_id'] ?? env('OMADA_SITE_ID', ''));
        $this->timeout = max(2, (int) env('OMADA_TIMEOUT', 10));
        $this->base = $this->mode === 'cloud'
            ? rtrim((string) env('OMADA_CLOUD_URL', 'https://omada.tplinknetwork.com'), '/').'/api/v2'
            : 'https://'.$this->host().':'.(int) ($router['omada_port'] ?? env('OMADA_PORT', 8043)).'/api/v2';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    private function host(): string
    {
        return (string) ($this->router['host'] ?? $this->router['omada_host'] ?? '');
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
                'username' => (string) ($this->router['user'] ?? $this->router['omada_user'] ?? 'admin'),
                'password' => (string) ($this->router['password'] ?? $this->router['omada_password'] ?? ''),
            ];
        $response = Http::withOptions([
            'verify' => $this->verifyTls(),
            'timeout' => $this->timeout,
        ])->acceptJson()->asJson()->post($this->base.'/login', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Omada login failed ('.$response->status().'): '.$response->body());
        }
        $json = $response->json();
        $this->token = (string) ($json['result']['token'] ?? $json['token'] ?? $response->header('X-CSRFToken') ?? '');
        $setCookie = $response->header('Set-Cookie');
        if (is_array($setCookie)) {
            $setCookie = implode('; ', $setCookie);
        }
        if ($setCookie) {
            $this->cookie = (string) preg_replace('/,.*/s', '', (string) $setCookie);
        }
        $this->authenticated = true;
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
                return $fallback;
            }

            return $response['result'] ?? $response;
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
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
        // UNCERTAIN: some firmware versions expose ports via /devices/{id}/ports.
        return (array) $this->safe('GET', $this->sitePath('/devices/'.rawurlencode($deviceId).'/ports'), [], [], []);
    }

    public function wlans(): array
    {
        return (array) $this->safe('GET', $this->sitePath('/wlans'), [], [], []);
    }

    public function updateSsid(string $wlanId, string $ssid): bool
    {
        // UNCERTAIN: some controllers use name= in a /wlans/{id} PATCH body.
        $result = $this->safe('PATCH', $this->sitePath('/wlans/'.rawurlencode($wlanId)), [
            'name' => $ssid,
        ], [], null);

        return $result !== null;
    }

    public function portals(): array
    {
        return (array) $this->safe('GET', $this->sitePath('/portals'), [], [], []);
    }

    public function createPortal(array $spec): array
    {
        // UNCERTAIN: portal schema varies (portalType, authType, ssid list).
        return (array) $this->safe('POST', $this->sitePath('/portals'), $spec, [], []);
    }

    public function vouchers(): array
    {
        return (array) $this->safe('GET', $this->sitePath('/vouchers'), [], [], []);
    }

    public function createVoucher(array $spec): array
    {
        return (array) $this->safe('POST', $this->sitePath('/vouchers'), $spec, [], []);
    }

    public function deleteVoucher(string $voucherId): bool
    {
        return $this->safe('DELETE', $this->sitePath('/vouchers/'.rawurlencode($voucherId)), [], [], null) !== null;
    }

    public function clients(): array
    {
        return (array) $this->safe('GET', $this->sitePath('/clients'), [], [], []);
    }

    public function disconnectClient(string $clientId): bool
    {
        // UNCERTAIN: TP-Link exposes kick as a POST to /clients/{id}/disconnect.
        return $this->safe('POST', $this->sitePath('/clients/'.rawurlencode($clientId).'/disconnect'), [], [], null) !== null;
    }

    public function setClientBlocked(string $clientId, bool $blocked): bool
    {
        // UNCERTAIN: block state may live under /blocked-clients on old builds.
        return $this->safe('PATCH', $this->sitePath('/clients/'.rawurlencode($clientId)), [
            'block' => $blocked,
        ], [], null) !== null;
    }

    public function radiusServers(): array
    {
        // UNCERTAIN: AAA endpoint name differs between on-prem and cloud.
        return (array) $this->safe('GET', $this->sitePath('/authentication/servers'), [], [], []);
    }

    public function configureRadius(array $spec): bool
    {
        // UNCERTAIN: AAA server object schema is firmware-dependent.
        return $this->safe('POST', $this->sitePath('/authentication/servers'), $spec, [], null) !== null;
    }

    public function walledGarden(array $spec): bool
    {
        // UNCERTAIN: Omada calls this a portal "free rule" / whitelist.
        return $this->safe('POST', $this->sitePath('/portals/free-rules'), $spec, [], null) !== null;
    }

    public function logs(int $limit = 50): array
    {
        // UNCERTAIN: log endpoint may be /logs with a "limit" query parameter.
        $rows = (array) $this->safe('GET', $this->sitePath('/logs'), [], ['limit' => $limit], []);

        return array_slice($rows, -max(1, $limit));
    }

    public function rebootDevice(string $deviceId): bool
    {
        // UNCERTAIN: confirmed on recent controller versions, absent on older.
        return $this->safe('POST', $this->sitePath('/devices/'.rawurlencode($deviceId).'/reboot'), [], [], null) !== null;
    }
}
