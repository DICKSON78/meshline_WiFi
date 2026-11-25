<?php

namespace App\Services\Payment;

use App\Services\Routers\RouterManager;
use App\Support\Meshline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Port of services/paymentFulfillment.js + paymentReconciler.js +
 * withdrawalPayouts.js.  All money movement happens inside a FOR UPDATE
 * transaction; router access always happens after the commit with a hard cap.
 */
class PaymentFulfillment
{
    private const ROUTER_CONNECT_TIMEOUT_MS = 12000;
    private const PAID_STATUSES = ['SUCCESS', 'SETTLED', 'COMPLETED'];

    /** Scheduler entry point: activate one order and return the fulfillment result. */
    public static function processAndActivate(string $orderReference): array
    {
        $result = [];
        $connect = null;
        DB::transaction(function () use ($orderReference, &$result, &$connect): void {
            $tx = DB::table('clickpesa_transactions')->where('order_reference', $orderReference)
                ->lockForUpdate()->first();
            if (! $tx) {
                throw new \RuntimeException("ClickPesa order not found: {$orderReference}");
            }
            $result = self::fulfillClickPesaPayment([
                'orderReference' => $orderReference,
                'amount' => $tx->amount,
                'currency' => 'TZS',
                'providerRef' => $tx->clickpesa_id,
            ], DB::getFacadeRoot(), true);
            if (! empty($result['__connect'])) {
                $connect = $result['__connect'];
                $connect['orderReference'] = $orderReference;
            }

            // Polling an already processed order must not become a free-package
            // fountain: only re-grant when there is no active entitlement and
            // no prior activated payment for the same package.
            if (! empty($result['alreadyProcessed']) && $tx->user_id && $tx->purpose === 'package_purchase') {
                try {
                    $extra = Meshline::decode($tx->extra_data ?? null, []);
                    if (! empty($extra['package_id'])) {
                        $user = DB::table('users')->where('id', $tx->user_id)->lockForUpdate()->first();
                        $now = now();
                        $hasActive = $user && $user->usage_until && date_create_immutable((string) $user->usage_until) > $now;
                        $applied = DB::table('payments')->where('user_id', $tx->user_id)
                            ->where('package_id', $extra['package_id'])->where('activated', 1)
                            ->orderByDesc('id')->exists();
                        if (! $hasActive && ! $applied) {
                            $activation = self::activatePackage((int) $tx->user_id, (int) $extra['package_id'], (float) $tx->amount, $extra, DB::getFacadeRoot());
                            if (! empty($activation['connect'])) {
                                $connect = $activation['connect'];
                                $connect['orderReference'] = $orderReference;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Recovery activation failed', ['error' => $e->getMessage(), 'orderReference' => $orderReference]);
                }
            }
        }, 3);

        unset($result['__connect']);
        if ($connect !== null) {
            self::connectUserToRouterBestEffort($connect);
        }

        return $result;
    }

    /** Own-transaction entry point used by the reconciler and dashboard fallback. */
    public static function fulfillOrder(string $orderReference, array $payment = []): array
    {
        $result = [];
        $connect = null;
        try {
            DB::transaction(function () use ($orderReference, $payment, &$result, &$connect): void {
                $result = self::fulfillClickPesaPayment($payment + ['orderReference' => $orderReference], DB::getFacadeRoot(), true);
            }, 3);
        } catch (\Throwable $e) {
            Log::error('Fulfill order failed', ['orderReference' => $orderReference, 'error' => $e->getMessage()]);
            throw $e;
        }
        if (! empty($result['__connect'])) {
            $connect = $result['__connect'];
            unset($result['__connect']);
            $connect['orderReference'] = $orderReference;
        }
        if ($connect !== null) {
            self::connectUserToRouterBestEffort($connect);
        }

        return $result;
    }

    /**
     * Core idempotent fulfillment.  Caller must hold the transaction/lock.
     *
     * @param  mixed  $executor  a DB connection or the DB facade
     */
    public static function fulfillClickPesaPayment(array $payment, mixed $executor = null, bool $inTransaction = false): array
    {
        $db = $executor ?? DB::getFacadeRoot();
        $orderReference = (string) ($payment['orderReference'] ?? '');
        $tx = $db->table('clickpesa_transactions')->where('order_reference', $orderReference)
            ->lockForUpdate()->first();
        if (! $tx) {
            throw new \RuntimeException('Order not found');
        }
        if ($tx->processed) {
            return ['alreadyProcessed' => true];
        }

        $purpose = (string) ($tx->purpose ?? 'payment');
        $userId = $tx->user_id ? (int) $tx->user_id : null;
        $extra = Meshline::decode($tx->extra_data ?? null, []);
        if (! is_array($extra)) {
            $extra = [];
        }

        $orderAmount = (float) $tx->amount;
        $paidAmount = isset($payment['amount']) && $payment['amount'] !== null
            ? (float) $payment['amount'] : $orderAmount;
        $payerTotal = (float) ($extra['customer_total'] ?? $orderAmount);
        $orderCurrency = strtoupper((string) ($tx->currency ?? 'TZS'));
        $paidCurrency = strtoupper((string) ($payment['currency'] ?? $orderCurrency));
        if (abs($paidAmount - $orderAmount) > 0.01 && abs($paidAmount - $payerTotal) > 0.01) {
            throw new \RuntimeException('Amount mismatch');
        }
        if ($paidCurrency !== 'TZS' || $orderCurrency !== $paidCurrency) {
            throw new \RuntimeException('Currency mismatch');
        }

        $result = [];
        $connect = null;
        if ($purpose === 'package_purchase' && $userId) {
            $packageId = isset($extra['package_id']) ? (int) $extra['package_id'] : null;
            $isGift = ! empty($extra['gift']);
            if ($isGift && ! empty($extra['recipient_user_id'])) {
                self::giftToUser($db, (int) $extra['recipient_user_id'], (int) $packageId, $orderReference, $tx->phone, $orderAmount);
                $result = [
                    'gifted' => true,
                    'message' => 'Kifurushi kimetumwa kwa mpokeaji. Kitaanza kuhesabu mara atakapounganishwa WiFi ya Meshline WiFi.',
                ];
            } elseif ($isGift) {
                $result = self::issueVoucher($db, $orderReference, $packageId, $extra, $tx, $orderAmount);
            } elseif ($packageId) {
                $activation = self::activatePackage($userId, $packageId, $orderAmount, $extra, $db);
                if (! empty($activation['connect'])) {
                    $connect = $activation['connect'];
                }
                $result = [
                    'activated' => true,
                    'message' => 'Kifurushi chako kimewashwa. Unganisha tena au refresh Wi-Fi ili kuanza kutumia.',
                ];
            }
        } elseif ($purpose === 'package_purchase' && ! empty($extra['gift']) && ! empty($extra['recipient_user_id']) && ! empty($extra['package_id'])) {
            self::giftToUser($db, (int) $extra['recipient_user_id'], (int) $extra['package_id'], $orderReference, $tx->phone, $orderAmount);
            $result = [
                'gifted' => true,
                'message' => 'Kifurushi kimetumwa kwa mpokeaji. Kitaanza kuhesabu mara atakapounganishwa WiFi ya Meshline WiFi.',
            ];
        } elseif ($purpose === 'package_purchase' && ! empty($extra['gift']) && ! $userId) {
            if (empty($extra['package_id'])) {
                throw new \RuntimeException('Voucher purchase has no package');
            }
            $result = self::issueVoucher($db, $orderReference, (int) $extra['package_id'], $extra, $tx, $orderAmount);
        } elseif ($purpose === 'reseller_topup' && $userId) {
            self::creditWallet($userId, $orderAmount, $orderReference, 'Reseller wallet top-up via ClickPesa', $db);
            // The reseller portal spends resellers.balance, so the confirmed
            // ClickPesa deposit must land there as well as in the wallet ledger.
            $credited = $db->table('resellers')->where('user_id', $userId)->update([
                'balance' => DB::raw('balance + '.number_format($orderAmount, 2, '.', '')),
            ]);
            if (! $credited) {
                throw new \RuntimeException('Reseller row not found for top-up');
            }
            $result = ['message' => 'Salio la wallet limeongezwa!'];
        }

        $txUpdate = ['status' => 'SUCCESS', 'processed' => 1];
        if (! empty($payment['providerRef'])) {
            $txUpdate['clickpesa_id'] = (string) $payment['providerRef'];
        }
        $db->table('clickpesa_transactions')->where('order_reference', $orderReference)->update($txUpdate);

        if ($userId) {
            $isGift = $purpose === 'package_purchase' && ! empty($extra['gift']);
            $title = ! empty($result['gifted']) ? 'Zawadi imetumwa' : ($isGift ? 'Vocha yako iko tayari' : 'Malipo yamefanikiwa');
            $amountLabel = number_format($orderAmount);
            $details = ! empty($result['gifted'])
                ? "Malipo ya TSh {$amountLabel} yamepokelewa. {$result['message']}"
                : (! empty($result['voucher'])
                    ? "Malipo ya TSh {$amountLabel} yamepokelewa. Vocha yako: {$result['voucher']}"
                    : ($purpose === 'package_purchase'
                        ? "Malipo ya TSh {$amountLabel} yamepokelewa na kifurushi kimewashwa."
                        : "Malipo ya TSh {$amountLabel} yamepokelewa na fedha zimeongezwa kwenye wallet yako."));
            $db->table('notifications')->insert([
                'user_id' => $userId,
                'notification_type' => 'success',
                'title' => $title,
                'message' => $details,
                'action_url' => $isGift ? '/hotspot/subscribe' : '/hotspot/dashboard',
                'action_text' => $isGift ? 'Tazama vocha' : 'Fungua dashboard',
                'created_at' => DB::raw('NOW()'),
            ]);
        }

        try {
            $db->table('payment_ledger')->insert([
                'customer_id' => $userId,
                'order_reference' => $orderReference,
                'clickpesa_transaction_id' => $tx->id,
                'type' => 'payment',
                'amount' => $tx->amount,
                'currency' => $orderCurrency,
                'status' => 'completed',
                'provider' => 'clickpesa',
                'provider_ref' => $payment['providerRef'] ?? null,
                'description' => $purpose,
                'metadata' => Meshline::encode(['extra' => $extra]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to insert into payments ledger', ['error' => $e->getMessage()]);
        }

        if (! empty($result['voucher'])) {
            try {
                $stored = $db->table('clickpesa_transactions')->where('order_reference', $orderReference)->first(['extra_data']);
                $storedExtra = Meshline::decode($stored->extra_data ?? null, []);
                if (! empty($storedExtra['voucher_code'])) {
                    $result['voucher'] = $storedExtra['voucher_code'];
                }
            } catch (\Throwable $ignored) {
                // keep the in-memory voucher code
            }
        }
        if ($connect !== null) {
            $result['__connect'] = $connect;
        }

        return $result;
    }

    /** Port of utils/packageEntitlement.js caller in paymentFulfillment. */
    public static function activatePackage(int $userId, int $packageId, float $amount, array $extra = [], mixed $executor = null): array
    {
        $db = $executor ?? DB::getFacadeRoot();
        $pkg = $db->table('packages')->where('id', $packageId)->first();
        $user = $db->table('users')->where('id', $userId)->first();
        if (! $pkg || ! $user) {
            return [];
        }
        $now = now();
        $entitlement = PackageEntitlement::extendUserPackage($db, $userId, (array) $pkg);
        $db->table('users')->where('id', $userId)->update([
            'moneyspent' => DB::raw('COALESCE(moneyspent, 0) + '.number_format($amount, 2, '.', '')),
            'updated_at' => $now,
        ]);
        $db->table('payments')->insert([
            'user_id' => $userId,
            'package_id' => $packageId,
            'amount' => $amount,
            'payment_method' => 'clickpesa',
            'status' => 'completed',
            'activated' => 1,
            'created_at' => DB::raw('NOW()'),
        ]);
        Log::info('Package activated', ['user' => $userId, 'package' => $pkg->name, 'until' => $entitlement['usageUntil']->format('c')]);

        $mac = $extra['mac_address'] ?? $user->mac_address ?? null;
        $routerId = $extra['router_id'] ?? $user->last_router_id ?? null;
        $connect = ($mac && $routerId) ? [
            'mac' => (string) $mac,
            'routerID' => (string) $routerId,
            'uptimeSeconds' => max(0, $entitlement['usageUntil']->getTimestamp() - $now->getTimestamp()),
            'bytes' => 0,
        ] : null;

        return $connect === null ? [] : ['connect' => $connect];
    }

    public static function creditWallet(int $userId, float $amount, string $orderReference, string $description, mixed $executor = null): void
    {
        $db = $executor ?? DB::getFacadeRoot();
        $db->table('wallets')->upsert([['user_id' => $userId, 'balance' => 0, 'total_deposited' => 0]], ['user_id'], ['user_id']);
        $wallet = $db->table('wallets')->where('user_id', $userId)->lockForUpdate()->first(['id', 'balance']);
        if (! $wallet) {
            throw new \RuntimeException("Wallet could not be created for user {$userId}");
        }
        $before = (float) ($wallet->balance ?? 0);
        $after = $before + $amount;
        $db->table('wallets')->where('id', $wallet->id)->update([
            'balance' => $after,
            'total_deposited' => DB::raw('total_deposited + '.number_format($amount, 2, '.', '')),
            'updated_at' => DB::raw('NOW()'),
        ]);
        $db->table('transactions')->insert([
            'wallet_id' => $wallet->id,
            'user_id' => $userId,
            'transaction_type' => 'deposit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'currency' => 'TZS',
            'reference_type' => 'payment',
            'description' => $description,
            'status' => 'completed',
            'processed_at' => DB::raw('NOW()'),
            'payment_method' => 'system',
            'payment_reference' => $orderReference,
            'created_at' => DB::raw('NOW()'),
        ]);
    }

    private static function giftToUser(mixed $db, int $recipientId, int $packageId, string $orderReference, ?string $buyerPhone, float $amount): void
    {
        PendingGrants::createGiftGrant($db, $recipientId, $packageId, $orderReference, $buyerPhone);
        $db->table('notifications')->insert([
            'user_id' => $recipientId,
            'notification_type' => 'success',
            'title' => 'Umepewa kifurushi',
            'message' => 'Umepewa kifurushi chenye thamani ya TSh '.number_format($amount).'. Kitaanza kuhesabu ukiunganishwa WiFi ya Meshline WiFi.',
            'action_url' => '/hotspot/dashboard',
            'action_text' => 'Fungua dashboard',
            'created_at' => DB::raw('NOW()'),
        ]);
    }

    private static function issueVoucher(mixed $db, string $orderReference, int $packageId, array $extra, object $tx, float $amount): array
    {
        $code = self::generateVoucherCode();
        $db->table('vouchers')->insert([
            'voucher_code' => $code,
            'package_id' => $packageId,
            'price' => $amount,
            'is_used' => 0,
            'expires_at' => now()->addDays(30),
            'recipient_phone' => $extra['recipient_phone'] ?? null,
            'purchased_by_phone' => $tx->phone ?? null,
            'payment_order_reference' => $orderReference,
            'created_at' => DB::raw('NOW()'),
        ]);
        $extra['voucher_code'] = $code;
        $db->table('clickpesa_transactions')->where('order_reference', $orderReference)
            ->update(['extra_data' => Meshline::encode($extra)]);

        return ['voucher' => $code, 'message' => 'Voucher imegeneratiwa!'];
    }

    public static function generateVoucherCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $group = static function () use ($chars): string {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $out .= $chars[random_int(0, strlen($chars) - 1)];
            }

            return $out;
        };

        return 'HB-'.$group().'-'.$group().'-'.$group();
    }

    /**
     * RouterOS auto-connect: fire-and-forget AFTER commit, hard 12s cap.
     * A slow router must never hold DB locks or stall an HTTP response.
     */
    public static function connectUserToRouterBestEffort(array $connect): bool
    {
        $mac = $connect['mac'] ?? null;
        $routerId = $connect['routerID'] ?? null;
        if (! $mac || ! $routerId) {
            return false;
        }
        $previousTimeout = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) intdiv(self::ROUTER_CONNECT_TIMEOUT_MS, 1000));
        $alarmSet = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): void {
                throw new \RuntimeException('Router connect timed out');
            });
            pcntl_alarm((int) ceil(self::ROUTER_CONNECT_TIMEOUT_MS / 1000));
            $alarmSet = true;
        }
        try {
            if (! class_exists(RouterManager::class)) {
                return false;
            }
            // Never invent a router id: only active, registered routers.
            $active = DB::table('mikrotiks')->where('is_active', 1)
                ->where(function ($q) use ($routerId) {
                    $q->where('id', $routerId)->orWhere('router_id', (string) $routerId);
                })->first();
            if (! $active) {
                return false;
            }
            $manager = app(RouterManager::class);
            $session = $manager->connectById($routerId);
            if (! $session) {
                return false;
            }
            $session->driver->grantAccess($session, (string) $mac, [
                'uptime_seconds' => (int) ($connect['uptimeSeconds'] ?? 0),
                'bytes' => (int) ($connect['bytes'] ?? 0),
            ], (bool) ($connect['preserveBytes'] ?? false));
            Log::info('Auto-connected to router after fulfillment', [
                'mac' => $mac, 'router' => $routerId, 'orderReference' => $connect['orderReference'] ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Auto-connect after payment fulfillment failed (non-critical)', [
                'error' => $e->getMessage(), 'mac' => $mac, 'router' => $routerId,
            ]);

            return false;
        } finally {
            if ($alarmSet) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, SIG_DFL);
            }
            if ($previousTimeout !== false) {
                @ini_set('default_socket_timeout', (string) $previousTimeout);
            }
        }
    }

    /** Scheduler entry point: activate paid-but-unprocessed orders with a daily API budget. */
    public static function reconcile(): int
    {
        static $lastRunAt = 0;
        static $checksByOrder = [];
        static $budgetDay = '';
        static $budgetUsed = 0;
        $runEveryMs = 10 * 60 * 1000;
        $maxChecks = 4;
        $dailyBudget = 20;
        if (microtime(true) * 1000 - $lastRunAt < $runEveryMs) {
            return 0;
        }
        $lastRunAt = microtime(true) * 1000;
        $today = gmdate('Y-m-d');
        if ($today !== $budgetDay) {
            $budgetDay = $today;
            $budgetUsed = 0;
        }
        $rows = DB::table('clickpesa_transactions')
            ->where('processed', 0)
            ->whereIn('purpose', ['package_purchase', 'reseller_topup'])
            ->whereNotIn('status', ['FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED', 'REFUNDED'])
            ->where('created_at', '>', DB::raw('DATE_SUB(NOW(), INTERVAL 1 DAY)'))
            ->orderBy('updated_at')->limit(2)->get();
        $activated = 0;
        $client = new ClickPesaClient();
        foreach ($rows as $row) {
            $ref = (string) $row->order_reference;
            $checks = (int) ($checksByOrder[$ref] ?? 0);
            if ($checks >= $maxChecks || $budgetUsed >= $dailyBudget) {
                continue;
            }
            $checksByOrder[$ref] = $checks + 1;
            $budgetUsed++;
            try {
                $result = $client->queryPaymentStatus($ref);
                $data = isset($result[0]) && is_array($result[0]) ? $result[0] : $result;
                $status = strtoupper((string) ($data['status'] ?? ''));
                if ($status === '') {
                    continue;
                }
                DB::table('clickpesa_transactions')->where('order_reference', $ref)->update([
                    'status' => $status,
                    'clickpesa_response' => Meshline::encode($result),
                ]);
                if (in_array($status, self::PAID_STATUSES, true)) {
                    self::processAndActivate($ref);
                    $activated++;
                    Log::info('Payment activated by reconciler', ['orderReference' => $ref, 'status' => $status]);
                }
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '429') || $e instanceof ClickPesaException && $e->statusCode === 429) {
                    $checksByOrder[$ref] = $checks;
                    Log::warning('Payment checks paused by provider rate limit, retrying next run');
                    break;
                }
                Log::warning('Payment check failed', ['orderReference' => $ref, 'error' => $e->getMessage()]);
            }
        }

        return $activated;
    }

    /** Scheduler entry point: send queued reseller withdrawals via mobile money payouts. */
    public static function processPayouts(): int
    {
        static $lastCreateAt = 0;
        self::reconcileSentPayouts();
        if (microtime(true) * 1000 - $lastCreateAt < 60 * 1000) {
            return 0;
        }
        $withdrawal = DB::table('reseller_withdrawals')->where('status', 'pending')
            ->whereNotNull('order_reference')->orderBy('id')->first();
        if (! $withdrawal) {
            return 0;
        }
        if ((int) ($withdrawal->attempts ?? 0) >= 20) {
            DB::table('reseller_withdrawals')->where('id', $withdrawal->id)
                ->where('status', 'pending')->update(['status' => 'sending']);
            self::markPayoutFailed($withdrawal, 'Too many attempts');

            return 1;
        }
        $claimed = DB::table('reseller_withdrawals')->where('id', $withdrawal->id)
            ->where('status', 'pending')->update([
                'status' => 'sending',
                'attempts' => DB::raw('attempts + 1'),
            ]);
        if (! $claimed) {
            return 0;
        }
        self::sendPayout((array) $withdrawal, $lastCreateAt);

        return 1;
    }

    private static function reconcileSentPayouts(): void
    {
        $row = DB::table('reseller_withdrawals')->where('status', 'sent')
            ->where('sent_at', '<', DB::raw('DATE_SUB(NOW(), INTERVAL 2 MINUTE)'))
            ->orderBy('sent_at')->first();
        if (! $row) {
            return;
        }
        try {
            self::checkPayout((array) $row);
        } catch (\Throwable $e) {
            Log::warning('Payout status check failed', ['withdrawal' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    public static function normalizePhone(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $input) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '255'.substr($digits, 1);
        }

        return preg_match('/^255\d{9}$/', $digits) ? $digits : null;
    }

    private static function sendPayout(array $withdrawal, int &$lastCreateAt): void
    {
        $phone = self::normalizePhone((string) ($withdrawal['payment_phone'] ?? ''));
        if (! $phone) {
            self::markPayoutFailed($withdrawal, 'Invalid mobile money number');

            return;
        }
        $params = [
            'amount' => $withdrawal['payout_amount'],
            'phoneNumber' => $phone,
            'orderReference' => $withdrawal['order_reference'],
        ];
        $client = new ClickPesaClient();
        try {
            $client->previewMobilePayout($params);
        } catch (\Throwable $e) {
            $status = $e instanceof ClickPesaException ? $e->statusCode : 0;
            $message = self::errorMessage($e);
            if ($status === 0 || $status >= 500 || $status === 404 || preg_match('/insufficient|in progress|retry after/i', $message)) {
                self::returnPayoutToPending($withdrawal, $message);
            } else {
                self::markPayoutFailed($withdrawal, $message);
            }

            return;
        }
        $lastCreateAt = (int) (microtime(true) * 1000);
        try {
            $created = $client->createMobilePayout($params);
            DB::table('reseller_withdrawals')->where('id', $withdrawal['id'])->update([
                'status' => 'sent',
                'provider_ref' => substr((string) ($created['id'] ?? $withdrawal['order_reference']), 0, 64),
                'sent_at' => DB::raw('NOW()'),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $status = $e instanceof ClickPesaException ? $e->statusCode : 0;
            $message = self::errorMessage($e);
            if ($status === 409) {
                try {
                    self::checkPayout($withdrawal);

                    return;
                } catch (\Throwable $ignored) {
                    self::returnPayoutToPending($withdrawal, 'Could not confirm earlier payout, will retry');

                    return;
                }
            }
            if ($status === 0 || $status >= 500 || preg_match('/in progress|retry after|insufficient/i', $message)) {
                self::returnPayoutToPending($withdrawal, $message);

                return;
            }
            self::markPayoutFailed($withdrawal, $message);
        }
    }

    private static function checkPayout(array $withdrawal): void
    {
        $client = new ClickPesaClient();
        $state = $client->queryPayoutStatus((string) $withdrawal['order_reference']);
        $status = strtoupper((string) ($state['status'] ?? ''));
        if ($status === 'SUCCESS') {
            DB::table('reseller_withdrawals')->where('id', $withdrawal['id'])
                ->update(['status' => 'processed', 'last_error' => null]);
        } elseif ($status === 'AUTHORIZED') {
            DB::table('reseller_withdrawals')->where('id', $withdrawal['id'])
                ->update(['status' => 'sent', 'last_error' => null]);
        } elseif ($status === 'REVERSED') {
            self::markPayoutFailed($withdrawal, 'Payout reversed by provider');
        } else {
            self::returnPayoutToPending($withdrawal, 'Payout status unknown, will retry');
        }
    }

    private static function errorMessage(\Throwable $e): string
    {
        return substr($e->getMessage(), 0, 255);
    }

    private static function markPayoutFailed(array $withdrawal, string $message): void
    {
        $updated = DB::table('reseller_withdrawals')->where('id', $withdrawal['id'])
            ->whereIn('status', ['sending', 'sent'])
            ->update(['status' => 'failed', 'last_error' => substr($message, 0, 255)]);
        if (! $updated) {
            return;
        }
        // Refund only after the status change succeeds: a failed payout is never lost.
        DB::table('resellers')->where('id', $withdrawal['reseller_id'])
            ->update(['balance' => DB::raw('COALESCE(balance, 0) + '.number_format((float) ($withdrawal['amount'] ?? 0), 2, '.', ''))]);
        Log::warning('Withdrawal payout failed, balance refunded', ['withdrawal' => $withdrawal['id'], 'message' => $message]);
    }

    private static function returnPayoutToPending(array $withdrawal, string $message): void
    {
        DB::table('reseller_withdrawals')->where('id', $withdrawal['id'])->where('status', 'sending')
            ->update(['status' => 'pending', 'last_error' => substr($message, 0, 255)]);
    }

    /** Public so the webhook can mark failed orders and notify the buyer. */
    public static function notifyPaymentFailure(string $orderReference, ?string $reason = null): void
    {
        DB::transaction(function () use ($orderReference, $reason): void {
            $tx = DB::table('clickpesa_transactions')->where('order_reference', $orderReference)
                ->lockForUpdate()->first(['user_id']);
            DB::table('clickpesa_transactions')->where('order_reference', $orderReference)
                ->update(['status' => 'FAILED']);
            if ($tx && $tx->user_id) {
                DB::table('notifications')->insert([
                    'user_id' => $tx->user_id,
                    'notification_type' => 'error',
                    'title' => 'Malipo hayajakamilika',
                    'message' => $reason
                        ? 'ClickPesa haijakamilisha malipo: '.substr($reason, 0, 300)
                        : 'ClickPesa haijakamilisha malipo. Tafadhali jaribu tena.',
                    'action_url' => '/hotspot/subscribe',
                    'action_text' => 'Jaribu tena',
                    'created_at' => DB::raw('NOW()'),
                ]);
            }
        }, 3);
    }
}
