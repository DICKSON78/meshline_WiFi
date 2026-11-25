<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\ClickPesaClient;
use App\Services\Payment\ClickPesaException;
use App\Services\Payment\PaymentFulfillment;
use App\Support\ApiResponse;
use App\Support\Meshline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Port of controllers/clickpesaWebhookController.js — idempotent event processor. */
class ClickPesaWebhookController extends Controller
{
    private const SUPPORTED_EVENTS = [
        'PAYMENT RECEIVED', 'PAYMENT FAILED', 'PAYOUT INITIATED', 'PAYOUT REFUNDED', 'PAYOUT REVERSED',
    ];

    public function __construct(private readonly ClickPesaClient $client) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-ClickPesa-Signature')
            ?: $request->header('X-Signature')
            ?: $request->header('ClickPesa-Signature')
            ?: $request->header('X-Webhook-Signature')
            ?: $request->header('X-Checksum');
        $payload = json_decode($rawBody ?: '{}', true);
        if (! is_array($payload)) {
            return ApiResponse::json(['error' => 'Invalid payload'], 400);
        }
        $eventType = self::eventType($payload);
        $orderRef = self::orderRef($payload) ?: null;
        $providerRef = self::providerRef($payload) ?: null;
        if (! in_array($eventType, self::SUPPORTED_EVENTS, true)) {
            return ApiResponse::json(['error' => 'Unsupported ClickPesa event'], 400);
        }

        try {
            $verifiedData = $this->verifyWebhook($payload, $rawBody, $signature, $eventType, $orderRef);
        } catch (ClickPesaException $e) {
            $status = $e->statusCode === 429 ? 503 : ($e->statusCode === 0 ? 401 : $e->statusCode);
            Log::warning('ClickPesa webhook verification failed', [
                'eventType' => $eventType, 'orderRef' => $orderRef, 'status' => $status, 'error' => $e->getMessage(),
            ]);

            return ApiResponse::json([
                'error' => $status === 503 ? 'Verification temporarily unavailable' : 'Invalid ClickPesa webhook',
            ], $status);
        } catch (\Throwable $e) {
            Log::warning('ClickPesa webhook verification failed', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Invalid ClickPesa webhook'], 401);
        }

        // unique(provider_ref, event_type) makes retries safe. A processed
        // duplicate returns the exact legacy envelope without re-fulfilling.
        try {
            $existingQuery = DB::table('payment_events')->where('provider', 'clickpesa')
                ->where('event_type', $eventType);
            if ($providerRef !== null) {
                $existingQuery->where('provider_ref', $providerRef);
            } elseif ($orderRef !== null) {
                // MySQL's unique index allows several NULL provider_ref rows.
                $existingQuery->whereNull('provider_ref')->where('order_reference', $orderRef);
            }
            $existing = $providerRef !== null || $orderRef !== null ? $existingQuery->first(['id', 'status']) : null;
            if ($existing) {
                if ($existing->status === 'processed') {
                    return ApiResponse::json(['status' => 'received', 'duplicate' => true]);
                }
                $eventId = (int) $existing->id;
            }
            $eventId ??= DB::table('payment_events')->insertGetId([
                'provider' => 'clickpesa',
                'event_type' => $eventType,
                'provider_ref' => $providerRef,
                'order_reference' => $orderRef,
                'raw_payload' => Meshline::encode($payload),
                'status' => 'received',
            ]);
        } catch (\Throwable $e) {
            if ($this->isDuplicate($e) && $providerRef !== null) {
                $existing = DB::table('payment_events')->where('provider', 'clickpesa')
                    ->where('provider_ref', $providerRef)->where('event_type', $eventType)->first(['id', 'status']);
                if (! $existing) {
                    return ApiResponse::json(['error' => 'Could not locate duplicate webhook event'], 503);
                }
                if ($existing->status === 'processed') {
                    return ApiResponse::json(['status' => 'received', 'duplicate' => true]);
                }
                $eventId = (int) $existing->id;
            } else {
                Log::error('Failed to store ClickPesa webhook event', ['error' => $e->getMessage()]);

                return ApiResponse::json(['error' => 'Could not store webhook event'], 503);
            }
        }

        try {
            $this->processEvent([
                'eventId' => (int) $eventId,
                'eventType' => $eventType,
                'orderRef' => $orderRef,
                'providerRef' => $providerRef,
                'payload' => $payload,
                'verifiedData' => $verifiedData,
            ]);

            return ApiResponse::json(['status' => 'received']);
        } catch (\Throwable $e) {
            Log::error('ClickPesa webhook processing deferred for provider retry', [
                'eventId' => $eventId ?? null, 'eventType' => $eventType, 'orderRef' => $orderRef, 'error' => $e->getMessage(),
            ]);

            return ApiResponse::json(['error' => 'Webhook processing failed; retry later'], 503);
        }
    }

