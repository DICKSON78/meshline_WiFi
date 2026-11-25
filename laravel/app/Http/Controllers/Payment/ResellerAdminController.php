<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Port of the reseller-v2 router assignment pages in routes/resellerAdmin.js.
 * Internet service only: no equipment plans, loans, repayments or partner payouts.
 */
class ResellerAdminController extends Controller
{
    public function routers(): JsonResponse
    {
        try {
            $routers = $this->allRouters();
            $active = $this->activeAssignments();
            $byRouter = [];
            foreach ($active as $assignment) {
                $byRouter[(string) $assignment->router_id] = $assignment;
            }

            return ApiResponse::json([
                'routers' => array_map(fn ($r) => [...$r, 'assignment' => $byRouter[$r['key']] ?? null], $routers),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to load routers', $e);
        }
    }

    public function overview(): JsonResponse
    {
        try {
            $resellers = DB::table('users as u')->leftJoin('resellers as r', 'r.user_id', '=', 'u.id')
                ->where('u.role', 'reseller')->orderByDesc('u.id')
                ->select('u.id', 'u.name', 'u.company_name', 'u.phone_number', 'u.email', 'u.is_active',
                    'r.balance', 'r.total_earned', 'r.tier')->get();
            $active = $this->activeAssignments();
            $rows = $resellers->map(function ($u) use ($active) {
                return [
                    ...(array) $u,
                    'balance' => (float) ($u->balance ?? 0),
                    'total_earned' => (float) ($u->total_earned ?? 0),
                    'routers' => array_map(fn ($a) => [
                        'router_id' => $a->router_id,
                        'display_name' => $a->display_name,
                        'commission_percent' => (float) $a->commission_percent,
                    ], array_values(array_filter($active->all(), fn ($a) => (int) $a->reseller_user_id === (int) $u->id))),
                ];
            });

            return ApiResponse::json(['resellers' => $rows]);
        } catch (\Throwable $e) {
            return $this->error('Failed to load resellers', $e);
        }
    }

    public function createReseller(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $company = trim((string) $request->input('company_name', '')) ?: null;
        $email = trim((string) $request->input('email', '')) ?: null;
        $phone = $this->normPhone($request->input('phone'));
        $password = (string) $request->input('password', '');
        if (strlen($name) < 2 || strlen($name) > 100) {
            return $this->err('Name must be 2-100 characters', 400);
        }
        if (! $phone) {
            return $this->err('Phone must look like 2557XXXXXXXX or 07XXXXXXXX', 400);
        }
        if (strlen($password) < 8) {
            return $this->err('Password must be at least 8 characters', 400);
        }
        try {
            if (DB::table('users')->where('phone_number', $phone)->exists()) {
                return $this->err('A user with this phone already exists', 409);
            }
            if ($email && DB::table('users')->where('email', $email)->exists()) {
                return $this->err('A user with this email already exists', 409);
            }
            $uid = DB::transaction(function () use ($name, $company, $email, $phone, $password): int {
                $id = $this->insertRow('users', [
                    'name' => $name, 'phone_number' => $phone, 'email' => $email, 'company_name' => $company,
                    'password' => Hash::make($password), 'role' => 'reseller', 'is_active' => 1, 'is_verified' => 1,
                ]);
                $this->insertRow('resellers', [
                    'user_id' => $id, 'balance' => 0, 'total_earned' => 0, 'status' => 'active', 'tier' => 'bronze',
                    'total_vouchers_sold' => 0, 'total_customers' => 0, 'created_at' => now(),
                ]);

                return $id;
            }, 3);

            return ApiResponse::json(['success' => true, 'id' => $uid], 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create reseller', $e);
        }
    }

    public function assignRouter(Request $request): JsonResponse
    {
        try {
            $rid = trim((string) $request->input('router_id', ''));
            $resellerId = (int) $request->input('reseller_user_id');
            $display = trim((string) $request->input('display_name', ''));
            $rawPct = $request->input('commission_percent');
            $pct = ($rawPct === null || $rawPct === '') ? 20.0 : $this->pct($rawPct);
            $rename = $request->input('rename_router') === true || $request->input('rename_router') === 'true';
            if ($rid === '' || $resellerId <= 0) {
                return $this->err('router_id and reseller_user_id are required', 400);
            }
            if (strlen($display) < 2 || strlen($display) > 80) {
                return $this->err('Router name must be 2-80 characters', 400);
            }
            if ($pct === null || is_nan($pct)) {
                return $this->err('HQ percent must be between 0 and 100', 400);
            }
            $target = null;
            foreach ($this->allRouters() as $router) {
                if ($router['key'] === $rid) {
                    $target = $router;
                    break;
                }
            }
            if (! $target) {
                return $this->err('Unknown router_id', 404);
            }
            if (! DB::table('users')->where('id', $resellerId)->where('role', 'reseller')
                ->where('is_active', 1)->exists()) {
                return $this->err('Active reseller not found', 404);
            }
            $actor = LegacySession::id($request);
            DB::transaction(function () use ($rid, $resellerId, $display, $pct, $rename, $actor): void {
                $old = DB::table('router_assignments')->where('router_id', $rid)->whereNull('unassigned_at')
                    ->lockForUpdate()->pluck('reseller_user_id')->all();
                DB::table('router_assignments')->where('router_id', $rid)->whereNull('unassigned_at')->update([
                    'unassigned_at' => DB::raw('NOW()'), 'unassigned_by' => $actor,
                ]);
                DB::table('router_assignments')->insert([
                    'router_id' => $rid, 'reseller_user_id' => $resellerId, 'display_name' => $display,
                    'commission_percent' => $pct, 'assigned_by' => $actor,
                ]);
                if ($rename) {
                    DB::table('mikrotiks')->where('router_id', $rid)->update(['router_name' => $display]);
                }
                foreach (array_unique([$resellerId, ...$old]) as $uid) {
                    $this->syncResellerRouter((int) $uid);
                }
            }, 3);

            return ApiResponse::json(['success' => true, 'renamed' => $rename, 'previous_name' => $target['name']], 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to assign router', $e);
        }
    }

    public function unassignRouter(Request $request): JsonResponse
    {
        $rid = trim((string) $request->input('router_id', ''));
        if ($rid === '') {
            return $this->err('router_id is required', 400);
        }
        try {
            $closed = DB::transaction(function () use ($rid, $request): int {
                $old = DB::table('router_assignments')->where('router_id', $rid)->whereNull('unassigned_at')
                    ->lockForUpdate()->pluck('reseller_user_id')->all();
                $count = DB::table('router_assignments')->where('router_id', $rid)->whereNull('unassigned_at')->update([
                    'unassigned_at' => DB::raw('NOW()'), 'unassigned_by' => LegacySession::id($request),
                ]);
                foreach (array_unique($old) as $uid) {
                    $this->syncResellerRouter((int) $uid);
                }

                return $count;
            }, 3);

            return ApiResponse::ok(['closed' => $closed]);
        } catch (\Throwable $e) {
            return $this->error('Failed to unassign router', $e);
        }
    }

    public function history(string $routerId): JsonResponse
    {
        $rows = DB::table('router_assignments as a')->leftJoin('users as u', 'u.id', '=', 'a.reseller_user_id')
            ->where('a.router_id', $routerId)->orderByDesc('a.assigned_at')
            ->select('a.id', 'a.router_id', 'a.display_name', 'a.commission_percent', 'a.assigned_at',
                'a.unassigned_at', 'u.name as reseller_name', 'u.company_name')->get();

        return ApiResponse::json(['history' => $rows]);
    }

    // ---------------------------------------------------------------- helpers

    private function normPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '255'.substr($digits, 1);
        }

        return preg_match('/^255\d{9}$/', $digits) ? $digits : null;
    }

    private function pct(mixed $value, mixed $default = null): ?float
    {
        if ($value === null || $value === '') {
            return $default === null ? null : (float) $default;
        }
        $n = (float) $value;
        if (! is_finite($n) || $n < 0 || $n > 100) {
            return NAN;
        }

        return round($n * 100) / 100;
    }

    private function allRouters(): array
    {
        return DB::table('mikrotiks')->select('id', 'router_id', 'router_name', 'status', 'is_active', 'location_name')
            ->get()->map(static fn ($r) => [
                'key' => (string) ($r->router_id ?: $r->id),
                'id' => $r->id,
                'name' => $r->router_name,
                'status' => $r->status,
                'is_active' => $r->is_active,
                'location' => $r->location_name,
            ])->all();
    }

    private function activeAssignments(): \Illuminate\Support\Collection
    {
        return DB::table('router_assignments as a')->leftJoin('users as u', 'u.id', '=', 'a.reseller_user_id')
            ->whereNull('a.unassigned_at')
            ->select('a.id', 'a.router_id', 'a.reseller_user_id', 'a.display_name', 'a.commission_percent',
                'a.assigned_at', 'u.name as reseller_name', 'u.company_name', 'u.phone_number')
            ->get();
    }

    private function syncResellerRouter(int $resellerUserId): void
    {
        if ($resellerUserId <= 0) {
            return;
        }
        $active = DB::table('router_assignments')->where('reseller_user_id', $resellerUserId)
            ->whereNull('unassigned_at')->orderByDesc('assigned_at')->orderByDesc('id')->first(['router_id']);
        $mid = null;
        if ($active) {
            $router = DB::table('mikrotiks')->where('router_id', $active->router_id)->first(['id']);
            $mid = $router ? $router->id : null;
        }
        DB::table('resellers')->where('user_id', $resellerUserId)->update(['router_id' => $mid]);
    }

    /** Fill NOT NULL columns without defaults when creating a reseller user. */
    private function insertRow(string $table, array $provided): int
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
            throw new \InvalidArgumentException('Unsafe table name');
        }
        $info = DB::select(
            'SELECT COLUMN_NAME AS c, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, EXTRA AS e, DATA_TYPE AS t, COLUMN_TYPE AS ct
             FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        $row = [];
        foreach ($info as $col) {
            if (preg_match('/auto_increment|generated/i', (string) $col->e)) {
                continue;
            }
            if (array_key_exists($col->c, $provided)) {
                $row[$col->c] = $provided[$col->c];
                continue;
            }
            if ($col->n === 'NO' && $col->d === null) {
                $type = strtolower((string) $col->t);
                if (preg_match('/int|decimal|float|double|bit/', $type)) {
                    $row[$col->c] = 0;
                } elseif (preg_match('/date|time/', $type)) {
                    $row[$col->c] = now();
                } elseif ($type === 'enum') {
                    preg_match("/enum\\('([^']*)'/", (string) $col->ct, $m);
                    $row[$col->c] = $m[1] ?? '';
                } elseif ($type === 'json') {
                    $row[$col->c] = '{}';
                } else {
                    $row[$col->c] = '';
                }
            }
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    private function err(string $message, int $status): JsonResponse
    {
        return ApiResponse::json(['error' => $message], $status);
    }

    private function error(string $message, \Throwable $e): JsonResponse
    {
        Log::error('resellers-v2 error', ['message' => $message, 'error' => $e->getMessage()]);
        $response = ['error' => $message];
        if (config('app.debug')) {
            $response['detail'] = $e->getMessage();
        }

        return ApiResponse::json($response, 500);
    }
}
