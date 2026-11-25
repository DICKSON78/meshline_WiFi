<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\ClickPesaClient;
use App\Services\Payment\PaymentFulfillment;
use App\Services\Payment\PendingGrants;
use App\Support\ApiResponse;
use App\Support\Hotbando;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Port of controllers/clickpesaController.js (legacy /api/clickpesa routes). */
class ClickPesaController extends Controller
{
    /** Internet packages/voucher gifts and reseller balance top-ups only. */
    private const PURPOSES = ['package_purchase', 'reseller_topup'];

    public function __construct(private readonly ClickPesaClient $client) {}

    public function preview(Request $request): JsonResponse
    {
        try {
            $purpose = (string) $request->input('purpose', 'package_purchase');
            if (! in_array($purpose, self::PURPOSES, true)) {
                return ApiResponse::json(['error' => 'Aina ya malipo si sahihi.'], 400);
            }
            $user = self::currentPaymentUser($request);
            if ($purpose === 'package_purchase' && ! $user && ! $request->input('gift')) {
                return ApiResponse::json(['error' => 'Ingia kwanza ili kununua kifurushi.'], 401);
            }
            if ($purpose === 'reseller_topup' && ! $user) {
                return ApiResponse::json(['error' => 'Ingia kwanza kuongeza salio la reseller.'], 401);
            }
            $phone = Hotbando::intlPhone($request->input('phoneNumber', $request->input('phone_number')));
            if (! $phone) {
                return ApiResponse::json(['error' => 'Namba ya simu si sahihi.'], 400);
            }
            $amount = (float) $request->input('amount');
            if ($purpose === 'package_purchase') {
                if (! $request->input('package_id')) {
                    return ApiResponse::json(['error' => 'Kifurushi kinahitajika.'], 400);
                }
                $amount = self::getPackageAmount($request->input('package_id'));
                if (! $amount) {
                    return ApiResponse::json(['error' => 'Kifurushi hakipo au hakitumiki.'], 400);
                }
            }
            if (! is_finite($amount) || $amount <= 0) {
                return ApiResponse::json(['error' => 'Kiasi cha malipo si sahihi.'], 400);
            }
            $orderReference = self::generateOrderRef();
            $preview = $this->client->previewUSSDPush([
                'amount' => $amount, 'phoneNumber' => $phone, 'orderReference' => $orderReference,
            ]);
            $quote = self::formatFeeQuote($preview, $amount);

            return ApiResponse::ok([...$quote, 'currency' => 'TZS']);
        } catch (\Throwable $e) {
            Log::error('ClickPesa fee preview error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => $e->getMessage() ?: 'Imeshindikana kukokotoa ada ya malipo.'], 502);
        }
    }

    public function initiatePayment(Request $request): JsonResponse
    {
        try {
            $phoneNumber = $request->input('phoneNumber');
            $purpose = (string) ($request->input('purpose') ?: 'package_purchase');
            if (! in_array($purpose, self::PURPOSES, true)) {
                return ApiResponse::json(['error' => 'Aina ya malipo si sahihi.'], 400);
            }
            $gift = $purpose === 'package_purchase' && (bool) $request->input('gift');
            $user = self::currentPaymentUser($request);
            if (! $phoneNumber) {
                return ApiResponse::json(['error' => 'Namba ya simu inahitajika'], 400);
            }
            if ($purpose === 'package_purchase' && ! $user && ! $gift) {
                return ApiResponse::json(['error' => 'Please log in first to buy a package.'], 401);
            }
            if ($purpose === 'reseller_topup' && ! $user) {
                return ApiResponse::json(['error' => 'Ingia kwanza kuongeza salio la reseller.'], 401);
            }
            $phone = Hotbando::intlPhone($phoneNumber);
            if (! $phone) {
                return ApiResponse::json(['error' => 'Namba ya simu si sahihi.'], 400);
            }
            $recipientPhone = $gift ? Hotbando::intlPhone($request->input('recipient_phone')) : null;
            if ($gift && ! $recipientPhone) {
                return ApiResponse::json(['error' => 'Weka namba sahihi ya mpokeaji wa voucher.'], 400);
            }
            $recipientUserId = null;
            if ($gift) {
                $recipient = PendingGrants::findCustomerByPhone(DB::getFacadeRoot(), $recipientPhone);
                if (! $recipient) {
                    return ApiResponse::json([
                        'error' => 'Mpokeaji hajajisajili HotBando. Mwambie ajisajili kwenye customer portal kwanza.',
                    ], 400);
                }
                $recipientUserId = (int) $recipient['id'];
            }
            $orderReference = self::generateOrderRef();
            $packageId = $request->input('package_id');
            $amount = (float) $request->input('amount');
            if ($purpose === 'package_purchase') {
                if (! $packageId) {
                    return ApiResponse::json(['error' => 'Kifurushi kinahitajika.'], 400);
                }
                $package = DB::table('packages')->where('id', $packageId)->where('is_active', 1)
                    ->first(['id', 'price', 'duration_hours', 'name']);
                if (! $package) {
                    return ApiResponse::json(['error' => 'Kifurushi hakipo'], 400);
                }
                // Always use the server-side price, never the client amount.
                $amount = (float) $package->price;
            } elseif (! $request->input('amount') || $amount <= 0) {
                return ApiResponse::json(['error' => 'Amount inahitajika'], 400);
            }

            $preview = $this->client->previewUSSDPush([
                'amount' => $amount, 'phoneNumber' => $phone, 'orderReference' => $orderReference,
            ]);
            $feeQuote = self::formatFeeQuote($preview, $amount);
            $expectedTotal = $request->input('expectedTotal');
            if ($expectedTotal !== null && $expectedTotal !== '' && abs((float) $expectedTotal - $feeQuote['total']) > 0.01) {
                return ApiResponse::json([
                    'error' => 'Ada ya malipo imebadilika. Tafadhali kagua jumla mpya kabla ya kuendelea.',
                    'quote' => [...$feeQuote, 'currency' => 'TZS'],
                ], 409);
            }

            $extraData = [
                'package_id' => $packageId ?: null,
                'package_name' => null,
                'package_duration_hours' => null,
                'gift' => $gift,
                'recipient_phone' => $recipientPhone,
                'recipient_user_id' => $recipientUserId,
                'mac_address' => $user['mac_address'] ?? $request->session()->get('mac') ?? null,
                'router_id' => $user['last_router_id'] ?? $request->session()->get('routerID') ?? null,
                'provider_fee' => $feeQuote['fee'] ?? null,
                'customer_total' => $feeQuote['total'] ?? null,
                'fee_channel' => $feeQuote['channel'] ?? null,
            ];
            if ($packageId) {
                $package = DB::table('packages')->where('id', $packageId)->first(['name', 'duration_hours']);
                if ($package) {
                    $extraData['package_name'] = $package->name;
                    $extraData['package_duration_hours'] = $package->duration_hours;
                }
            }
            DB::table('clickpesa_transactions')->insert([
                'order_reference' => $orderReference,
                'user_id' => $user['id'] ?? null,
                'amount' => $amount,
                'phone' => $phone,
                'purpose' => $purpose,
                'status' => 'PENDING',
                'extra_data' => Hotbando::encode($extraData),
            ]);
            $result = $this->client->initiateUSSDPush([
                'amount' => $amount, 'phoneNumber' => $phone, 'orderReference' => $orderReference,
            ]);
            if (($result['status'] ?? null) === 'FAILED') {
                DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->update([
                    'status' => 'FAILED',
                    'clickpesa_response' => Hotbando::encode($result),
                ]);

                return ApiResponse::json(['error' => $result['message'] ?? 'Malipo yameshindikana'], 400);
            }
            DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->update([
                'status' => $result['status'] ?? 'PROCESSING',
                'channel' => $result['channel'] ?? null,
                'clickpesa_id' => $result['id'] ?? null,
                'clickpesa_response' => Hotbando::encode($result),
            ]);

            return ApiResponse::ok([
                'orderReference' => $orderReference,
                'status' => $result['status'] ?? null,
                'channel' => $result['channel'] ?? null,
                'amount' => $amount,
                'providerFee' => $feeQuote['fee'] ?? null,
                'customerTotal' => $feeQuote['total'] ?? null,
                'message' => 'Tafadhali angalia simu yako na weka PIN yako kukamilisha malipo',
            ]);
        } catch (\Throwable $e) {
            Log::error('ClickPesa initiate payment error', ['error' => $e->getMessage()]);
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 0;

            return ApiResponse::json([
                'error' => $status === 409
                    ? 'Reference tayari imetumika. Tafadhali jaribu tena.'
                    : ($e->getMessage() ?: 'Malipo yameshindikana'),
            ], $status === 409 ? 409 : 500);
        }
    }

    public function checkStatus(Request $request, string $orderReference): JsonResponse
    {
        try {
            $tx = DB::table('clickpesa_transactions')->where('order_reference', $orderReference)
                ->first(['status', 'processed', 'purpose', 'user_id', 'extra_data', 'updated_at']);
            if (! $tx) {
                return ApiResponse::json(['error' => 'Malipo hayajapatikana'], 404);
            }
            $storedStatus = strtoupper((string) $tx->status);
            if ($tx->processed) {
                // A cheap read-only entitlement check avoids a transaction on
                // every checkout-poll while still recovering lost activations.
                if ($tx->purpose === 'package_purchase' && $tx->user_id) {
                    $user = DB::table('users')->where('id', $tx->user_id)->first(['usage_until', 'free_bytes']);
                    $hasEntitlement = $user && (
                        ($user->usage_until && date_create_immutable((string) $user->usage_until) > now())
                        || (float) ($user->free_bytes ?? 0) > 0
                    );
                    if (! $hasEntitlement) {
                        try {
                            $this->processAndActivate($orderReference);
                        } catch (\Throwable $ignored) {
                            // polling must never 500 on a recovery failure
                        }
                    }
                }
                $extra = Hotbando::decode($tx->extra_data ?? null, []);

                return ApiResponse::ok([
                    'status' => in_array($storedStatus, ['SUCCESS', 'SETTLED', 'COMPLETED'], true) ? 'completed' : $storedStatus,
                    ...(! empty($extra['voucher_code'])
                        ? ['voucher' => $extra['voucher_code'], 'message' => 'Malipo yamekamilika. Voucher yako iko tayari.']
                        : ['message' => 'Malipo yamekamilika!']),
                ]);
            }
            if (in_array($storedStatus, ['SUCCESS', 'SETTLED', 'COMPLETED'], true)) {
                return ApiResponse::ok(['status' => 'completed', 'activated' => true,
                    ...$this->processAndActivate($orderReference)]);
            }
            $ageMs = $tx->updated_at
                ? (now()->getTimestamp() - date_create_immutable((string) $tx->updated_at)->getTimestamp()) * 1000
                : -1;
            if ($ageMs >= 0 && $ageMs < 60000) {
                return ApiResponse::ok(['status' => $storedStatus ?: 'PROCESSING']);
            }
            $result = $this->client->queryPaymentStatus($orderReference);
            $paymentData = isset($result[0]) && is_array($result[0]) ? $result[0] : $result;
            $newStatus = strtoupper((string) ($paymentData['status'] ?? 'UNKNOWN'));
            DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->update([
                'status' => $newStatus,
                'clickpesa_response' => Hotbando::encode($result),
            ]);
            if (in_array($newStatus, ['SUCCESS', 'SETTLED'], true)) {
                return ApiResponse::ok(['status' => $newStatus, 'activated' => true,
                    ...$this->processAndActivate($orderReference)]);
            }

            return ApiResponse::ok(['status' => $newStatus, 'data' => $paymentData]);
        } catch (\Throwable $e) {
            Log::error('ClickPesa status check error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Imeshindikana kuangalia hali ya malipo'], 500);
        }
    }

