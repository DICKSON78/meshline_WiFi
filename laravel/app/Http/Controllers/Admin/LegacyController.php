<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inline handlers from routes/adminLegacy.js: wallet, reseller administration,
 * package CRUD and voucher browsing/statistics.
 */
class LegacyController extends BaseController
{
    // ─────────────────────────── Admin wallet ───────────────────────────

    public function wallet(Request $request): JsonResponse
    {
        try {
            $userId = $this->uid($request);
            $wallet = DB::table('wallets')->where('user_id', $userId)->first();
            if (! $wallet) {
                DB::table('wallets')->insert([
                    'user_id' => $userId,
                    'balance' => 0,
                    'is_active' => 1,
                    'created_at' => now(),
                ]);
                $wallet = DB::table('wallets')->where('user_id', $userId)->first();
            }

            return ApiResponse::json(['success' => true, 'wallet' => $wallet ?: ['balance' => 0, 'is_active' => 1]]);
        } catch (Throwable $e) {
            return $this->plainError('Failed to load wallet');
        }
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        try {
            $userId = $this->uid($request);
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 50, 1, 100);
            $offset = ($page - 1) * $limit;
            $transactions = DB::table('transactions')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit($limit)->offset($offset)->get();
            $total = (int) DB::table('transactions')->where('user_id', $userId)->count();

            return ApiResponse::json([
                'success' => true,
                'transactions' => $transactions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainError('Failed to load transactions');
        }
    }

    public function walletDeposit(Request $request): JsonResponse
    {
        $amount = $request->input('amount');
        if (! $amount || ! is_numeric($amount) || (float) $amount <= 0) {
            return $this->plainError('Invalid amount', 400);
        }
        $userId = $this->uid($request);
        $description = $request->input('description') ?: 'Admin self-deposit';

        try {
            $result = DB::transaction(function () use ($userId, $amount, $description): array {
                $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                if (! $wallet) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'user_id' => $userId, 'balance' => 0, 'total_deposited' => 0,
                        'is_active' => 1, 'created_at' => now(),
                    ]);
                    $wallet = (object) ['id' => $walletId, 'balance' => 0];
                }
                $before = (float) $wallet->balance;
                $after = $before + (float) $amount;
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $after,
                    'total_deposited' => DB::raw('total_deposited + '.(float) $amount),
                ]);
                $transactionId = DB::table('transactions')->insertGetId([
                    'wallet_id' => $wallet->id,
                    'user_id' => $userId,
                    'transaction_type' => 'deposit',
                    'amount' => (float) $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'status' => 'completed',
                    'payment_method' => 'manual',
                    'payment_reference' => 'ADMIN-'.round(microtime(true) * 1000),
                    'description' => $description,
                    'processed_at' => now(),
                    'created_at' => now(),
                ]);

