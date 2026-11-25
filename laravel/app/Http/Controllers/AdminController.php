<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\BaseController;
use App\Support\ApiResponse;
use App\Support\Meshline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/** Port of controllers/adminController.js, scoped to the internet/voucher product. */
class AdminController extends BaseController
{
    public static function formatTimeAgo(mixed $dateString): string
    {
        $timestamp = strtotime((string) $dateString);
        if ($timestamp === false) {
            return 'Sasa hivi';
        }
        $diff = time() - $timestamp;
        $minutes = (int) floor($diff / 60);
        $hours = (int) floor($diff / 3600);
        $days = (int) floor($diff / 86400);
        if ($minutes < 1) {
            return 'Sasa hivi';
        }
        if ($minutes < 60) {
            return "Dakika {$minutes} zilizopita";
        }
        if ($hours < 24) {
            return "Saa {$hours} zilizopita";
        }
        if ($days === 1) {
            return 'Jana';
        }

        return "Siku {$days} zilizopita";
    }

    /** Dashboard metrics derived from vouchers, customers and routers only. */
    public static function getDashboardData(): array
    {
        $empty = [
            'totalViews' => 0,
            'activePackages' => 0,
            'dataDistributed' => 0,
            'completionRate' => 0,
            'viewsTrend' => array_map(static fn (int $i) => [
                'date' => now()->subDays(6 - $i)->format('Y-m-d'), 'total' => 0,
            ], range(0, 6)),
            'activePackagesList' => [],
            'pendingApprovals' => [],
            'recentActivity' => [
                ['icon' => 'info-circle', 'description' => 'Mfumo upo tayari', 'time' => 'Sasa hivi'],
            ],
            'chartData' => ['labels' => ['Leo', 'Jana', 'Juzi', '3', '4', '5', '6'], 'data' => [0, 0, 0, 0, 0, 0, 0]],
            'online_routers' => 0,
            'total_routers' => 0,
            'active_users' => 0,
            'total_users' => 0,
        ];
        try {
            // "totalViews" is retained as the Vue key but now counts voucher
            // redemptions (internet sessions started) over the last 30 days.
            $totalViews = (int) (DB::selectOne('SELECT COUNT(*) as count FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')?->count ?? 0);
            $activePackages = (int) (DB::selectOne('SELECT COUNT(*) as count FROM packages WHERE is_active = 1')?->count ?? 0);
            $dataDistributedRow = DB::selectOne('SELECT COALESCE(SUM(free_bytes), 0) / (1024 * 1024 * 1024) as total_gb FROM users WHERE role = "customer"');
            $dataDistributed = number_format((float) ($dataDistributedRow->total_gb ?? 0), 2, '.', '');
            $completion = DB::selectOne(
                'SELECT CASE WHEN COUNT(*) = 0 THEN 0 ELSE ROUND(AVG(CASE WHEN usage_until > NOW() THEN 1 ELSE 0 END) * 100, 2) END as rate
                 FROM users WHERE role = "customer"'
            );
            $completionRate = $completion->rate ?? 0;

            $viewsTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $dateStr = now()->subDays($i)->format('Y-m-d');
                $daily = DB::selectOne('SELECT COUNT(*) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?', [$dateStr]);
                $viewsTrend[] = ['date' => $dateStr, 'total' => (int) ($daily->total ?? 0)];
            }
            $activePackagesList = DB::select(
                'SELECT p.id, p.name, p.price, COUNT(v.id) as redemptions
                 FROM packages p LEFT JOIN vouchers v ON v.package_id = p.id AND v.is_used = 1
                 WHERE p.is_active = 1 GROUP BY p.id ORDER BY p.sort_order ASC, p.price ASC LIMIT 5'
            );
            $pendingApprovals = DB::select(
                "SELECT router_id, router_name, setup_status, created_at
                 FROM mikrotiks WHERE setup_status = 'pending' ORDER BY created_at DESC LIMIT 5"
            );

            $recentActivity = [];
            $recentVouchers = DB::select(
                'SELECT v.used_at, p.name as package_name, u.name as user_name
                 FROM vouchers v JOIN packages p ON v.package_id = p.id JOIN users u ON v.used_by = u.id
                 WHERE v.is_used = 1 ORDER BY v.used_at DESC LIMIT 3'
            );
            foreach ($recentVouchers as $voucher) {
                $recentActivity[] = [
                    'icon' => 'ticket',
                    'description' => "{$voucher->user_name} ametumia voucher ya {$voucher->package_name}",
                    'time' => self::formatTimeAgo($voucher->used_at),
                ];
            }
            $newCustomers = DB::select(
                "SELECT created_at, name, phone_number FROM users WHERE role = 'customer'
                 ORDER BY created_at DESC LIMIT 2"
            );
            foreach ($newCustomers as $customer) {
                $recentActivity[] = [
                    'icon' => 'user-plus',
                    'description' => "{$customer->name} amejiunga kama mteja",
                    'time' => self::formatTimeAgo($customer->created_at),
                ];
            }

            $chartData = [
                'labels' => array_map(static fn ($item) => (new \DateTime($item['date']))->format('j M'), $viewsTrend),
                'data' => array_column($viewsTrend, 'total'),
            ];
            $routerStats = DB::selectOne(
                "SELECT COUNT(*) as total_routers, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_routers
                 FROM mikrotiks WHERE is_active = 1"
            );
            $activeUsers = (int) (DB::selectOne("SELECT COUNT(*) as count FROM users WHERE role = 'customer' AND usage_until > NOW() AND is_active = 1")?->count ?? 0);
            $totalUsers = (int) (DB::selectOne("SELECT COUNT(*) as count FROM users WHERE role = 'customer'")?->count ?? 0);

            return [
                'totalViews' => $totalViews,
                'activePackages' => $activePackages,
                'dataDistributed' => $dataDistributed,
                'completionRate' => $completionRate,
                'viewsTrend' => $viewsTrend,
                'activePackagesList' => $activePackagesList,
                'pendingApprovals' => $pendingApprovals,
                'recentActivity' => $recentActivity,
                'chartData' => $chartData,
                'online_routers' => (int) ($routerStats->online_routers ?? 0),
                'total_routers' => (int) ($routerStats->total_routers ?? 0),
                'active_users' => $activeUsers,
                'total_users' => $totalUsers,
            ];
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }
    }

    public function dashboardStats(): JsonResponse
    {
        try {
            return ApiResponse::json(array_merge(['success' => true], self::getDashboardData()));
        } catch (Throwable $e) {
            return $this->plainFail('Hitilafu katika kupakua takwimu');
        }
    }

    public function getPackages(): JsonResponse
    {
        try {
            $packages = DB::select('SELECT id, name, description, duration_hours, price, is_active FROM packages WHERE is_active = 1 ORDER BY price ASC');

            return ApiResponse::json(['success' => true, 'data' => $packages, 'count' => count($packages)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata vifurushi');
        }
    }

    // ─────────────────────────── Internet analytics ───────────────────────────

    private function analyticsCore(int $days = 30): array
    {
        $redemptions = DB::selectOne('SELECT COUNT(*) as count FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(NOW(), INTERVAL '.$days.' DAY)');
        $activeRate = DB::selectOne(
            'SELECT CASE WHEN COUNT(*) = 0 THEN 0 ELSE ROUND(AVG(CASE WHEN usage_until > NOW() THEN 1 ELSE 0 END) * 100, 2) END as rate
             FROM users WHERE role = "customer"'
        );
        $avgDuration = DB::selectOne(
            'SELECT COALESCE(AVG(p.duration_hours), 0) as avgHours
             FROM vouchers v JOIN packages p ON v.package_id = p.id
             WHERE v.is_used = 1 AND v.used_at >= DATE_SUB(NOW(), INTERVAL '.$days.' DAY)'
        );

        return [
            'totalViews' => (int) ($redemptions->count ?? 0),
            'completionRate' => $activeRate->rate ?? 0,
            'avgWatchTime' => (int) round((float) ($avgDuration->avgHours ?? 0)),
        ];
    }

    public function getAnalyticsStats(): JsonResponse
    {
        try {
            $core = $this->analyticsCore(30);
            $payments = DB::selectOne("SELECT COUNT(*) as count FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $uniqueUsers = DB::selectOne('SELECT COUNT(DISTINCT used_by) as count FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');

            return ApiResponse::json(array_merge([
                'success' => true,
                'totalClicks' => (int) ($payments->count ?? 0),
                'uniqueViewers' => (int) ($uniqueUsers->count ?? 0),
            ], $core));
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata takwimu za analytics');
        }
    }

    public function adminAnalytics(): JsonResponse
    {
        try {
            $core = $this->analyticsCore(30);
            $salesTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $daily = DB::selectOne('SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?', [$date]);
                $salesTrend[] = ['date' => $date, 'total' => $this->num($daily->total ?? 0)];
            }
            $popularPackages = DB::select(
                'SELECT p.name, COUNT(*) as count,
                        ROUND((COUNT(*) / (SELECT COUNT(*) FROM vouchers WHERE is_used = 1) * 100), 2) as percentage
                 FROM vouchers v JOIN packages p ON v.package_id = p.id
                 WHERE v.is_used = 1 GROUP BY p.name ORDER BY count DESC LIMIT 5'
            );
            $customersByLocation = DB::select(
                "SELECT location, COUNT(*) as count,
                        ROUND((COUNT(*) / (SELECT COUNT(*) FROM users WHERE role = 'customer') * 100), 2) as percentage
                 FROM users WHERE role = 'customer' GROUP BY location ORDER BY count DESC LIMIT 5"
            );

            return ApiResponse::json(array_merge([
                'success' => true,
                'salesTrend' => $salesTrend,
                'popularPackages' => $popularPackages,
                'customersByLocation' => $customersByLocation,
            ], $core));
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata takwimu');
        }
    }

    public function reportsData(): JsonResponse
    {
        try {
            $vouchers = DB::selectOne('SELECT COUNT(*) as count FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $newUsers = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND role = "customer"');
            $pendingRouters = DB::selectOne("SELECT COUNT(*) as count FROM mikrotiks WHERE setup_status = 'pending'");

            return ApiResponse::json([
                'success' => true,
                'totalVouchersUsed' => (int) ($vouchers->count ?? 0),
                'newUsers' => (int) ($newUsers->count ?? 0),
                'pendingRouters' => (int) ($pendingRouters->count ?? 0),
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata data ya ripoti');
        }
    }

    public function getAnalyticsData(): JsonResponse
    {
        try {
            $core = $this->analyticsCore(30);
            $payments = DB::selectOne("SELECT COUNT(*) as count FROM clickpesa_transactions WHERE status IN ('SUCCESS','SETTLED') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $uniqueUsers = DB::selectOne('SELECT COUNT(DISTINCT used_by) as count FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $viewsTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $daily = DB::selectOne('SELECT COUNT(*) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?', [$date]);
                $viewsTrend[] = ['date' => $date, 'total' => (int) ($daily->total ?? 0)];
            }

            return ApiResponse::json([
                'success' => true,
                'data' => [
                    'totalViews' => $core['totalViews'],
                    'completionRate' => $core['completionRate'],
                    'avgWatchTime' => $core['avgWatchTime'],
                    'totalClicks' => (int) ($payments->count ?? 0),
                    'uniqueViewers' => (int) ($uniqueUsers->count ?? 0),
                    'viewsTrend' => $viewsTrend,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata data ya analytics');
        }
    }

    // ─────────────────────────── Customers ───────────────────────────

    public function getCustomers(Request $request): JsonResponse
    {
        try {
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 50, 1, 100000);
            $offset = ($page - 1) * $limit;
            $search = $request->query('search');
            $params = [];
            $extra = '';
            if ($search) {
                $extra = ' AND (phone_number LIKE ? OR name LIKE ? OR mac_address LIKE ? OR location LIKE ?)';
                $term = '%'.$search.'%';
                array_push($params, $term, $term, $term, $term);
            }
            $customers = DB::select(
                "SELECT id, name, phone_number, package, location, moneyspent, usage_start, usage_until,
                        free_bytes / (1024*1024) as free_mb, mac_address, last_router_id, is_active, created_at, updated_at
                 FROM users WHERE role = 'customer' {$extra}
                 ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );
            $totalRow = DB::selectOne("SELECT COUNT(*) as total FROM users WHERE role = \"customer\" {$extra}", $params);
            $total = (int) ($totalRow->total ?? 0);

            return ApiResponse::json([
                'success' => true,
                'data' => $customers,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata wateja');
        }
    }

    public function getOnlineCustomers(): JsonResponse
    {
        try {
            $customers = DB::select(
                "SELECT u.id, u.name, u.phone_number, u.mac_address, u.location, u.last_router_id, u.usage_until,
                        TIMESTAMPDIFF(MINUTE, u.updated_at, NOW()) as minutes_ago,
                        m.router_name, m.status as router_status
                 FROM users u LEFT JOIN mikrotiks m ON u.last_router_id = m.router_id
                 WHERE u.usage_until > NOW() AND u.updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                   AND u.role = 'customer'
                 ORDER BY u.updated_at DESC"
            );

            return ApiResponse::json(['success' => true, 'data' => $customers, 'count' => count($customers)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata wateja walio online');
        }
    }

    public function suspendCustomer(Request $request, int|string $id): JsonResponse
    {
        try {
            $user = DB::table('users')->where('id', $id)->where('role', 'customer')->first(['id', 'name']);
            if (! $user) {
                return $this->plainFail('Mtumiaji hakupatikana', 404);
            }
            $permanent = $request->input('is_permanent') ? 1 : 0;
            DB::table('user_suspensions')->insert([
                'user_id' => $id,
                'reason' => $request->input('reason') ?: 'Imesimamishwa na msimamizi',
                'suspended_by' => $this->uid($request),
                'suspended_until' => $permanent ? null : ($request->input('suspended_until') ?: null),
                'is_permanent' => $permanent,
                'created_at' => now(),
            ]);
            DB::table('users')->where('id', $id)->update(['usage_until' => now(), 'is_active' => 0]);

            return ApiResponse::json(['success' => true, 'message' => 'Mtumiaji amesimamishwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kusimamisha mtumiaji');
        }
    }

    public function unsuspendCustomer(int|string $id): JsonResponse
    {
        try {
            DB::table('users')->where('id', $id)->update(['is_active' => 1]);

            return ApiResponse::json(['success' => true, 'message' => 'Mtumiaji amerejeshwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kurejesha mtumiaji');
        }
    }

    public function adjustCustomerSubscription(Request $request, int|string $id): JsonResponse
    {
        try {
            $values = [];
            foreach (['usage_until' => 'usage_until', 'package_name' => 'package', 'free_bytes' => 'free_bytes'] as $input => $column) {
                if ($request->filled($input) || ($input === 'free_bytes' && $request->exists('free_bytes'))) {
                    $values[$column] = $request->input($input);
                }
            }
            $values['updated_at'] = now();
            DB::table('users')->where('id', $id)->update($values);

            return ApiResponse::json(['success' => true, 'message' => 'Usajili wa mtumiaji umeongezewa muda kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kurekebisha usajili');
        }
    }

    public function deleteCustomer(int|string $id): JsonResponse
    {
        try {
            $deleted = DB::table('users')->where('id', $id)->where('role', 'customer')->delete();
            if (! $deleted) {
                return $this->plainFail('Mtumiaji hakupatikana', 404);
            }

            return ApiResponse::json(['success' => true, 'message' => 'Mtumiaji amefutwa kikamilifu', 'deletedId' => $id]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kufuta mtumiaji');
        }
    }

    // ─────────────────────────── Settings / users ───────────────────────────

    public function getSystemSettings(): JsonResponse
    {
        try {
            $settings = DB::table('system_settings')->get();

            return ApiResponse::json(['success' => true, 'data' => $settings, 'count' => $settings->count()]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata mipangilio');
        }
    }

    public function updateSystemSettings(Request $request): JsonResponse
    {
        try {
            $settings = $request->input('settings');
            if (! is_array($settings) || ! $settings) {
                $key = $request->input('setting_key', $request->input('id'));
                if ($key === null) {
                    return $this->plainFail('Hakuna mipangilio iliyowasilishwa');
                }
                $settings = [$key => $request->input('setting_value')];
            }
            foreach ($settings as $key => $value) {
                if (is_array($value)) {
                    $value = Meshline::encode($value);
                }
                DB::table('system_settings')->where('setting_key', $key)->update([
                    'setting_value' => $value,
                    'updated_at' => now(),
                ]);
            }

            return ApiResponse::json(['success' => true, 'message' => 'Mipangilio ya mfumo imesasishwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kusasisha mipangilio');
        }
    }

    public function getNotifications(): JsonResponse
    {
        try {
            $notifications = DB::select(
                'SELECT n.*, u.name as user_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id
                 WHERE n.is_read = 0 ORDER BY n.created_at DESC LIMIT 50'
            );

            return ApiResponse::json(['success' => true, 'data' => $notifications, 'count' => count($notifications)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata arifa');
        }
    }

    public function markNotificationRead(int|string $id): JsonResponse
    {
        try {
            if (! DB::table('notifications')->where('id', $id)->exists()) {
                return $this->plainFail('Arifa hakupatikana', 404);
            }
            DB::table('notifications')->where('id', $id)->update(['is_read' => 1]);

            return ApiResponse::json(['success' => true, 'message' => 'Arifa imesomwa']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kuweka arifa kama ilivyosomwa');
        }
    }

    public function setUnlimitedStatus(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        try {
            if (! DB::table('users')->where('id', $userId)->exists()) {
                return $this->plainFail('Mtumiaji hakupatikana', 404);
            }
            $now = now();
            if ($request->input('is_unlimited')) {
                DB::table('users')->where('id', $userId)->update([
                    'package' => 'UNLIMITED',
                    'usage_start' => $now,
                    'usage_until' => '2030-12-31 23:59:59',
                    'updated_at' => $now,
                ]);

                return ApiResponse::json(['success' => true, 'message' => 'Mtumiaji amepewa ufikiaji usio na kikomo hadi 2030']);
            }
            DB::table('users')->where('id', $userId)->update([
                'package' => 'HAKUNA KIFURUSHI',
                'usage_start' => $now,
                'usage_until' => $now,
                'updated_at' => $now,
            ]);

            return ApiResponse::json(['success' => true, 'message' => 'Ufikiaji usio na kikomo umeondolewa kwa mtumiaji']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kuweka hali ya mtumiaji');
        }
    }

    public function deleteUser(int|string $id): JsonResponse
    {
        try {
            $user = DB::table('users')->where('id', $id)->first(['id', 'role']);
            if (! $user) {
                return $this->plainFail('Mtumiaji hakupatikana', 404);
            }
            if ($user->role !== 'customer') {
                return ApiResponse::json(['error' => 'Cannot delete non-customer users'], 403);
            }
            DB::table('users')->where('id', $id)->delete();

            return ApiResponse::json(['success' => true, 'message' => 'Mtumiaji amefutwa kikamilifu', 'deletedId' => $id]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kufuta mtumiaji');
        }
    }

    public function getUserStats(): JsonResponse
    {
        try {
            $total = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE role = "customer"');
            $active = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE usage_until > NOW() AND role = "customer"');
            $today = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE() AND role = "customer"');
            $revenue = DB::selectOne('SELECT COALESCE(SUM(moneyspent), 0) as total FROM users WHERE role = "customer"');

            return ApiResponse::json([
                'success' => true,
                'data' => [
                    'totalUsers' => (int) ($total->count ?? 0),
                    'activeUsers' => (int) ($active->count ?? 0),
                    'newUsersToday' => (int) ($today->count ?? 0),
                    'totalRevenue' => $this->num($revenue->total ?? 0),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata takwimu za watumiaji');
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        $adminId = $this->uid($request);
        $current = $request->input('current_password');
        $new = $request->input('new_password');
        $confirm = $request->input('confirm_password');
        if (! $adminId) {
            return $this->plainFail('Admin access required', 401);
        }
        if (! $current || ! $new || ! $confirm) {
            return $this->plainFail('Jaza sehemu zote.');
        }
        if ($new !== $confirm) {
            return $this->plainFail('Neno la siri jipya halifanani.');
        }
        if (mb_strlen((string) $new) < 8) {
            return $this->plainFail('Neno la siri linapaswa kuwa na herufi angalau 8.');
        }
        try {
            $user = DB::table('users')->where('id', $adminId)->first(['password']);
            if (! $user || ! Hash::check((string) $current, (string) $user->password)) {
                return $this->plainFail('Neno la siri la sasa sio sahihi.');
            }
            $rounds = (int) (config('hashing.bcrypt.rounds', env('BCRYPT_ROUNDS', 12)));
            DB::table('users')->where('id', $adminId)->update([
                'password' => Hash::make((string) $new, ['rounds' => $rounds]),
                'updated_at' => now(),
            ]);

            return ApiResponse::json(['success' => true, 'message' => 'Neno la siri limebadilishwa.']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kubadilisha neno la siri.');
        }
    }

    public function getRecentActivity(Request $request): JsonResponse
    {
        try {
            $limit = $this->clampInt($request->query('limit'), 20, 1, 1000);
            $activities = [];
            $users = DB::select('SELECT id, name, phone_number, role, created_at FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT '.$limit);
            foreach ($users as $user) {
                $role = $user->role;
                $activities[] = [
                    'created_at' => $user->created_at,
                    'description' => 'Mtumiaji mpya amejiunga',
                    'user_name' => $user->name ?: $user->phone_number,
                    'status_text' => $role === 'agent' ? 'Agent' : 'Customer',
                    'status_class' => $role === 'agent'
                        ? 'bg-blue-100 text-blue-800'
                        : 'bg-green-100 text-green-800',
                ];
            }
            $vouchers = DB::select(
                'SELECT v.used_at, u.name, u.phone_number, p.name as package_name
                 FROM vouchers v JOIN users u ON v.used_by = u.id JOIN packages p ON v.package_id = p.id
                 WHERE v.is_used = 1 AND v.used_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY v.used_at DESC LIMIT '.$limit
            );
            foreach ($vouchers as $voucher) {
                $activities[] = [
                    'created_at' => $voucher->used_at,
                    'description' => "Ametumia voucher: {$voucher->package_name}",
                    'user_name' => $voucher->name ?: $voucher->phone_number,
                    'status_text' => 'Voucher',
                    'status_class' => 'bg-yellow-100 text-yellow-800',
                ];
            }
            $payments = DB::select(
                'SELECT p.created_at, u.name, u.phone_number, p.amount, p.status
                 FROM payments p JOIN users u ON p.user_id = u.id
                 WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY p.created_at DESC LIMIT '.$limit
            );
            foreach ($payments as $payment) {
                $status = $payment->status;
                $activities[] = [
                    'created_at' => $payment->created_at,
                    'description' => 'Malipo: TZS '.number_format((float) $payment->amount),
                    'user_name' => $payment->name ?: $payment->phone_number,
                    'status_text' => $status === 'completed' ? 'Imekamilika' : ($status === 'pending' ? 'Inasubiri' : 'Imefeli'),
                    'status_class' => $status === 'completed'
                        ? 'bg-green-100 text-green-800'
                        : ($status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'),
                ];
            }
            usort($activities, static fn ($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
            $activities = array_slice($activities, 0, $limit);

            return ApiResponse::json(['success' => true, 'activities' => $activities, 'count' => count($activities)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupakia shughuli');
        }
    }
}
