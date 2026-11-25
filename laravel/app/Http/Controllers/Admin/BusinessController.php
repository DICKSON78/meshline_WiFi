<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use App\Support\Hotbando;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Port of routes/business.js — the /admin/business internet CRM module. */
class BusinessController extends BaseController
{
    // ─────────────────────────── Customers ───────────────────────────

    public function customers(Request $request): JsonResponse
    {
        try {
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 20, 1, 200);
            $offset = ($page - 1) * $limit;
            $where = "WHERE u.role = 'customer'";
            $params = [];
            if ($request->query('search')) {
                $where .= ' AND (u.name LIKE ? OR u.phone_number LIKE ? OR u.email LIKE ?)';
                $term = '%'.$request->query('search').'%';
                array_push($params, $term, $term, $term);
            }
            if ($request->query('status') === 'active') {
                $where .= ' AND u.is_active = 1';
            } elseif ($request->query('status') === 'suspended') {
                $where .= ' AND u.is_active = 0';
            }
            if ($request->query('date_from')) {
                $where .= ' AND u.created_at >= ?';
                $params[] = $request->query('date_from').' 00:00:00';
            }
            if ($request->query('date_to')) {
                $where .= ' AND u.created_at <= ?';
                $params[] = $request->query('date_to').' 23:59:59';
            }
            if ($request->query('package_id')) {
                $where .= ' AND u.package = ?';
                $params[] = $request->query('package_id');
            }
            $allowedSorts = ['created_at', 'name', 'moneyspent', 'package'];
            $sortCol = in_array($request->query('sort'), $allowedSorts, true) ? $request->query('sort') : 'created_at';
            $sortOrder = strtoupper((string) $request->query('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            $totalRow = DB::selectOne("SELECT COUNT(*) as total FROM users u {$where}", $params);
            $total = (int) ($totalRow->total ?? 0);
            $customers = DB::select(
                "SELECT u.id, u.name, u.phone_number, u.email, u.is_active, u.role, u.created_at, u.last_login,
                        u.package, u.moneyspent, u.usage_until,
                        (SELECT COUNT(*) FROM clickpesa_transactions ct WHERE ct.user_id = u.id AND ct.status IN ('SUCCESS','SETTLED')) as payment_count,
                        (SELECT COALESCE(SUM(ct.amount),0) FROM clickpesa_transactions ct WHERE ct.user_id = u.id AND ct.status IN ('SUCCESS','SETTLED')) as total_payments,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.used_by = u.id) as vouchers_redeemed,
                        (SELECT v.created_at FROM vouchers v WHERE v.used_by = u.id ORDER BY v.used_at DESC LIMIT 1) as last_activity
                 FROM users u {$where}
                 ORDER BY u.{$sortCol} {$sortOrder}
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            );

            return ApiResponse::json([
                'success' => true,
                'customers' => $customers,
                'pagination' => [
                    'page' => $page, 'limit' => $limit, 'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function customersStats(): JsonResponse
    {
        try {
            $count = static fn (string $sql) => (int) (DB::selectOne($sql)?->c ?? 0);
            $totalRevenue = DB::selectOne("SELECT COALESCE(SUM(amount),0) as t FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED')");
            $arpu = DB::selectOne("SELECT COALESCE(AVG(moneyspent),0) as avg FROM users WHERE role='customer' AND moneyspent > 0");
            $byPackage = DB::select(
                "SELECT u.package as package_name, COUNT(*) as count, COALESCE(SUM(u.moneyspent),0) as revenue
                 FROM users u WHERE u.role='customer' GROUP BY u.package ORDER BY count DESC"
            );
            $growth = DB::select(
                "SELECT DATE(created_at) as day, COUNT(*) as new_users
                 FROM users WHERE role='customer' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                 GROUP BY DATE(created_at) ORDER BY day"
            );

            return ApiResponse::json([
                'success' => true,
                'total' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer'"),
                'active' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND is_active=1"),
                'suspended' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND is_active=0"),
                'todayNew' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND DATE(created_at)=CURDATE()"),
                'weekNew' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"),
                'monthNew' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"),
                'totalRevenue' => $totalRevenue->t ?? 0,
                'arpu' => (int) round((float) ($arpu->avg ?? 0)),
                'onlineNow' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"),
                'churnedUsers' => $count("SELECT COUNT(*) as c FROM users WHERE role='customer' AND last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) AND last_login IS NOT NULL AND moneyspent > 0"),
                'byPackage' => $byPackage,
                'growth' => $growth,
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function customerDetail(int|string $id): JsonResponse
    {
        try {
            $users = DB::select(
                "SELECT u.*, COALESCE(ct_agg.total_spent,0) as total_spent, COALESCE(ct_agg.payment_count,0) as payment_count,
                        COALESCE(v_agg.vouchers_used,0) as vouchers_used, COALESCE(v_agg.total_data_mb,0) as total_data_mb
                 FROM users u
                 LEFT JOIN (SELECT user_id, SUM(amount) as total_spent, COUNT(*) as payment_count
                            FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') GROUP BY user_id) ct_agg ON u.id = ct_agg.user_id
                 LEFT JOIN (SELECT used_by as user_id, COUNT(*) as vouchers_used, COALESCE(SUM(p.data_limit_mb),0) as total_data_mb
                            FROM vouchers v JOIN packages p ON v.package_id = p.id WHERE v.is_used=1 GROUP BY used_by) v_agg ON u.id = v_agg.user_id
                 WHERE u.id = ?",
                [$id]
            );
            if (! $users) {
                return ApiResponse::json(['success' => false, 'message' => 'Customer not found'], 404);
            }
            $payments = DB::select('SELECT * FROM clickpesa_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$id]);
            $vouchers = DB::select(
                'SELECT v.*, p.name as package_name, p.duration_hours, p.data_limit_mb
                 FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id
                 WHERE v.used_by = ? ORDER BY v.used_at DESC LIMIT 20',
                [$id]
            );
            $sessions = DB::select('SELECT *, timestamp AS connected_at FROM user_connection_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 20', [$id]);
            try {
                $devices = DB::select('SELECT DISTINCT mac_address, first_seen, last_seen FROM user_devices WHERE user_id = ? ORDER BY last_seen DESC LIMIT 10', [$id]);
            } catch (Throwable $e) {
                $devices = [];
            }

            return ApiResponse::json([
                'success' => true,
                'customer' => $users[0],
                'payments' => $payments,
                'vouchers' => $vouchers,
                'sessions' => $sessions,
                'devices' => $devices,
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function customerAdjust(Request $request, int|string $id): JsonResponse
    {
        try {
            $user = DB::table('users')->where('id', $id)->where('role', 'customer')->first();
            if (! $user) {
                return ApiResponse::json(['success' => false, 'message' => 'Customer not found'], 404);
            }
            $action = $request->input('action');
            if ($action === 'extend') {
                $days = (int) ($request->input('days', 7)) ?: 7;
                DB::table('users')->where('id', $id)->update([
                    'usage_until' => DB::raw("DATE_ADD(COALESCE(usage_until, NOW()), INTERVAL {$days} DAY)"),
                ]);
            } elseif ($action === 'grant_data') {
                $mb = (int) ($request->input('data_mb', 100)) ?: 100;
                DB::table('users')->where('id', $id)->update([
                    'free_bytes' => DB::raw('COALESCE(free_bytes,0) + '.($mb * Hotbando::MIB)),
                ]);
            } elseif ($action === 'change_package') {
                DB::table('users')->where('id', $id)->update(['package' => $request->input('package_name', '')]);
            } elseif ($action === 'suspend') {
                DB::table('users')->where('id', $id)->update(['is_active' => 0]);
            } elseif ($action === 'activate') {
                DB::table('users')->where('id', $id)->update(['is_active' => 1]);
            }

            return ApiResponse::json(['success' => true, 'message' => 'Customer updated']);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    // ─────────────────────────── Resellers ───────────────────────────

    public function resellers(Request $request): JsonResponse
    {
        try {
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 20, 1, 200);
            $offset = ($page - 1) * $limit;
            $where = "WHERE u.role = 'reseller'";
            $params = [];
            if ($request->query('search')) {
                $where .= ' AND (u.name LIKE ? OR u.phone_number LIKE ?)';
                $term = '%'.$request->query('search').'%';
                array_push($params, $term, $term);
            }
            if ($request->query('status') === 'active') {
                $where .= ' AND u.is_active = 1';
            } elseif ($request->query('status') === 'suspended') {
                $where .= ' AND u.is_active = 0';
            }
            if ($request->query('tier')) {
                $where .= ' AND r.tier = ?';
                $params[] = $request->query('tier');
            }
            $countRow = DB::selectOne("SELECT COUNT(*) as total FROM users u LEFT JOIN resellers r ON u.id = r.user_id {$where}", $params);
            $total = (int) ($countRow->total ?? 0);
            $resellers = DB::select(
                "SELECT u.id, u.name, u.phone_number, u.email, u.is_active, u.created_at,
                        COALESCE(r.balance,0) as balance, COALESCE(r.total_earned,0) as total_earned,
                        COALESCE(r.commission_rate,10) as commission_rate, COALESCE(r.tier,'bronze') as tier,
                        COALESCE(r.total_vouchers_sold,0) as total_vouchers_sold,
                        0 as total_customers, r.status as reseller_status,
                        (SELECT COUNT(*) FROM voucher_batches vb WHERE vb.created_by = u.id) as batch_count,
                        (SELECT COUNT(*) FROM vouchers v JOIN voucher_batches vb ON v.batch_id = vb.id WHERE vb.created_by = u.id AND v.is_used=1) as redeemed_count,
                        (SELECT COALESCE(SUM(v.price),0) FROM vouchers v JOIN voucher_batches vb ON v.batch_id = vb.id WHERE vb.created_by = u.id AND v.is_used=1) as revenue_generated
                 FROM users u LEFT JOIN resellers r ON u.id = r.user_id {$where}
                 ORDER BY revenue_generated DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );
            $summary = DB::selectOne(
                "SELECT COUNT(*) as total, SUM(CASE WHEN u.is_active=1 THEN 1 ELSE 0 END) as active,
                        COALESCE(SUM(r.balance),0) as total_balance, COALESCE(SUM(r.total_earned),0) as total_earned,
                        COALESCE(AVG(r.commission_rate),10) as avg_commission,
                        (SELECT COALESCE(SUM(v.price),0) FROM vouchers v JOIN voucher_batches vb ON v.batch_id=vb.id JOIN users u2 ON vb.created_by=u2.id WHERE u2.role='reseller' AND v.is_used=1) as total_revenue
                 FROM users u LEFT JOIN resellers r ON u.id = r.user_id WHERE u.role='reseller'"
            );

            return ApiResponse::json([
                'success' => true,
                'resellers' => $resellers,
                'summary' => $summary,
                'pagination' => [
                    'page' => $page, 'limit' => $limit, 'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function resellerDetail(int|string $id): JsonResponse
    {
        try {
            $users = DB::select(
                "SELECT u.*, COALESCE(r.balance,0) as balance, COALESCE(r.total_earned,0) as total_earned,
                        COALESCE(r.commission_rate,10) as commission_rate, COALESCE(r.tier,'bronze') as tier,
                        COALESCE(r.total_vouchers_sold,0) as total_vouchers_sold, r.status as reseller_status
                 FROM users u LEFT JOIN resellers r ON u.id = r.user_id WHERE u.id=? AND u.role='reseller'",
                [$id]
            );
            if (! $users) {
                return ApiResponse::json(['success' => false, 'message' => 'Reseller not found'], 404);
            }
            $batches = DB::select(
                'SELECT vb.*, (SELECT COUNT(*) FROM vouchers WHERE batch_id=vb.id) as total_vouchers,
                        (SELECT COUNT(*) FROM vouchers WHERE batch_id=vb.id AND is_used=1) as redeemed
                 FROM voucher_batches vb WHERE vb.created_by=? ORDER BY vb.created_at DESC LIMIT 20',
                [$id]
            );
            $transactions = DB::select('SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 30', [$id]);
            $recentSales = DB::select(
                'SELECT v.voucher_code, v.price, v.used_at, p.name as package_name
                 FROM vouchers v JOIN voucher_batches vb ON v.batch_id=vb.id LEFT JOIN packages p ON v.package_id=p.id
                 WHERE vb.created_by=? AND v.is_used=1 ORDER BY v.used_at DESC LIMIT 10',
                [$id]
            );
            $performance = DB::select(
                'SELECT DATE(v.used_at) as day, COUNT(*) as sales, COALESCE(SUM(v.price),0) as revenue
                 FROM vouchers v JOIN voucher_batches vb ON v.batch_id=vb.id
                 WHERE vb.created_by=? AND v.is_used=1 AND v.used_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY DATE(v.used_at) ORDER BY day',
                [$id]
            );

            return ApiResponse::json([
                'success' => true,
                'reseller' => $users[0],
                'batches' => $batches,
                'transactions' => $transactions,
                'recentSales' => $recentSales,
                'performance' => $performance,
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function resellerTier(Request $request, int|string $id): JsonResponse
    {
        try {
            $tier = $request->input('tier');
            if ($tier !== null && ! in_array($tier, ['bronze', 'silver', 'gold', 'platinum'], true)) {
                return ApiResponse::json(['success' => false, 'message' => 'Invalid tier'], 400);
            }
            if ($tier !== null) {
                DB::table('resellers')->where('user_id', $id)->update(['tier' => $tier]);
            }
            if ($request->exists('commission_rate')) {
                DB::table('resellers')->where('user_id', $id)->update(['commission_rate' => (float) $request->input('commission_rate')]);
            }

            return ApiResponse::json(['success' => true, 'message' => 'Tier updated']);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function resellerStatus(Request $request, int|string $id): JsonResponse
    {
        try {
            $active = $request->input('is_active') ? 1 : 0;
            DB::table('users')->where('id', $id)->where('role', 'reseller')->update(['is_active' => $active]);
            DB::table('resellers')->where('user_id', $id)->update(['status' => $active ? 'active' : 'suspended']);

            return ApiResponse::json([
                'success' => true,
                'message' => $active ? 'Reseller activated' : 'Reseller suspended',
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    // ─────────────────────────── Campaigns ───────────────────────────





    // ─────────────────────────── Ads (business module) ───────────────────────────




    // ─────────────────────────── Reports ───────────────────────────

    public function reportsOverview(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '30d');
            $days = $period === '7d' ? 7 : ($period === '90d' ? 90 : 30);
            $filter = 'DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)';
            $revenue = DB::select(
                "SELECT DATE(created_at) as day, COALESCE(SUM(amount),0) as revenue, COUNT(*) as transactions
                 FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') AND created_at >= {$filter}
                 GROUP BY DATE(created_at) ORDER BY day"
            );
            $totalRevenue = DB::selectOne("SELECT COALESCE(SUM(amount),0) as t FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') AND created_at >= {$filter}");
            $newUsers = DB::select("SELECT DATE(created_at) as day, COUNT(*) as count FROM users WHERE role='customer' AND created_at >= {$filter} GROUP BY DATE(created_at) ORDER BY day");
            $activeUsers = DB::selectOne("SELECT COUNT(DISTINCT user_id) as c FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') AND created_at >= {$filter}");
            $voucherSales = DB::select("SELECT DATE(used_at) as day, COUNT(*) as count, COALESCE(SUM(price),0) as revenue FROM vouchers WHERE is_used=1 AND used_at >= {$filter} GROUP BY DATE(used_at) ORDER BY day");
            $topPackages = DB::select(
                "SELECT p.name, COUNT(v.id) as sales, COALESCE(SUM(v.price),0) as revenue
                 FROM vouchers v JOIN packages p ON v.package_id=p.id WHERE v.is_used=1 AND v.used_at >= {$filter}
                 GROUP BY p.id ORDER BY sales DESC LIMIT 5"
            );
            $routerStats = DB::select('SELECT status, COUNT(*) as count FROM mikrotiks GROUP BY status');
            $resellerPerf = DB::select(
                "SELECT u.name, COALESCE(r.total_earned,0) as earned, COALESCE(r.total_vouchers_sold,0) as sold
                 FROM users u JOIN resellers r ON u.id=r.user_id WHERE u.role='reseller' ORDER BY earned DESC LIMIT 5"
            );

            return ApiResponse::json([
                'success' => true,
                'revenue' => ['data' => $revenue, 'total' => $totalRevenue->t ?? 0],
                'users' => ['newUsers' => $newUsers, 'activeCount' => $activeUsers->c ?? 0],
                'vouchers' => $voucherSales,
                'topPackages' => $topPackages,
                'routerStats' => $routerStats,
                'resellerPerf' => $resellerPerf,
                'period' => $period,
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    public function reportsFinancial(Request $request): JsonResponse
    {
        try {
            $where = "WHERE status IN ('SUCCESS','SETTLED')";
            $params = [];
            if ($request->query('from')) {
                $where .= ' AND created_at >= ?';
                $params[] = $request->query('from').' 00:00:00';
            }
            if ($request->query('to')) {
                $where .= ' AND created_at <= ?';
                $params[] = $request->query('to').' 23:59:59';
            }
            $daily = DB::select("SELECT DATE(created_at) as day, COALESCE(SUM(amount),0) as revenue, COUNT(*) as transactions FROM clickpesa_transactions {$where} GROUP BY DATE(created_at) ORDER BY day", $params);
            $byMethod = DB::select("SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount),0) as revenue FROM clickpesa_transactions {$where} GROUP BY payment_method ORDER BY revenue DESC", $params);
            $byHour = DB::select("SELECT HOUR(created_at) as hour, COUNT(*) as count, COALESCE(SUM(amount),0) as revenue FROM clickpesa_transactions {$where} GROUP BY HOUR(created_at) ORDER BY hour", $params);
            $total = DB::selectOne("SELECT COALESCE(SUM(amount),0) as revenue, COUNT(*) as transactions, COALESCE(AVG(amount),0) as avg_order FROM clickpesa_transactions {$where}", $params);

            return ApiResponse::json([
                'success' => true,
                'daily' => $daily,
                'byMethod' => $byMethod,
                'byHour' => $byHour,
                'total' => $total,
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    // ─────────────────────────── Applications ───────────────────────────





    // ─────────────────────────── Packages ───────────────────────────

    public function packages(): JsonResponse
    {
        try {
            $packages = DB::select(
                'SELECT p.*,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.package_id=p.id) as total_vouchers,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.package_id=p.id AND v.is_used=1) as redeemed,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.package_id=p.id AND v.is_used=0) as available,
                        (SELECT COALESCE(SUM(v.price),0) FROM vouchers v WHERE v.package_id=p.id AND v.is_used=1) as revenue,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.package_id=p.id AND v.is_used=1 AND v.used_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as week_sales,
                        (SELECT COUNT(*) FROM vouchers v WHERE v.package_id=p.id AND v.is_used=1 AND v.used_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as month_sales
                 FROM packages p ORDER BY p.sort_order ASC, p.price ASC'
            );
            $revTotal = DB::selectOne("SELECT COALESCE(SUM(amount),0) as t FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED')");
            $vouchersTotal = DB::selectOne('SELECT COUNT(*) as total, SUM(CASE WHEN is_used=1 THEN 1 ELSE 0 END) as used FROM vouchers');

            return ApiResponse::json([
                'success' => true,
                'packages' => $packages,
                'summary' => [
                    'totalRevenue' => $revTotal->t ?? 0,
                    'totalVouchers' => $vouchersTotal->total ?? 0,
                    'usedVouchers' => $vouchersTotal->used ?? 0,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->businessFail($e);
        }
    }

    private function businessFail(Throwable $e): JsonResponse
    {
        report($e);

        return ApiResponse::json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