                return [
                    'transaction_id' => $transactionId,
                    'wallet_id' => $wallet->id,
                    'balance' => $after,
                    'amount' => (float) $amount,
                ];
            });

            return ApiResponse::json(array_merge(
                ['success' => true, 'message' => 'Deposit successful'],
                $result
            ));
        } catch (Throwable $e) {
            return $this->plainError($e->getMessage());
        }
    }

    // ─────────────────────────── Resellers ───────────────────────────

    public function resellers(Request $request): JsonResponse
    {
        try {
            $search = (string) $request->query('search', '');
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 20, 1, 100);
            $offset = ($page - 1) * $limit;
            $where = "WHERE u.role = 'reseller'";
            $params = [];
            if ($search !== '') {
                $where .= ' AND (u.name LIKE ? OR u.phone_number LIKE ? OR u.email LIKE ?)';
                $term = '%'.$search.'%';
                array_push($params, $term, $term, $term);
            }
            $resellers = DB::select(
                "SELECT u.id, u.name, u.email, u.phone_number, u.is_active, u.created_at,
                        COALESCE(r.balance,0) as wallet_balance, COALESCE(r.total_earned,0) as total_earned, r.status as reseller_status,
                        (SELECT COUNT(*) FROM vouchers v JOIN voucher_batches vb ON v.batch_id = vb.id WHERE vb.created_by = u.id) as total_vouchers,
                        (SELECT COUNT(*) FROM vouchers v JOIN voucher_batches vb ON v.batch_id = vb.id WHERE vb.created_by = u.id AND v.is_used = 1) as redeemed_vouchers
                 FROM users u LEFT JOIN resellers r ON u.id = r.user_id
                 {$where} ORDER BY u.created_at DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );
            $countRow = DB::selectOne("SELECT COUNT(*) as total FROM users u {$where}", $params);
            $total = (int) ($countRow->total ?? 0);

            return ApiResponse::json([
                'success' => true,
                'data' => $resellers,
                'pagination' => [
                    'page' => $page, 'limit' => $limit, 'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainError('Failed to load resellers');
        }
    }

    public function reseller(int|string $id): JsonResponse
    {
        try {
            $users = DB::select(
                "SELECT u.*, COALESCE(r.balance,0) as wallet_balance, COALESCE(r.total_earned,0) as total_earned, r.id as reseller_record_id
                 FROM users u LEFT JOIN resellers r ON u.id = r.user_id WHERE u.id = ? AND u.role = 'reseller'",
                [$id]
            );
            if (! $users) {
                return $this->plainError('Reseller not found', 404);
            }
            $user = $users[0];
            $batches = DB::select('SELECT * FROM voucher_batches WHERE created_by = ? ORDER BY created_at DESC LIMIT 20', [$id]);
            $transactions = DB::select('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$id]);
            $wallet = DB::select('SELECT * FROM wallets WHERE user_id = ?', [$id]);

            return ApiResponse::json([
                'success' => true,
                'user' => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'phone_number' => $user->phone_number, 'is_active' => $user->is_active,
                    'created_at' => $user->created_at,
                ],
                'wallet' => $wallet[0] ?? ['balance' => $user->wallet_balance ?? 0],
                'batches' => $batches,
                'transactions' => $transactions,
                'stats' => [
                    'wallet_balance' => $user->wallet_balance,
                    'total_earned' => $user->total_earned,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainError('Failed to load reseller details');
        }
    }

    public function resellerDeposit(Request $request, int|string $id): JsonResponse
    {
        $amount = $request->input('amount');
        if (! $amount || ! is_numeric($amount) || (float) $amount < 100) {
            return $this->plainError('Minimum deposit is TSh 100', 400);
        }
        $description = $request->input('description') ?: 'Admin deposit to reseller';
        try {
            $exists = DB::table('users')->where('id', $id)->where('role', 'reseller')->exists();
            if (! $exists) {
                return $this->plainError('Reseller not found', 404);
            }
            $balance = DB::transaction(function () use ($id, $amount, $description): float {
                $reseller = DB::table('resellers')->where('user_id', $id)->lockForUpdate()->first();
                if (! $reseller) {
                    throw new \RuntimeException('Reseller not found');
                }
                DB::table('resellers')->where('user_id', $id)->update([
                    'balance' => DB::raw('balance + '.(float) $amount),
                ]);
                $wallet = DB::table('wallets')->where('user_id', $id)->lockForUpdate()->first();
                if (! $wallet) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'user_id' => $id, 'balance' => 0, 'total_deposited' => 0,
                        'is_active' => 1, 'created_at' => now(),
                    ]);
                    $wallet = (object) ['id' => $walletId, 'balance' => 0];
                }
                $before = (float) $wallet->balance;
                $after = $before + (float) $amount;
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $after,
                    'total_deposited' => DB::raw('total_deposited + '.(float) $amount),
                ]);
                DB::table('transactions')->insert([
                    'wallet_id' => $wallet->id,
                    'user_id' => $id,
                    'transaction_type' => 'deposit',
                    'amount' => (float) $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'status' => 'completed',
                    'payment_method' => 'manual',
                    'payment_reference' => 'ADMIN-DEPOSIT-'.round(microtime(true) * 1000),
                    'description' => $description,
                    'processed_at' => now(),
                    'created_at' => now(),
                ]);

                return (float) ($reseller->balance + (float) $amount);
            });

            return ApiResponse::json(['success' => true, 'message' => 'Deposit successful', 'balance' => $balance]);
        } catch (Throwable $e) {
            return $this->plainError($e->getMessage());
        }
    }

    public function resellerDeduct(Request $request, int|string $id): JsonResponse
    {
        $amount = $request->input('amount');
        if (! $amount || ! is_numeric($amount) || (float) $amount < 100) {
            return $this->plainError('Minimum deduction is TSh 100', 400);
        }
        $description = $request->input('description') ?: 'Admin deduction from reseller';
        try {
            $balance = DB::transaction(function () use ($id, $amount, $description): float {
                $reseller = DB::table('resellers')->where('user_id', $id)->lockForUpdate()->first();
                if (! $reseller) {
                    throw new \RuntimeException('Reseller not found');
                }
                if ((float) $reseller->balance < (float) $amount) {
                    throw new \RuntimeException(
                        'Insufficient balance. Available: TSh '.number_format((float) $reseller->balance)
                    );
                }
                DB::table('resellers')->where('user_id', $id)->update([
                    'balance' => DB::raw('balance - '.(float) $amount),
                ]);
                DB::table('transactions')->insert([
                    'user_id' => $id,
                    'transaction_type' => 'deduction',
                    'amount' => (float) $amount,
                    'status' => 'completed',
                    'payment_method' => 'manual',
                    'description' => $description,
                    'created_at' => now(),
                ]);

                return (float) $reseller->balance - (float) $amount;
            });

            return ApiResponse::json(['success' => true, 'message' => 'Deduction successful', 'balance' => $balance]);
        } catch (Throwable $e) {
            return $this->plainError($e->getMessage());
        }
    }

    // ─────────────────────────── Packages ───────────────────────────

    public function createPackage(Request $request): JsonResponse
    {
        $name = $request->input('name');
        $duration = $request->input('duration_hours');
        $price = $request->input('price');
        if (! $name || ! $duration || ! $price) {
            return ApiResponse::json([
                'success' => false,
                'error' => 'Name, duration, and price are required',
            ], 400);
        }
        try {
            $id = DB::table('packages')->insertGetId([
                'name' => $name,
                'description' => $request->input('description', ''),
                'duration_hours' => $duration,
                'price' => $price,
                'currency' => $request->input('currency', 'TZS'),
                'data_limit_mb' => $request->input('data_limit_mb', 0) ?: 0,
                'speed_limit_mbps' => $request->input('speed_limit_mbps', 0) ?: 0,
                'is_active' => $request->exists('is_active') ? $this->boolInt($request->input('is_active')) : 1,
                'sort_order' => $request->input('sort_order', 0) ?: 0,
            ]);

            return ApiResponse::json(['success' => true, 'id' => $id, 'message' => 'Package created']);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function updatePackage(Request $request, int|string $id): JsonResponse
    {
        try {
            DB::table('packages')->where('id', $id)->update([
                'name' => $request->input('name'),
                'description' => $request->input('description', ''),
                'duration_hours' => $request->input('duration_hours'),
                'price' => $request->input('price'),
                'currency' => $request->input('currency', 'TZS'),
                'data_limit_mb' => $request->input('data_limit_mb', 0) ?: 0,
                'speed_limit_mbps' => $request->input('speed_limit_mbps', 0) ?: 0,
                'is_active' => $request->exists('is_active') ? $this->boolInt($request->input('is_active')) : 1,
                'sort_order' => $request->input('sort_order', 0) ?: 0,
            ]);

            return ApiResponse::json(['success' => true, 'message' => 'Package updated']);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deletePackage(int|string $id): JsonResponse
    {
        try {
            DB::table('packages')->where('id', $id)->delete();

            return ApiResponse::json(['success' => true, 'message' => 'Package deleted']);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function allPackages(): JsonResponse
    {
        try {
            $packages = DB::table('packages')->orderBy('sort_order')->orderBy('price')->get();

            return ApiResponse::json(['success' => true, 'data' => $packages, 'count' => $packages->count()]);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────── Voucher browsing ───────────────────────────

    public function voucherList(Request $request): JsonResponse
    {
        try {
            $where = '1=1';
            $params = [];
            $status = $request->query('status');
            if ($status === 'used') {
                $where .= ' AND v.is_used = 1';
            } elseif ($status === 'available') {
                $where .= ' AND v.is_used = 0';
            } elseif ($status === 'expired') {
                $where .= ' AND v.expires_at IS NOT NULL AND v.expires_at < NOW() AND v.is_used = 0';
            }
            if ($request->query('package_id')) {
                $where .= ' AND v.package_id = ?';
                $params[] = $request->query('package_id');
            }
            if ($request->query('batch_id')) {
                $where .= ' AND v.batch_id = ?';
                $params[] = $request->query('batch_id');
            }
            if ($request->query('search')) {
                $where .= ' AND (v.voucher_code LIKE ? OR p.name LIKE ?)';
                $term = '%'.$request->query('search').'%';
                array_push($params, $term, $term);
            }
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 50, 1, 100);
            $offset = ($page - 1) * $limit;
            $countRow = DB::selectOne(
                "SELECT COUNT(*) as total FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id WHERE {$where}",
                $params
            );
            $total = (int) ($countRow->total ?? 0);
            $vouchers = DB::select(
                "SELECT v.*, p.name as package_name, p.duration_hours, vb.batch_name, vb.issued_to, u.name as used_by_name
                 FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id
                 LEFT JOIN voucher_batches vb ON v.batch_id = vb.id LEFT JOIN users u ON v.used_by = u.id
                 WHERE {$where} ORDER BY v.created_at DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );

            return ApiResponse::json([
                'success' => true, 'data' => $vouchers, 'total' => $total,
                'page' => $page, 'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function voucherStatsFull(): JsonResponse
    {
        try {
            $scalar = static fn (string $sql): mixed => (int) (DB::selectOne($sql)?->c ?? 0);
            $total = $scalar('SELECT COUNT(*) as c FROM vouchers');
            $available = $scalar('SELECT COUNT(*) as c FROM vouchers WHERE is_used = 0');
            $used = $scalar('SELECT COUNT(*) as c FROM vouchers WHERE is_used = 1');
            $expired = $scalar('SELECT COUNT(*) as c FROM vouchers WHERE is_used = 0 AND expires_at IS NOT NULL AND expires_at < NOW()');
            $todayUsed = $scalar('SELECT COUNT(*) as c FROM vouchers WHERE is_used = 1 AND DATE(used_at) = CURDATE()');
            $todayRevenue = DB::selectOne('SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = CURDATE()');
            $totalRevenue = DB::selectOne('SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1');
            $byPackage = DB::select(
                'SELECT p.id, p.name, p.price, p.duration_hours, COUNT(v.id) as total,
                        SUM(CASE WHEN v.is_used = 0 THEN 1 ELSE 0 END) as available,
                        SUM(CASE WHEN v.is_used = 1 THEN 1 ELSE 0 END) as used
                 FROM packages p LEFT JOIN vouchers v ON p.id = v.package_id
                 GROUP BY p.id ORDER BY p.price ASC'
            );
            $recentBatches = DB::select(
                'SELECT vb.*, u.name as created_by_name,
                        (SELECT COUNT(*) FROM vouchers WHERE batch_id = vb.id) as voucher_count,
                        (SELECT COUNT(*) FROM vouchers WHERE batch_id = vb.id AND is_used = 1) as redeemed_count
                 FROM voucher_batches vb LEFT JOIN users u ON vb.created_by = u.id
                 ORDER BY vb.created_at DESC LIMIT 10'
            );

            return ApiResponse::json([
                'success' => true,
                'total' => $total,
                'available' => $available,
                'used' => $used,
                'expired' => $expired,
                'todayUsed' => $todayUsed,
                'todayRevenue' => $todayRevenue->total ?? 0,
                'totalRevenue' => $totalRevenue->total ?? 0,
                'byPackage' => $byPackage,
                'recentBatches' => $recentBatches,
            ]);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
