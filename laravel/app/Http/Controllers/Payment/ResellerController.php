<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentFulfillment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Port of controllers/resellerController.js — the reseller portal under /api/reseller. */
class ResellerController extends Controller
{
    private const MIN_RESELLER_SALE_PRICE = 500;
    private const WITHDRAWAL_FEE_RATE = 0.10;
    private const PACKAGE_VALUES = [
        'MASAA 6' => ['id' => 1, 'price' => 500],
        'MASAA 24' => ['id' => 2, 'price' => 1000],
        'WIKI 1' => ['id' => 3, 'price' => 6000],
        'MWEZI 1' => ['id' => 4, 'price' => 40000],
    ];

    public function register(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = (string) $request->input('email', '');
        $phone = (string) $request->input('phone', '');
        $password = (string) $request->input('password', '');
        if ($name === '') {
            return $this->fail('Jina linahitajika');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Barua pepe sio sahihi');
        }
        if (! preg_match('/^[0-9]{9,15}$/', $phone)) {
            return $this->fail('Namba ya simu sio sahihi');
        }
        if (strlen($password) < 6) {
            return $this->fail('Neno la siri linahitajika (angalau herufi 6)');
        }
        try {
            $exists = DB::table('users')->where('email', $email)->orWhere('phone_number', $phone)
                ->where('role', 'reseller')->exists();
            if ($exists) {
                return $this->fail('Barua pepe au namba ya simu tayari imesajiliwa kama reseller');
            }
            $userId = DB::table('users')->insertGetId([
                'name' => $name,
                'phone_number' => $phone,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'reseller',
                'location_id' => $request->input('location_id') ?: null,
                'is_active' => 1,
                'is_verified' => 0,
                'created_at' => DB::raw('NOW()'),
                'updated_at' => DB::raw('NOW()'),
            ]);
            DB::table('resellers')->insert([
                'user_id' => $userId,
                'location_id' => $request->input('location_id') ?: null,
                'balance' => 0,
                'total_earned' => 0,
                'status' => 'active',
                'created_at' => DB::raw('NOW()'),
            ]);
            $reseller = DB::table('resellers')->where('user_id', $userId)->first();
            $this->setSession($request, [
                'id' => $userId, 'name' => $name, 'email' => $email, 'phone_number' => $phone,
                'role' => 'reseller', 'reseller_id' => $reseller->id,
                'location' => $request->input('location') ?: null,
            ]);

            return ApiResponse::success([
                'data' => [
                    'id' => $userId, 'name' => $name, 'email' => $email,
                    'phone_number' => $phone, 'reseller_id' => $reseller->id,
                ],
            ], 'Usajili umefanikiwa');
        } catch (\Throwable $e) {
            Log::error('Reseller registration failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kusajili reseller', 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        if ($email === '' || $password === '') {
            return $this->fail('Barua pepe na neno la siri vinahitajika');
        }
        try {
            $user = DB::table('users')->where('email', $email)->where('role', 'reseller')
                ->where('is_active', 1)->first();
            if (! $user || ! Hash::check($password, (string) $user->password)) {
                return $this->fail('Barua pepe au neno la siri sio sahihi', 401);
            }
            $reseller = DB::table('resellers')->where('user_id', $user->id)->where('status', 'active')->first();
            if (! $reseller) {
                return $this->fail('Akaunti yako ya reseller haipatikani au imesimamishwa', 403);
            }
            $this->setSession($request, [
                'id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email,
                'phone_number' => $user->phone_number, 'role' => 'reseller', 'reseller_id' => $reseller->id,
                'location' => $user->location ?? null,
            ]);

            return ApiResponse::success([
                'data' => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'reseller_id' => $reseller->id],
            ], 'Umefanikiwa kuingia');
        } catch (\Throwable $e) {
            Log::error('Reseller login failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kuingia', 500);
        }
    }

    public function getDashboard(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        try {
            $reseller = DB::table('resellers')->where('user_id', $uid)->first();
            if (! $reseller) {
                return $this->fail('Reseller haijapatikana', 404);
            }
            $voucherQuery = DB::table('vouchers')->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
                ->where('voucher_batches.created_by', $uid);
            $totalVouchers = (int) (clone $voucherQuery)->count();
            $soldVouchers = (int) (clone $voucherQuery)->where('vouchers.is_used', 1)->count();
            $earnings = (float) (clone $voucherQuery)->where('vouchers.is_used', 1)->sum('vouchers.price');
            $recentSales = DB::table('vouchers')
                ->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
                ->join('packages', 'vouchers.package_id', '=', 'packages.id')
                ->leftJoin('users', 'vouchers.used_by', '=', 'users.id')
                ->where('voucher_batches.created_by', $uid)->where('vouchers.is_used', 1)
                ->orderByDesc('vouchers.used_at')->limit(10)
                ->select('vouchers.voucher_code', 'packages.name as package_name', 'vouchers.price', 'vouchers.used_at', 'users.name as customer_name')
                ->get();
            $dailySales = DB::table('vouchers')
                ->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
                ->where('voucher_batches.created_by', $uid)->where('vouchers.is_used', 1)
                ->whereRaw('vouchers.used_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)')
                ->selectRaw('DATE(vouchers.used_at) as d, COUNT(*) as c')->groupByRaw('DATE(vouchers.used_at)')->get();
            $labels = [];
            $data = [];
            for ($i = 6; $i >= 0; $i--) {
                $dt = now()->subDays($i);
                $labels[] = $dt->format('D');
                $row = $dailySales->first(fn ($r) => substr((string) $r->d, 0, 10) === $dt->format('Y-m-d'));
                $data[] = $row ? (int) $row->c : 0;
            }

            return ApiResponse::ok([
                'wallet' => [
                    'balance' => (float) ($reseller->balance ?? 0),
                    'total_earned' => (float) ($reseller->total_earned ?? 0),
                ],
                'summary' => ['total' => $totalVouchers, 'redeemed' => $soldVouchers, 'earnings' => $earnings],
                'recent_sales' => $recentSales,
                'chartData' => ['labels' => $labels, 'data' => $data],
            ]);
        } catch (\Throwable $e) {
            Log::error('Reseller dashboard failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kupakua dashboard', 500);
        }
    }

    public function getVouchers(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        try {
            $status = (string) $request->query('status', '');
            $page = max(1, (int) $request->query('page', 1));
            $limit = max(1, min(500, (int) $request->query('limit', 50)));
            $query = DB::table('vouchers')
                ->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
                ->join('packages', 'vouchers.package_id', '=', 'packages.id')
                ->leftJoin('users', 'vouchers.used_by', '=', 'users.id')
                ->where('voucher_batches.created_by', $uid)
                ->select('vouchers.id', 'vouchers.voucher_code', 'packages.name as package_name', 'vouchers.price',
                    'vouchers.is_used', 'vouchers.used_at', 'vouchers.expires_at', 'vouchers.created_at',
                    'users.name as used_by_name', 'users.phone_number as used_by_phone');
            $countQuery = DB::table('vouchers')->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
                ->where('voucher_batches.created_by', $uid);
            if ($status === 'unused') {
                $query->where('vouchers.is_used', 0)->where('vouchers.expires_at', '>', DB::raw('NOW()'));
                $countQuery->where('vouchers.is_used', 0)->where('vouchers.expires_at', '>', DB::raw('NOW()'));
            } elseif ($status === 'used') {
                $query->where('vouchers.is_used', 1);
                $countQuery->where('vouchers.is_used', 1);
            } elseif ($status === 'expired') {
                $query->where('vouchers.is_used', 0)->where('vouchers.expires_at', '<=', DB::raw('NOW()'));
                $countQuery->where('vouchers.is_used', 0)->where('vouchers.expires_at', '<=', DB::raw('NOW()'));
            }
            $total = (int) (clone $countQuery)->count();
            $vouchers = (clone $query)->orderByDesc('vouchers.id')->limit($limit)->offset(($page - 1) * $limit)->get();
            $batches = DB::table('voucher_batches')->where('created_by', $uid)
                ->orderByDesc('created_at')->limit(20)
                ->select('id', 'batch_name', 'total_vouchers', 'total_value', 'created_at',
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as formatted_date"))->get();

            return ApiResponse::ok([
                'data' => $vouchers,
                'batches' => $batches,
                'pagination' => [
                    'page' => $page, 'limit' => $limit, 'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Reseller vouchers failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kupata vouchers', 500);
        }
    }

    public function generateVouchers(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $packages = $request->input('packages');
        $items = [];
        if (is_array($packages)) {
            foreach ($packages as $name => $qty) {
                $q = (int) $qty;
                if ($q > 0 && isset(self::PACKAGE_VALUES[$name])) {
                    $items[] = ['package_type' => (string) $name, 'quantity' => $q];
                }
            }
        } elseif ($request->input('package_type') && $request->input('quantity')) {
            $items[] = [
                'package_type' => (string) $request->input('package_type'),
                'quantity' => (int) $request->input('quantity'),
            ];
        }
        if ($items === []) {
            return $this->fail('Aina ya kifurushi sio sahihi. Chagua: MASAA 6, MASAA 24, WIKI 1, au MWEZI 1');
        }
        try {
            $reseller = DB::table('resellers')->where('user_id', $uid)->where('status', 'active')->first();
            if (! $reseller) {
                return $this->fail('Akaunti ya reseller haijapatikana', 404);
            }
            $salePrices = $request->input('sale_prices');
            $totalCost = 0.0;
            foreach ($items as &$item) {
                $qty = $item['quantity'];
                if ($qty <= 0) {
                    return $this->fail('Idadi ya vouchers lazima iwe nambari chanya');
                }
                if ($qty > 10000) {
                    return $this->fail('Idadi ya vouchers haizidi 10,000 kwa mara moja');
                }
                $requested = is_array($salePrices) ? ($salePrices[$item['package_type']] ?? null) : null;
                $price = is_numeric($requested) && $requested > 0
                    ? (float) $requested : (float) self::PACKAGE_VALUES[$item['package_type']]['price'];
                if ($price < self::MIN_RESELLER_SALE_PRICE) {
                    return $this->fail('Bei ya chini ya voucher ni TZS '.number_format(self::MIN_RESELLER_SALE_PRICE));
                }
                $item['salePrice'] = $price;
                $totalCost += $price * $qty;
            }
            unset($item);
            $maxId = (int) (DB::table('voucher_batches')->max('id') ?? 0);
            $batchName = 'RSL-'.strtoupper(now()->format('M')).'-'.str_pad((string) ($maxId + 1), 3, '0', STR_PAD_LEFT);
            $totalQty = array_sum(array_column($items, 'quantity'));
            $batchId = DB::table('voucher_batches')->insertGetId([
                'batch_name' => $batchName,
                'issued_to' => 'Reseller: '.(self::user($request)['name'] ?? ''),
                'total_vouchers' => $totalQty,
                'total_value' => $totalCost,
                'created_by' => $uid,
            ]);
            $rows = [];
            foreach ($items as $item) {
                for ($i = 0; $i < $item['quantity']; $i++) {
                    $rows[] = [
                        'voucher_code' => $this->generateUniqueVoucher(),
                        'package_id' => self::PACKAGE_VALUES[$item['package_type']]['id'],
                        'price' => $item['salePrice'],
                        'is_used' => 0,
                        'used_at' => null,
                        'used_by' => null,
                        'batch_id' => $batchId,
                        'expires_at' => now()->addYear(),
                        'reseller_profit' => 1,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('vouchers')->insert($chunk);
            }
            DB::table('voucher_batches')->where('id', $batchId)
                ->update(['total_vouchers' => $totalQty, 'total_value' => $totalCost]);

            return ApiResponse::success([
                'data' => [
                    'batch_id' => $batchId, 'batch_name' => $batchName, 'count' => $totalQty,
                    'sale_value' => $totalCost, 'balance_after' => (float) $reseller->balance,
                ],
            ], 'Vouchers '.$totalQty.' zimeundwa kikamilifu! Zitakapouzwa, TZS '.number_format($totalCost).' zitaingia kwenye akaunti yako.');
        } catch (\Throwable $e) {
            Log::error('Reseller voucher generation failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kuunda vouchers', 500);
        }
    }

    public function topUp(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $amount = (float) $request->input('amount');
        if (! is_finite($amount) || $amount <= 0) {
            return $this->fail('Kiasi lazima iwe nambari chanya');
        }
        if ($amount < 1000) {
            return $this->fail('Kiasi kidogo ni TZS 1,000');
        }
        try {
            $reseller = DB::table('resellers')->where('user_id', $uid)->where('status', 'active')->first();
            if (! $reseller) {
                return $this->fail('Akaunti ya reseller haijapatikana', 404);
            }
            DB::table('resellers')->where('user_id', $uid)->update([
                'balance' => DB::raw('balance + '.number_format($amount, 2, '.', '')),
            ]);
            DB::table('transactions')->insert([
                'user_id' => $uid,
                'transaction_type' => 'topup',
                'amount' => $amount,
                'payment_reference' => $request->input('payment_reference') ?: null,
                'description' => 'Top up via '.($request->input('payment_method') ?: 'clickpesa'),
                'created_at' => DB::raw('NOW()'),
            ]);
            $balance = (float) (DB::table('resellers')->where('user_id', $uid)->value('balance') ?? 0);

            return ApiResponse::success([
                'data' => [
                    'amount_added' => $amount, 'balance_after' => $balance,
                    'payment_method' => $request->input('payment_method') ?: 'manual',
                    'payment_reference' => $request->input('payment_reference') ?: null,
                ],
            ], 'Salio limeongezwa kwa TZS '.number_format($amount));
        } catch (\Throwable $e) {
            Log::error('Reseller top up failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kuongeza salio', 500);
        }
    }

    public function getTopups(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $rows = DB::table('transactions')->where('user_id', $uid)->where('transaction_type', 'topup')
            ->orderByDesc('created_at')->limit(50)
            ->select('id', 'amount', 'payment_reference', 'description', 'created_at')->get();

        return ApiResponse::ok(['data' => $rows]);
    }

    public function getWithdrawals(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $reseller = DB::table('resellers')->where('user_id', $uid)->first(['id']);
        if (! $reseller) {
            return $this->fail('Reseller haijapatikana', 404);
        }
        $rows = DB::table('reseller_withdrawals')->where('reseller_id', $reseller->id)
            ->orderByDesc('created_at')->get();

        return ApiResponse::ok(['data' => $rows, 'count' => $rows->count()]);
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $amount = (float) $request->input('amount');
        if (! is_finite($amount) || $amount <= 0) {
            return $this->fail('Kiasi lazima iwe nambari chanya');
        }
        if ($amount < 5000) {
            return $this->fail('Kiasi kidogo cha uondoaji ni TZS 5,000');
        }
        $phone = PaymentFulfillment::normalizePhone($request->input('payment_phone'));
        if (! $phone) {
            return $this->fail('Weka namba sahihi ya simu ya mobile money (mfano 0712345678)');
        }
        try {
            $result = DB::transaction(function () use ($uid, $amount, $phone, $request): array {
                $reseller = DB::table('resellers')->where('user_id', $uid)->where('status', 'active')
                    ->lockForUpdate()->first();
                if (! $reseller) {
                    return ['error' => $this->fail('Akaunti ya reseller haijapatikana', 404)];
                }
                if ((float) $reseller->balance < $amount) {
                    return ['error' => $this->fail('Salio haikutosi. Una TZS '.number_format((float) $reseller->balance))];
                }
                $fee = (int) round($amount * self::WITHDRAWAL_FEE_RATE);
                $payout = $amount - $fee;
                $withdrawalId = DB::table('reseller_withdrawals')->insertGetId([
                    'reseller_id' => $reseller->id,
                    'user_id' => $uid,
                    'amount' => $amount,
                    'fee' => $fee,
                    'payout_amount' => $payout,
                    'payment_method' => $request->input('payment_method') ?: 'mobile_money',
                    'payment_phone' => $phone,
                    'status' => 'pending',
                    'attempts' => 0,
                    'requested_at' => DB::raw('NOW()'),
                ]);
                DB::table('reseller_withdrawals')->where('id', $withdrawalId)
                    ->update(['order_reference' => 'WDR'.$withdrawalId]);
                DB::table('resellers')->where('user_id', $uid)->update([
                    'balance' => DB::raw('balance - '.number_format($amount, 2, '.', '')),
                ]);
                DB::table('transactions')->insert([
                    'user_id' => $uid,
                    'transaction_type' => 'withdrawal',
                    'amount' => $amount,
                    'payment_reference' => 'WDR-'.$withdrawalId,
                    'description' => 'Withdrawal via mobile money; platform fee 10% TZS '.number_format($fee).'; payout TZS '.number_format($payout),
                    'created_at' => DB::raw('NOW()'),
                ]);

                return ['id' => $withdrawalId, 'fee' => $fee, 'payout' => $payout, 'phone' => $phone];
            }, 3);
            if (isset($result['error'])) {
                return $result['error'];
            }
            $balance = (float) (DB::table('resellers')->where('user_id', $uid)->value('balance') ?? 0);

            return ApiResponse::success([
                'data' => [
                    'id' => $result['id'], 'amount' => $amount, 'fee' => $result['fee'],
                    'payout' => $result['payout'], 'balance_after' => $balance,
                    'status' => 'pending', 'payment_phone' => $result['phone'],
                ],
            ], 'Uondoaji wa TZS '.number_format($amount)." umepokelewa. Ada ya 10% ni TZS ".number_format($result['fee'])
                .'; TZS '.number_format($result['payout']).' zitatumwa kwenye namba '.$result['phone'].' ndani ya dakika chache.');
        } catch (\Throwable $e) {
            Log::error('Reseller withdrawal failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kufanya uondoaji', 500);
        }
    }

    public function cancelWithdrawal(Request $request, int $id): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        try {
            $result = DB::transaction(function () use ($uid, $id): array {
                $reseller = DB::table('resellers')->where('user_id', $uid)->first(['id']);
                if (! $reseller) {
                    return ['error' => $this->fail('Reseller haijapatikana', 404)];
                }
                $withdrawal = DB::table('reseller_withdrawals')
                    ->where('id', $id)->where('reseller_id', $reseller->id)->where('status', 'pending')
                    ->lockForUpdate()->first();
                if (! $withdrawal) {
                    return ['error' => $this->fail('Uondoaji haujapatikana au umeshashughulikiwa', 404)];
                }
                DB::table('reseller_withdrawals')->where('id', $id)->delete();
                DB::table('resellers')->where('user_id', $uid)->update([
                    'balance' => DB::raw('balance + '.number_format((float) $withdrawal->amount, 2, '.', '')),
                ]);

                return [];
            }, 3);
            if (isset($result['error'])) {
                return $result['error'];
            }

            return ApiResponse::success([], 'Uondoaji umefutwa na salio limerudishwa');
        } catch (\Throwable $e) {
            Log::error('Reseller withdrawal cancel failed', ['error' => $e->getMessage()]);

            return $this->fail('Imeshindikana kufuta uondoaji', 500);
        }
    }

    public function deleteVoucher(Request $request, int $id): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $voucher = DB::table('vouchers')->join('voucher_batches', 'vouchers.batch_id', '=', 'voucher_batches.id')
            ->where('vouchers.id', $id)->where('voucher_batches.created_by', $uid)
            ->first(['vouchers.id', 'vouchers.is_used']);
        if (! $voucher) {
            return $this->fail('Voucher haijapatikana', 404);
        }
        if ($voucher->is_used) {
            return $this->fail('Haiwezekani kufuta voucher iliyotumika');
        }
        DB::table('vouchers')->where('id', $id)->delete();

        return ApiResponse::success([], 'Voucher imefutwa');
    }

    public function getSettings(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $user = DB::table('users')->where('id', $uid)
            ->first(['id', 'name', 'email', 'phone_number', 'location']);
        $reseller = DB::table('resellers')->where('user_id', $uid)
            ->first(['id', 'balance', 'total_earned', 'commission_rate', 'tier', 'status', 'router_id']);

        return ApiResponse::ok(['user' => $user, 'reseller' => $reseller]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $name = trim((string) $request->input('name', ''));
        $email = $request->input('email');
        $phone = $request->input('phone');
        if ($name === '') {
            return $this->fail('Jina linahitajika');
        }
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Barua pepe sio sahihi');
        }
        if ($phone && ! preg_match('/^[0-9]{9,15}$/', (string) $phone)) {
            return $this->fail('Namba ya simu sio sahihi');
        }
        DB::table('users')->where('id', $uid)->update([
            'name' => $name,
            'email' => $email ?: DB::raw('email'),
            'phone_number' => $phone ?: DB::raw('phone_number'),
            'updated_at' => DB::raw('NOW()'),
        ]);
        $session = self::user($request);
        if ($session) {
            $session['name'] = $name;
            if ($email) {
                $session['email'] = $email;
            }
            if ($phone) {
                $session['phone_number'] = $phone;
            }
            $this->setSession($request, $session);
        }

        return ApiResponse::success([], 'Wasifu umesasishwa');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if ($uid === null) {
            return $this->unauthorized();
        }
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('confirm_password', '');
        if ($current === '' || $new === '' || $confirm === '') {
            return $this->fail('Jaza sehemu zote.');
        }
        if ($new !== $confirm) {
            return $this->fail('Neno la siri jipya halifanani.');
        }
        if (strlen($new) < 8) {
            return $this->fail('Neno la siri linapaswa kuwa na herufi angalau 8.');
        }
        $user = DB::table('users')->where('id', $uid)->first(['password']);
        if (! $user) {
            return $this->fail('Reseller access required', 401);
        }
        if (! Hash::check($current, (string) $user->password)) {
            return $this->fail('Neno la siri la sasa sio sahihi.');
        }
        DB::table('users')->where('id', $uid)->update([
            'password' => Hash::make($new), 'updated_at' => DB::raw('NOW()'),
        ]);

        return ApiResponse::success([], 'Neno la siri limebadilishwa.');
    }

    private function generateUniqueVoucher(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'HOT'.random_int(100000, 999999);
            if (! DB::table('vouchers')->where('voucher_code', $code)->exists()) {
                return $code;
            }
        }

        return 'HOT'.substr((string) time(), -8);
    }

    private static function user(Request $request): ?array
    {
        $user = $request->session()->get('reseller_user');

        return is_array($user) ? $user : null;
    }

    private function userId(Request $request): ?int
    {
        $user = self::user($request);
        $id = $user['id'] ?? $user['user_id'] ?? null;

        return $id ? (int) $id : null;
    }

    private function setSession(Request $request, array $user): void
    {
        $user['login_time'] = $user['login_time'] ?? now()->toIso8601String();
        $request->session()->put('reseller_user', $user);
        $request->session()->save();
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return ApiResponse::json(['success' => false, 'message' => $message], $status);
    }

    private function unauthorized(): JsonResponse
    {
        return $this->fail('Authentication required', 401);
    }
}
