<?php

namespace App\Services\Payment;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Port of utils/clickpesa.js.  Deterministic key ordering + HMAC checksums,
 * a 55-minute token cache and TEST_MODE stubs are preserved from the Node app.
 */
class ClickPesaClient
{
    private Client $http;
    private ?string $token = null;
    private int $tokenExpiry = 0;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim(self::baseUrl(), '/').'/',
            'timeout' => 15,
            'http_errors' => false,
        ]);
    }

    public static function baseUrl(): string
    {
        return (string) (env('CLICKPESA_BASE_URL') ?: 'https://api.clickpesa.com/third-parties');
    }

    public static function checksumKey(): string
    {
        return (string) (env('CLICKPESA_CHECKSUM_KEY') ?? '');
    }

    public static function webhookSecret(): string
    {
        return (string) (env('CLICKPESA_WEBHOOK_SECRET') ?? '');
    }

    public static function testMode(): bool
    {
        return strtolower((string) env('CLICKPESA_TEST_MODE')) === 'true'
            || strtolower((string) env('APP_ENV')) === 'development';
    }

    /** Recursive key sort, matching JSON.stringify(canonicalize(payload)) in Node. */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }
        if (! $isList) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    public static function createChecksum(mixed $payload): ?string
    {
        $key = self::checksumKey();
        if ($key === '' || $payload === null) {
            return null;
        }

        return hash_hmac('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key);
    }

    /** Verify the checksum header/signature against the payload minus checksum fields. */
    public static function verifyWebhookChecksum(mixed $payload, ?string $received): bool
    {
        $key = self::checksumKey();
        if ($key === '' || $received === null || $received === '' || ! is_array($payload)) {
            return false;
        }
        $clean = $payload;
        unset($clean['checksum'], $clean['checksumMethod']);
        $expected = self::createChecksum($clean);

        return $expected !== null && hash_equals($expected, $received);
    }

    /** HMAC-SHA256(secret, rawBody): hex, base64 and sha256= prefixes all accepted. */
    public static function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = self::webhookSecret();
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }
        $hex = hash_hmac('sha256', $rawBody, $secret);
        $candidates = [$hex, base64_encode(hash_hmac('sha256', $rawBody, $secret, true))];
        $stripped = preg_replace('/^sha256=/i', '', $signature);
        if (is_string($stripped) && $stripped !== $signature) {
            $candidates[] = $hex;
            $candidates[] = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        }
        foreach ($candidates as $candidate) {
            if (hash_equals($candidate, $signature)) {
                return true;
            }
        }
        if (is_string($stripped)) {
            foreach ([$hex, base64_encode(hash_hmac('sha256', $rawBody, $secret, true))] as $candidate) {
                if (hash_equals($candidate, $stripped)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getToken(): string
    {
        if (self::testMode()) {
            return 'TEST_TOKEN_'.time();
        }
        if ($this->token !== null && time() * 1000 < $this->tokenExpiry) {
            return $this->token;
        }
        $clientId = (string) (env('CLICKPESA_CLIENT_ID') ?? '');
        $apiKey = (string) (env('CLICKPESA_API_KEY') ?? '');
        if ($clientId === '' || $apiKey === '') {
            throw new ClickPesaException('ClickPesa credentials not configured');
        }
        $data = $this->call('POST', 'generate-token', null, [
            'client-id' => $clientId,
            'api-key' => $apiKey,
        ], false);
        $token = $data['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new ClickPesaException('ClickPesa token response was empty');
        }
        $this->token = $token;
        $this->tokenExpiry = time() * 1000 + 55 * 60 * 1000;

        return $token;
    }

    public function previewUSSDPush(array $args): array
    {
        if (self::testMode()) {
            return [
                'activeMethods' => [['name' => 'MPESA', 'status' => 'AVAILABLE', 'fee' => 0]],
                'sender' => ['accountProvider' => 'MPESA'],
            ];
        }
        $payload = [
            'amount' => (string) $args['amount'],
            'currency' => 'TZS',
            'orderReference' => $args['orderReference'],
            'phoneNumber' => $args['phoneNumber'],
            'fetchSenderDetails' => true,
        ];
        $payload = $this->withChecksum($payload);

        return $this->call('POST', 'payments/preview-ussd-push-request', $payload, [
            'Authorization' => $this->getToken(),
        ], 30);
    }

    public function initiateUSSDPush(array $args): array
    {
        if (self::testMode()) {
            return [
                'status' => 'PROCESSING',
                'channel' => 'USSD',
                'id' => 'TEST_'.$args['orderReference'],
                'message' => 'USSD push sent (TEST MODE)',
            ];
        }
        $payload = $this->withChecksum([
            'amount' => (string) $args['amount'],
            'currency' => 'TZS',
            'orderReference' => $args['orderReference'],
            'phoneNumber' => $args['phoneNumber'],
        ]);

        return $this->call('POST', 'payments/initiate-ussd-push-request', $payload, [
            'Authorization' => $this->getToken(),
        ], 30);
    }

    public function queryPaymentStatus(string $orderReference): array
    {
        if (self::testMode()) {
            return [
                'status' => 'SUCCESS',
                'channel' => 'USSD',
                'id' => 'TEST_'.$orderReference,
                'amount' => '500',
                'phoneNumber' => '255678960706',
            ];
        }

        return $this->call('GET', 'payments/'.rawurlencode($orderReference), null, [
            'Authorization' => $this->getToken(),
        ]);
    }

    public function queryPayoutStatus(string $orderReference): array
    {
        if (self::testMode()) {
            return ['status' => 'SUCCESS', 'id' => 'TEST_'.$orderReference];
        }

        return $this->call('GET', 'payouts/'.rawurlencode($orderReference), null, [
            'Authorization' => $this->getToken(),
        ]);
    }

    public function getBalance(): array
    {
        if (self::testMode()) {
            return ['balance' => 0, 'currency' => 'TZS', 'test_mode' => true];
        }
        $token = $this->getToken();

        return $this->call('GET', 'account/balance', null, [
            'Authorization' => str_starts_with($token, 'Bearer ') ? $token : 'Bearer '.$token,
        ]);
    }

    public function previewMobilePayout(array $args): array
    {
        $payload = $this->withChecksum([
            'amount' => (int) round((float) $args['amount']),
            'currency' => 'TZS',
            'orderReference' => $args['orderReference'],
            'phoneNumber' => $args['phoneNumber'],
        ]);

        return $this->call('POST', 'payouts/preview-mobile-money-payout', $payload, [
            'Authorization' => $this->getToken(),
        ], 30);
    }

    public function createMobilePayout(array $args): array
    {
        $payload = $this->withChecksum([
            'amount' => (int) round((float) $args['amount']),
            'currency' => 'TZS',
            'orderReference' => $args['orderReference'],
            'phoneNumber' => $args['phoneNumber'],
        ]);

        return $this->call('POST', 'payouts/create-mobile-money-payout', $payload, [
            'Authorization' => $this->getToken(),
        ], 30);
    }

    public function queryAllPayments(array $filters = []): array
    {
        $query = array_filter([
            'channel' => $filters['channel'] ?? null,
            'status' => $filters['status'] ?? null,
            'startDate' => $filters['startDate'] ?? null,
            'limit' => $filters['limit'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->call('GET', 'payments/all', null, [
            'Authorization' => $this->getToken(),
        ], 30, $query);
    }

    private function withChecksum(array $payload): array
    {
        $checksum = self::createChecksum($payload);

        return $checksum === null ? $payload : $payload + ['checksum' => $checksum];
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function call(string $method, string $path, ?array $json, array $headers, int $timeout = 15, array $query = []): array
    {
        $options = [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
            'timeout' => $timeout,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new ClickPesaException('ClickPesa request failed: '.$e->getMessage());
        }
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }
        if ($response->getStatusCode() >= 400) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'ClickPesa request failed');

            throw new ClickPesaException($message, $response->getStatusCode(), $decoded);
        }

        return $decoded;
    }
}