    private function verifyWebhook(array $payload, string $rawBody, ?string $signature, string $eventType, ?string $orderRef): array
    {
        $data = self::eventData($payload);
        $hasHmacSecret = ClickPesaClient::webhookSecret() !== '';
        $hasChecksumKey = ClickPesaClient::checksumKey() !== '';
        $hmacValid = ClickPesaClient::verifyWebhookSignature($rawBody, $signature);
        $checksum = $payload['checksum'] ?? $data['checksum'] ?? $signature;
        $checksumValid = ClickPesaClient::verifyWebhookChecksum($payload, is_string($checksum) ? $checksum : null);
        if ($hmacValid || $checksumValid) {
            return $data;
        }
        if ($hasHmacSecret || $hasChecksumKey) {
            throw new ClickPesaException('Invalid ClickPesa webhook signature/checksum', 401);
        }

        // With no signing secret configured the callback is only a trigger:
        // verify the order directly against ClickPesa before accepting it.
        if (! $orderRef) {
            throw new ClickPesaException('Webhook order reference is missing', 400);
        }
        $result = str_starts_with($eventType, 'PAYMENT ')
            ? $this->client->queryPaymentStatus($orderRef)
            : $this->client->queryPayoutStatus($orderRef);
        $providerData = self::unwrapProviderResult($result);
        $providerStatus = strtoupper((string) ($providerData['status'] ?? $providerData['paymentStatus'] ?? ''));
        if (! self::eventMatchesStatus($eventType, $providerStatus)) {
            throw new ClickPesaException('ClickPesa status does not match the webhook event', 401);
        }
        if ($eventType === 'PAYMENT RECEIVED' && self::getAmount($providerData) === null) {
            throw new ClickPesaException('ClickPesa status response did not include the settled amount', 503);
        }

        return $providerData;
    }

    private static function eventMatchesStatus(string $eventType, string $status): bool
    {
        return match ($eventType) {
            'PAYMENT RECEIVED' => in_array($status, ['SUCCESS', 'SETTLED'], true),
            'PAYMENT FAILED' => in_array($status, ['FAILED', 'CANCELLED', 'CANCELED'], true),
            'PAYOUT INITIATED' => in_array($status, ['AUTHORIZED', 'PROCESSING', 'PENDING', 'SUCCESS', 'SETTLED'], true),
            'PAYOUT REFUNDED' => $status === 'REFUNDED',
            'PAYOUT REVERSED' => $status === 'REVERSED',
            default => false,
        };
    }

    private function processEvent(array $event): void
    {
        $connect = null;
        $eventId = $event['eventId'];
        try {
            DB::transaction(function () use ($event, $eventId, &$connect): void {
                if ($event['eventType'] === 'PAYMENT RECEIVED') {
                    if (! $event['orderRef']) {
                        throw new \RuntimeException('Payment webhook has no order reference');
                    }
                    $result = PaymentFulfillment::fulfillClickPesaPayment([
                        'orderReference' => $event['orderRef'],
                        'amount' => self::getAmount($event['verifiedData']),
                        'currency' => self::getCurrency($event['verifiedData']),
                        'providerRef' => $event['providerRef'],
                    ], DB::getFacadeRoot(), true);
                    if (! empty($result['__connect'])) {
                        $connect = $result['__connect'];
                        $connect['orderReference'] = $event['orderRef'];
                    }
                } elseif ($event['eventType'] === 'PAYMENT FAILED') {
                    $details = self::eventData($event['payload']);
                    PaymentFulfillment::notifyPaymentFailure(
                        (string) $event['orderRef'],
                        (string) ($details['message'] ?? $details['failureReason'] ?? '')
                    );
                }
                // Payout events stay in payment_events. This application does
                // not create provider-side customer payouts from webhooks.
                DB::table('payment_events')->where('id', $eventId)->update([
                    'status' => 'processed',
                    'error_message' => null,
                    'processed_at' => DB::raw('NOW()'),
                ]);
            }, 3);
        } catch (\Throwable $e) {
            DB::table('payment_events')->where('id', $eventId)->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 1000),
                'processed_at' => DB::raw('NOW()'),
            ]);
            Log::error('ClickPesa webhook processing failed', [
                'eventId' => $eventId, 'eventType' => $event['eventType'], 'orderRef' => $event['orderRef'], 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        // Never hold DB locks while talking to a router.
        if ($connect !== null) {
            PaymentFulfillment::connectUserToRouterBestEffort($connect);
        }
    }

    private static function eventData(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    private static function getProviderRef(array $payload): mixed
    {
        $data = self::eventData($payload);

        return $data['paymentReference'] ?? $data['providerRef'] ?? $data['providerReference'] ?? $data['transactionId'] ?? $data['id']
            ?? $payload['paymentReference'] ?? $payload['providerRef'] ?? $payload['providerReference'] ?? $payload['id'] ?? null;
    }

    private static function orderRef(array $payload): mixed
    {
        $data = self::eventData($payload);

        return $data['orderReference'] ?? $data['order_reference'] ?? $data['merchantReference'] ?? $data['externalReference']
            ?? $payload['orderReference'] ?? $payload['order_reference'] ?? $payload['merchantReference'] ?? null;
    }

    private static function eventType(array $payload): string
    {
        return strtoupper((string) ($payload['eventType'] ?? $payload['event'] ?? $payload['type']
            ?? self::eventData($payload)['eventType'] ?? ''));
    }

    private static function getAmount(mixed $data): mixed
    {
        if (! is_array($data)) {
            return null;
        }

        return $data['collectedAmount'] ?? $data['paidAmount'] ?? $data['totalAmount'] ?? $data['amount'] ?? null;
    }

    private static function getCurrency(mixed $data): string
    {
        if (! is_array($data)) {
            return 'TZS';
        }

        return (string) ($data['collectedCurrency'] ?? $data['currency'] ?? 'TZS');
    }

    private static function unwrapProviderResult(array $result): array
    {
        $value = isset($result[0]) && is_array($result[0]) ? $result[0] : $result;
        if (isset($value['data']) && is_array($value['data'])) {
            return $value['data'];
        }

        return $value;
    }

    private function isDuplicate(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, '1062');
    }
}