    public function confirmPayment(Request $request, string $orderReference): JsonResponse
    {
        try {
            $tx = DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->first();
            if (! $tx) {
                return ApiResponse::json(['error' => 'Malipo hayajapatikana'], 404);
            }
            if ($tx->processed) {
                try {
                    $this->processAndActivate($orderReference);
                } catch (\Throwable $ignored) {
                    // best effort recovery on an already processed order
                }
                $extra = Hotbando::decode($tx->extra_data ?? null, []);
                if (! empty($extra['gift']) && ! empty($extra['voucher_code'])) {
                    return ApiResponse::ok([
                        'status' => 'completed', 'voucher' => $extra['voucher_code'], 'message' => 'Voucher imegeneratiwa!',
                    ]);
                }

                return ApiResponse::ok(['status' => 'completed', 'message' => 'Malipo yamekamilika!']);
            }
            $result = $this->client->queryPaymentStatus($orderReference);
            $paymentData = isset($result[0]) && is_array($result[0]) ? $result[0] : $result;
            $newStatus = strtoupper((string) ($paymentData['status'] ?? $tx->status));
            DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->update([
                'status' => $newStatus,
                'clickpesa_response' => Hotbando::encode($result),
            ]);
            if (in_array($newStatus, ['SUCCESS', 'SETTLED'], true)) {
                return ApiResponse::ok(['status' => 'completed', ...$this->processAndActivate($orderReference)]);
            }

            return ApiResponse::json([
                'success' => false, 'status' => $newStatus, 'message' => 'Malipo bado yanachakatwa',
            ]);
        } catch (\Throwable $e) {
            Log::error('ClickPesa confirm payment error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Imeshindikana kuthibitisha malipo'], 500);
        }
    }

    /** Legacy webhook: checksum (or raw-body HMAC) then activation. */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true);
            if (! is_array($payload)) {
                return ApiResponse::json(['error' => 'Invalid payload'], 400);
            }
            $signature = $request->header('X-ClickPesa-Signature');
            $valid = ClickPesaClient::verifyWebhookChecksum($payload, $signature)
                || ClickPesaClient::verifyWebhookSignature($request->getContent(), $signature);
            if (! $valid) {
                Log::warning('ClickPesa webhook invalid checksum');

                return ApiResponse::json(['error' => 'Invalid checksum'], 401);
            }
            $status = $payload['status'] ?? null;
            $orderReference = $payload['orderReference'] ?? null;
            Log::info('ClickPesa webhook received', ['orderReference' => $orderReference, 'status' => $status, 'id' => $payload['id'] ?? null]);
            if ($orderReference) {
                DB::table('clickpesa_transactions')->where('order_reference', $orderReference)->update([
                    'status' => $status ?: 'UNKNOWN',
                    'channel' => $payload['channel'] ?? null,
                    'clickpesa_id' => $payload['id'] ?? null,
                    'clickpesa_response' => Hotbando::encode($payload),
                ]);
                if (in_array($status, ['SUCCESS', 'SETTLED'], true)) {
                    $this->processAndActivate((string) $orderReference);
                }
            }

            return ApiResponse::json(['status' => 'received']);
        } catch (\Throwable $e) {
            Log::error('ClickPesa webhook error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Webhook processing failed'], 500);
        }
    }

    public function getTransactions(): JsonResponse
    {
        try {
            $rows = DB::table('clickpesa_transactions')->orderByDesc('created_at')->limit(50)->get();

            return ApiResponse::ok(['transactions' => $rows]);
        } catch (\Throwable $e) {
            Log::error('ClickPesa get transactions error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Imeshindikana kupata muhtasari'], 500);
        }
    }

    public function getBalance(): JsonResponse
    {
        try {
            return ApiResponse::ok(['balance' => $this->client->getBalance()]);
        } catch (\Throwable $e) {
            Log::error('ClickPesa balance error', ['error' => $e->getMessage()]);

            return ApiResponse::json(['error' => 'Imeshindikana kupata salio'], 500);
        }
    }

    public function processAndActivate(string $orderReference): array
    {
        return PaymentFulfillment::processAndActivate($orderReference);
    }

    private static function currentPaymentUser(Request $request): ?array
    {
        foreach (['hotbando_user', 'user', 'reseller_user', 'admin_user'] as $key) {
            $user = $request->session()->get($key);
            if (is_array($user)) {
                return $user;
            }
        }

        return null;
    }

    private static function generateOrderRef(string $prefix = 'HB'): string
    {
        return $prefix.strtoupper(base_convert((string) (time() * 1000), 10, 36))
            .strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4));
    }

    private static function normalizeProvider(mixed $value): string
    {
        $provider = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '');
        if (in_array($provider, ['VODACOM', 'MPESA', 'MPESATZ', 'SAFARICOM'], true)) {
            return 'MPESA';
        }
        if (in_array($provider, ['TIGO', 'YAS', 'MIXX', 'MIXXBYYAS', 'TIGOPESA'], true)) {
            return 'TIGOPESA';
        }
        if (in_array($provider, ['AIRTEL', 'AIRTELMONEY'], true)) {
            return 'AIRTELMONEY';
        }
        if (in_array($provider, ['HALOTEL', 'HALOPESA'], true)) {
            return 'HALOPESA';
        }

        return $provider;
    }

    /** Choose the disclosed mobile-money fee from the provider's preview. */
    private static function formatFeeQuote(mixed $preview, float $amount): array
    {
        $response = is_array($preview) && isset($preview['data']) && is_array($preview['data']) ? $preview['data'] : $preview;
        if (! is_array($response)) {
            $response = [];
        }
        $methods = $response['activeMethods'] ?? [];
        if (! is_array($methods)) {
            $methods = [];
        }
        $senderProvider = self::normalizeProvider(
            $response['sender']['accountProvider'] ?? $response['sender']['provider'] ?? $response['accountProvider'] ?? null
        );
        $available = array_values(array_filter($methods, static fn ($m) => is_array($m)
            && in_array(strtoupper((string) ($m['status'] ?? '')), ['AVAILABLE', 'ACTIVE', 'ENABLED'], true)));
        $selected = null;
        foreach ($available as $method) {
            if (self::normalizeProvider($method['name'] ?? $method['provider'] ?? null) === $senderProvider) {
                $selected = $method;
                break;
            }
        }
        if ($selected === null && count($available) === 1) {
            $selected = $available[0];
        }
        if ($selected === null) {
            Log::warning('ClickPesa could not match a mobile money method', [
                'senderProvider' => $senderProvider ?: 'unknown',
                'availableMethods' => array_map(static fn ($m) => $m['name'] ?? $m['provider'] ?? 'unknown', $available),
            ]);
            throw new \RuntimeException(count($available)
                ? 'ClickPesa imeshindwa kutambua mtandao wa namba hii. Hakikisha umeweka namba ya simu ya mobile money inayotumika Tanzania, kisha ujaribu tena.'
                : 'ClickPesa haijaonyesha njia ya malipo inayopatikana kwa sasa. Hakikisha namba inatumika na ujaribu tena baada ya muda mfupi.');
        }
        $fee = (float) ($selected['fee'] ?? 0);

        return [
            'amount' => $amount,
            'fee' => $fee,
            'total' => $amount + $fee,
            'channel' => $selected['name'] ?? ($preview['sender']['accountProvider'] ?? null),
        ];
    }

    private static function getPackageAmount(mixed $packageId): ?float
    {
        if (! $packageId) {
            return null;
        }
        $row = DB::table('packages')->where('id', $packageId)->where('is_active', 1)->first(['price']);

        return $row ? (float) $row->price : null;
    }
}
