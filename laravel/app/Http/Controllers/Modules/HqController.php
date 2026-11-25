<?php

namespace App\Http\Controllers\Modules;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Hotbando;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Port of controllers/hqController.js (mounted at /api/admin/hq).
 * Every response keeps the legacy {success, data} envelope.
 */
class HqController extends Controller
{
    /** @return array<int,array<string,mixed>> */
    private static function rows(string $sql, array $bindings = []): array
    {
        return array_map(static fn ($row) => (array) $row, DB::select($sql, $bindings));
    }

    /** @return array<string,mixed>|null */
    private static function one(string $sql, array $bindings = []): ?array
    {
        $rows = self::rows($sql, $bindings);

        return $rows[0] ?? null;
    }

    private static function count(string $sql, array $bindings = []): int
    {
        return (int) (self::one($sql, $bindings)['total'] ?? 0);
    }

    private static function ok(array $data): JsonResponse
    {
        return ApiResponse::json(['success' => true, 'data' => $data]);
    }

    private static function fail(string $message, int $status = 500): JsonResponse
    {
        return ApiResponse::json(['success' => false, 'error' => $message], $status);
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private static function paging(Request $request, int $defaultLimit = 20): array
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', $defaultLimit);

        return [$page, $limit, $limit * ($page - 1), $limit];
    }

    private static function adminId(Request $request): ?int
    {
        $user = $request->session()->get('admin_user');

        return is_array($user) && isset($user['id']) ? (int) $user['id'] : LegacySession::id($request);
    }

    /** The legacy audit_trail row stores JSON for both sides of a change. */
    private static function audit(Request $request, string $entityType, string $action, ?int $entityId, ?array $old, ?array $new, ?string $description): void
    {
        try {
            DB::table('audit_trail')->insert([
                'user_id' => self::adminId($request),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $old === null ? null : Hotbando::encode($old),
                'new_values' => $new === null ? null : Hotbando::encode($new),
                'description' => $description,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // logAudit never breaks the calling request in the Express app.
        }
    }

    // ==================== REGIONS ====================

    public function getRegions()
    {
        try {
            return self::ok(self::rows("
                SELECT r.*, u.name AS manager_name,
                  (SELECT COUNT(*) FROM districts d WHERE d.region_id = r.id) AS district_count,
                  (SELECT COUNT(*) FROM branches b
                    JOIN districts d2 ON b.district_id = d2.id
                    WHERE d2.region_id = r.id) AS branch_count,
                  (SELECT COUNT(*) FROM wards w
                    JOIN branches b3 ON w.branch_id = b3.id
                    JOIN districts d3 ON b3.district_id = d3.id
                    WHERE d3.region_id = r.id) AS ward_count
                FROM regions r
                LEFT JOIN users u ON r.manager_id = u.id
                ORDER BY r.name
            "));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createRegion(Request $request)
    {
        try {
            $id = DB::table('regions')->insertGetId([
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'manager_id' => $request->input('manager_id') ?: null,
                'description' => $request->input('description') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'region', 'create', (int) $id, null, $request->all(), 'Region created');

            return self::ok(['id' => (int) $id, 'message' => 'Region created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateRegion(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM regions WHERE id = ?', [$id]);
            $new = [
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'manager_id' => $request->input('manager_id') ?: null,
                'description' => $request->input('description') ?: null,
            ];
            DB::table('regions')->where('id', $id)->update($new);
            self::audit($request, 'region', 'update', $id, $old, $new, 'Region updated');

            return self::ok(['message' => 'Region updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== DISTRICTS ====================

    public function getDistricts(Request $request)
    {
        try {
            $sql = '
                SELECT d.*, r.name AS region_name,
                  (SELECT COUNT(*) FROM branches b WHERE b.district_id = d.id) AS branch_count,
                  (SELECT COUNT(*) FROM wards w
                    JOIN branches b2 ON w.branch_id = b2.id
                    WHERE b2.district_id = d.id) AS ward_count
                FROM districts d
                LEFT JOIN regions r ON d.region_id = r.id
            ';
            $bindings = [];
            if ($request->query('region_id')) {
                $sql .= ' WHERE d.region_id = ?';
                $bindings[] = $request->query('region_id');
            }
            $sql .= ' ORDER BY d.name';

            return self::ok(self::rows($sql, $bindings));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createDistrict(Request $request)
    {
        try {
            $id = DB::table('districts')->insertGetId([
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'region_id' => $request->input('region_id'),
                'description' => $request->input('description') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'district', 'create', (int) $id, null, $request->all(), 'District created');

            return self::ok(['id' => (int) $id, 'message' => 'District created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateDistrict(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM districts WHERE id = ?', [$id]);
            $new = [
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'region_id' => $request->input('region_id'),
                'description' => $request->input('description') ?: null,
            ];
            DB::table('districts')->where('id', $id)->update($new);
            self::audit($request, 'district', 'update', $id, $old, $new, 'District updated');

            return self::ok(['message' => 'District updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== BRANCHES ====================

    public function getBranches(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('search')) {
                $where[] = '(b.name LIKE ? OR b.code LIKE ?)';
                $search = '%'.$request->query('search').'%';
                $bindings[] = $search;
                $bindings[] = $search;
            }
            if ($request->query('district_id')) {
                $where[] = 'b.district_id = ?';
                $bindings[] = $request->query('district_id');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM branches b $whereSQL", $bindings);

            $rows = self::rows("
                SELECT b.*, d.name AS district_name, r.name AS region_name,
                  u.name AS manager_name,
                  (SELECT COUNT(*) FROM staff s WHERE s.branch_id = b.id) AS staff_count,
                  (SELECT COUNT(*) FROM wards w WHERE w.branch_id = b.id) AS ward_count,
                  (SELECT COUNT(*) FROM mikrotiks m WHERE m.is_active = 1) AS router_count
                FROM branches b
                LEFT JOIN districts d ON b.district_id = d.id
                LEFT JOIN regions r ON d.region_id = r.id
                LEFT JOIN users u ON b.manager_id = u.id
                $whereSQL
                ORDER BY b.name
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['branches' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getBranchDetails(int $id)
    {
        try {
            $branch = self::one("
                SELECT b.*, d.name AS district_name, r.name AS region_name,
                  u.name AS manager_name
                FROM branches b
                LEFT JOIN districts d ON b.district_id = d.id
                LEFT JOIN regions r ON d.region_id = r.id
                LEFT JOIN users u ON b.manager_id = u.id
                WHERE b.id = ?
            ", [$id]);
            if ($branch === null) {
                return self::fail('Branch not found', 404);
            }
            $branch['staff_count'] = self::one('SELECT COUNT(*) AS count FROM staff WHERE branch_id = ?', [$id])['count'] ?? 0;
            $branch['ward_count'] = self::one('SELECT COUNT(*) AS count FROM wards WHERE branch_id = ?', [$id])['count'] ?? 0;
            $branch['router_count'] = self::one('SELECT COUNT(*) AS count FROM mikrotiks WHERE branch_id = ?', [$id])['count'] ?? 0;
            $branch['total_expenses'] = self::one("SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE branch_id = ? AND status = 'approved'", [$id])['total'] ?? 0;

            return self::ok($branch);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createBranch(Request $request)
    {
        try {
            $id = DB::table('branches')->insertGetId([
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'district_id' => $request->input('district_id'),
                'manager_id' => $request->input('manager_id') ?: null,
                'address' => $request->input('address') ?: null,
                'phone' => $request->input('phone') ?: null,
                'email' => $request->input('email') ?: null,
                'latitude' => $request->input('latitude') ?: null,
                'longitude' => $request->input('longitude') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'branch', 'create', (int) $id, null, $request->all(), 'Branch created');

            return self::ok(['id' => (int) $id, 'message' => 'Branch created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateBranch(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM branches WHERE id = ?', [$id]);
            $new = [
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'district_id' => $request->input('district_id'),
                'manager_id' => $request->input('manager_id') ?: null,
                'address' => $request->input('address') ?: null,
                'phone' => $request->input('phone') ?: null,
                'email' => $request->input('email') ?: null,
                'latitude' => $request->input('latitude') ?: null,
                'longitude' => $request->input('longitude') ?: null,
                'is_active' => $request->input('is_active') !== null ? $request->input('is_active') : 1,
            ];
            DB::table('branches')->where('id', $id)->update($new);
            self::audit($request, 'branch', 'update', $id, $old, $new, 'Branch updated');

            return self::ok(['message' => 'Branch updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== WARDS ====================

    public function getWards(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('branch_id')) {
                $where[] = 'w.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('district_id')) {
                $where[] = 'b.district_id = ?';
                $bindings[] = $request->query('district_id');
            }
            if ($request->query('region_id')) {
                $where[] = 'd.region_id = ?';
                $bindings[] = $request->query('region_id');
            }
            if ($request->query('search')) {
                $where[] = '(w.name LIKE ? OR w.code LIKE ?)';
                $search = '%'.$request->query('search').'%';
                array_push($bindings, $search, $search);
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("
                SELECT COUNT(*) AS total
                FROM wards w
                JOIN branches b ON w.branch_id = b.id
                JOIN districts d ON b.district_id = d.id
                $whereSQL
            ", $bindings);
            $rows = self::rows("
                SELECT w.*, b.name AS branch_name, d.name AS district_name,
                  r.name AS region_name, u.name AS supervisor_name
                FROM wards w
                JOIN branches b ON w.branch_id = b.id
                JOIN districts d ON b.district_id = d.id
                LEFT JOIN regions r ON d.region_id = r.id
                LEFT JOIN users u ON w.supervisor_id = u.id
                $whereSQL
                ORDER BY w.name
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['wards' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getWardDetails(int $id)
    {
        try {
            $ward = self::one("
                SELECT w.*, b.name AS branch_name, d.name AS district_name,
                  r.name AS region_name, u.name AS supervisor_name
                FROM wards w
                JOIN branches b ON w.branch_id = b.id
                JOIN districts d ON b.district_id = d.id
                LEFT JOIN regions r ON d.region_id = r.id
                LEFT JOIN users u ON w.supervisor_id = u.id
                WHERE w.id = ?
            ", [$id]);
            if ($ward === null) {
                return self::fail('Ward not found', 404);
            }

            return self::ok($ward);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createWard(Request $request)
    {
        try {
            $id = DB::table('wards')->insertGetId([
                'branch_id' => $request->input('branch_id'),
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'description' => $request->input('description') ?: null,
                'supervisor_id' => $request->input('supervisor_id') ?: null,
                'is_active' => $request->input('is_active', 1),
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'ward', 'create', (int) $id, null, $request->all(), 'Ward created');

            return self::ok(['id' => (int) $id, 'message' => 'Ward created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateWard(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM wards WHERE id = ?', [$id]);
            $new = [
                'branch_id' => $request->input('branch_id'),
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'description' => $request->input('description') ?: null,
                'supervisor_id' => $request->input('supervisor_id') ?: null,
                'is_active' => $request->input('is_active', 1),
            ];
            DB::table('wards')->where('id', $id)->update($new);
            self::audit($request, 'ward', 'update', $id, $old, $new, 'Ward updated');

            return self::ok(['message' => 'Ward updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== STAFF / HR ====================

    public function getStaff(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('search')) {
                $where[] = '(u.name LIKE ? OR s.employee_id LIKE ? OR u.email LIKE ?)';
                $search = '%'.$request->query('search').'%';
                array_push($bindings, $search, $search, $search);
            }
            if ($request->query('branch_id')) {
                $where[] = 's.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('department')) {
                $where[] = 's.department = ?';
                $bindings[] = $request->query('department');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM staff s JOIN users u ON s.user_id = u.id $whereSQL", $bindings);
            $rows = self::rows("
                SELECT s.*, u.name, u.email, u.phone_number, u.is_active AS user_active,
                  b.name AS branch_name, d.name AS district_name, r.name AS region_name
                FROM staff s
                JOIN users u ON s.user_id = u.id
                LEFT JOIN branches b ON s.branch_id = b.id
                LEFT JOIN districts d ON b.district_id = d.id
                LEFT JOIN regions r ON d.region_id = r.id
                $whereSQL
                ORDER BY u.name
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['staff' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createStaff(Request $request)
    {
        try {
            $tempPassword = bin2hex(random_bytes(8));
            $hashedPassword = Hash::make($tempPassword, ['cost' => 12]);
            $userId = DB::table('users')->insertGetId([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone_number' => $request->input('phone') ?: null,
                'password' => $hashedPassword,
                'role' => 'admin',
                'is_active' => 1,
                'created_at' => DB::raw('NOW()'),
            ]);
            $staffId = DB::table('staff')->insertGetId([
                'user_id' => $userId,
                'employee_id' => $request->input('employee_id'),
                'branch_id' => $request->input('branch_id') ?: null,
                'department' => $request->input('department') ?: null,
                'job_title' => $request->input('job_title') ?: null,
                'salary' => $request->input('salary') ?: null,
                'hire_date' => $request->input('hire_date') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'staff', 'create', (int) $staffId, null, $request->all(), 'Staff created');

            return self::ok([
                'id' => (int) $staffId,
                'user_id' => (int) $userId,
                'temp_password' => $tempPassword,
                'message' => 'Staff created',
            ]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateStaff(Request $request, int $id)
    {
        try {
            $staff = self::one('SELECT user_id FROM staff WHERE id = ?', [$id]);
            if ($staff === null) {
                return self::fail('Staff not found', 404);
            }
            $old = self::one('SELECT * FROM staff WHERE id = ?', [$id]);
            DB::table('users')->where('id', $staff['user_id'])->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone_number' => $request->input('phone') ?: null,
                'is_active' => $request->input('is_active') !== null ? $request->input('is_active') : 1,
            ]);
            $new = [
                'branch_id' => $request->input('branch_id') ?: null,
                'department' => $request->input('department') ?: null,
                'job_title' => $request->input('job_title') ?: null,
                'salary' => $request->input('salary') ?: null,
            ];
            DB::table('staff')->where('id', $id)->update($new);
            self::audit($request, 'staff', 'update', $id, $old, $new, 'Staff updated');

            return self::ok(['message' => 'Staff updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== SHIFTS / STAFF SHIFTS ====================

    public function getShifts(Request $request)
    {
        try {
            $sql = 'SELECT s.*, b.name AS branch_name FROM shifts s LEFT JOIN branches b ON s.branch_id = b.id';
            $bindings = [];
            if ($request->query('branch_id')) {
                $sql .= ' WHERE s.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            $sql .= ' ORDER BY s.start_time, s.name';

            return self::ok(self::rows($sql, $bindings));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createShift(Request $request)
    {
        try {
            $id = DB::table('shifts')->insertGetId([
                'name' => $request->input('name'),
                'start_time' => $request->input('start_time'),
                'end_time' => $request->input('end_time'),
                'branch_id' => $request->input('branch_id') ?: null,
                'is_active' => $request->input('is_active', 1),
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'shift', 'create', (int) $id, null, $request->all(), 'Shift created');

            return self::ok(['id' => (int) $id, 'message' => 'Shift created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateShift(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM shifts WHERE id = ?', [$id]);
            $new = [
                'name' => $request->input('name'),
                'start_time' => $request->input('start_time'),
                'end_time' => $request->input('end_time'),
                'branch_id' => $request->input('branch_id') ?: null,
                'is_active' => $request->input('is_active', 1),
            ];
            DB::table('shifts')->where('id', $id)->update($new);
            self::audit($request, 'shift', 'update', $id, $old, $new, 'Shift updated');

            return self::ok(['message' => 'Shift updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getStaffShifts(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('staff_id')) {
                $where[] = 'ss.staff_id = ?';
                $bindings[] = $request->query('staff_id');
            }
            if ($request->query('shift_id')) {
                $where[] = 'ss.shift_id = ?';
                $bindings[] = $request->query('shift_id');
            }
            if ($request->query('date_from')) {
                $where[] = 'ss.assigned_date >= ?';
                $bindings[] = $request->query('date_from');
            }
            if ($request->query('date_to')) {
                $where[] = 'ss.assigned_date <= ?';
                $bindings[] = $request->query('date_to');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM staff_shifts ss $whereSQL", $bindings);
            $rows = self::rows("
                SELECT ss.*, sh.name AS shift_name, sh.start_time, sh.end_time,
                  u.name AS staff_name, b.name AS branch_name
                FROM staff_shifts ss
                JOIN shifts sh ON ss.shift_id = sh.id
                JOIN staff s ON ss.staff_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN branches b ON s.branch_id = b.id
                $whereSQL
                ORDER BY ss.assigned_date DESC, sh.start_time
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['staff_shifts' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createStaffShift(Request $request)
    {
        try {
            $id = DB::table('staff_shifts')->insertGetId([
                'staff_id' => $request->input('staff_id'),
                'shift_id' => $request->input('shift_id'),
                'assigned_date' => $request->input('assigned_date'),
                'status' => $request->input('status', 'scheduled'),
                'notes' => $request->input('notes') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'staff_shift', 'create', (int) $id, null, $request->all(), 'Shift assigned');

            return self::ok(['id' => (int) $id, 'message' => 'Shift assigned']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateStaffShift(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM staff_shifts WHERE id = ?', [$id]);
            $new = [
                'status' => $request->input('status', 'scheduled'),
                'notes' => $request->input('notes') ?: null,
            ];
            DB::table('staff_shifts')->where('id', $id)->update($new);
            self::audit($request, 'staff_shift', 'update', $id, $old, $new, 'Staff shift updated');

            return self::ok(['message' => 'Staff shift updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function deleteStaffShift(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM staff_shifts WHERE id = ?', [$id]);
            DB::table('staff_shifts')->where('id', $id)->delete();
            self::audit($request, 'staff_shift', 'delete', $id, $old, null, 'Staff shift removed');

            return self::ok(['message' => 'Staff shift removed']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== ATTENDANCE ====================

    public function getAttendance(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('branch_id')) {
                $where[] = 's.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('staff_id')) {
                $where[] = 'a.staff_id = ?';
                $bindings[] = $request->query('staff_id');
            }
            if ($request->query('date_from')) {
                $where[] = 'a.attendance_date >= ?';
                $bindings[] = $request->query('date_from');
            }
            if ($request->query('date_to')) {
                $where[] = 'a.attendance_date <= ?';
                $bindings[] = $request->query('date_to');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM attendance a JOIN staff s ON a.staff_id = s.id $whereSQL", $bindings);
            $rows = self::rows("
                SELECT a.*, s.employee_id, u.name AS staff_name, b.name AS branch_name
                FROM attendance a
                JOIN staff s ON a.staff_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN branches b ON s.branch_id = b.id
                $whereSQL
                ORDER BY a.attendance_date DESC, u.name
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['attendance' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function markAttendance(Request $request)
    {
        try {
            $staffId = $request->input('staff_id');
            $date = $request->input('date');
            $existing = self::one('SELECT id FROM attendance WHERE staff_id = ? AND attendance_date = ?', [$staffId, $date]);
            $values = [
                'status' => $request->input('status'),
                'check_in' => $request->input('check_in') ?: null,
                'check_out' => $request->input('check_out') ?: null,
                'notes' => $request->input('notes') ?: null,
            ];
            if ($existing !== null) {
                DB::table('attendance')->where('id', $existing['id'])->update($values);

                return self::ok(['message' => 'Attendance updated']);
            }
            $id = DB::table('attendance')->insertGetId($values + [
                'staff_id' => $staffId,
                'attendance_date' => $date,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Attendance marked']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== LEAVE REQUESTS ====================

    public function getLeaveRequests(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('status')) {
                $where[] = 'lr.status = ?';
                $bindings[] = $request->query('status');
            }
            if ($request->query('staff_id')) {
                $where[] = 'lr.staff_id = ?';
                $bindings[] = $request->query('staff_id');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM leave_requests lr $whereSQL", $bindings);
            $rows = self::rows("
                SELECT lr.*, s.employee_id, u.name AS staff_name, b.name AS branch_name,
                  approver.name AS approved_by_name
                FROM leave_requests lr
                JOIN staff s ON lr.staff_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN branches b ON s.branch_id = b.id
                LEFT JOIN users approver ON lr.approved_by = approver.id
                $whereSQL
                ORDER BY lr.created_at DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['requests' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateLeaveStatus(Request $request, int $id)
    {
        try {
            $status = $request->input('status');
            $old = self::one('SELECT * FROM leave_requests WHERE id = ?', [$id]);
            DB::table('leave_requests')->where('id', $id)->update([
                'status' => $status,
                'approved_by' => self::adminId($request),
                'notes' => $request->input('notes') ?: null,
                'updated_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'leave_request', 'update', $id, $old, $request->all(), "Leave $status");

            return self::ok(['message' => "Leave $status"]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== FINANCE ====================

    public function getExpenses(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('branch_id')) {
                $where[] = 'e.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('category_id')) {
                $where[] = 'e.category_id = ?';
                $bindings[] = $request->query('category_id');
            }
            if ($request->query('date_from')) {
                $where[] = 'e.expense_date >= ?';
                $bindings[] = $request->query('date_from');
            }
            if ($request->query('date_to')) {
                $where[] = 'e.expense_date <= ?';
                $bindings[] = $request->query('date_to');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM expenses e $whereSQL", $bindings);
            $rows = self::rows("
                SELECT e.*, ec.name AS category_name, b.name AS branch_name, u.name AS submitted_by_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN branches b ON e.branch_id = b.id
                LEFT JOIN users u ON e.submitted_by = u.id
                $whereSQL
                ORDER BY e.expense_date DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['expenses' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createExpense(Request $request)
    {
        try {
            $id = DB::table('expenses')->insertGetId([
                'title' => $request->input('title') ?: $request->input('description'),
                'description' => $request->input('description') ?: null,
                'amount' => $request->input('amount'),
                'category_id' => $request->input('category_id'),
                'branch_id' => $request->input('branch_id') ?: null,
                'expense_date' => $request->input('expense_date'),
                'payment_method' => $request->input('payment_method') ?: 'cash',
                'receipt_url' => $request->input('receipt_url') ?: null,
                'notes' => $request->input('notes') ?: null,
                'submitted_by' => self::adminId($request),
                'status' => 'pending',
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'expense', 'create', (int) $id, null, $request->all(), 'Expense created');

            return self::ok(['id' => (int) $id, 'message' => 'Expense created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function approveExpense(Request $request, int $id)
    {
        try {
            $status = $request->input('status');
            $old = self::one('SELECT * FROM expenses WHERE id = ?', [$id]);
            DB::table('expenses')->where('id', $id)->update([
                'status' => $status,
                'approved_by' => self::adminId($request),
                'approved_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'expense', 'update', $id, $old, $request->all(), "Expense $status");

            return self::ok(['message' => "Expense $status"]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getExpenseCategories()
    {
        try {
            return self::ok(self::rows("
                SELECT ec.*,
                  (SELECT COUNT(*) FROM expenses e WHERE e.category_id = ec.id) AS expense_count,
                  (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.category_id = ec.id AND e.status = 'approved') AS total_spent
                FROM expense_categories ec
                ORDER BY ec.name
            "));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createExpenseCategory(Request $request)
    {
        try {
            $id = DB::table('expense_categories')->insertGetId([
                'name' => $request->input('name'),
                'description' => $request->input('description') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Category created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getBudgets(Request $request)
    {
        try {
            $where = [];
            $bindings = [];
            if ($request->query('branch_id')) {
                $where[] = 'bt.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('status')) {
                $where[] = 'bt.status = ?';
                $bindings[] = $request->query('status');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

            return self::ok(self::rows("
                SELECT bt.*, ec.name AS category_name, br.name AS branch_name
                FROM budgets bt
                LEFT JOIN expense_categories ec ON bt.category_id = ec.id
                LEFT JOIN branches br ON bt.branch_id = br.id
                $whereSQL
                ORDER BY bt.created_at DESC
            ", $bindings));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createBudget(Request $request)
    {
        try {
            $id = DB::table('budgets')->insertGetId([
                'category_id' => $request->input('category_id') ?: null,
                'branch_id' => $request->input('branch_id') ?: null,
                'name' => $request->input('name'),
                'budget_type' => $request->input('budget_type') ?: 'operational',
                'amount' => $request->input('amount'),
                'period_start' => $request->input('period_start'),
                'period_end' => $request->input('period_end') ?: null,
                'notes' => $request->input('notes') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Budget created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getProfitLoss(Request $request)
    {
        try {
            $yr = $request->query('year') ?: date('Y');
            $clickpesa = self::rows("
                SELECT MONTH(created_at) AS month, COALESCE(SUM(amount), 0) AS total
                FROM clickpesa_transactions WHERE YEAR(created_at) = ?
                  AND UPPER(status) IN ('SUCCESS', 'SETTLED', 'COMPLETED') GROUP BY MONTH(created_at)
            ", [$yr]);
            $manual = self::rows("
                SELECT MONTH(created_at) AS month, COALESCE(SUM(amount), 0) AS total
                FROM payments WHERE YEAR(created_at) = ? AND LOWER(status) IN ('completed', 'success', 'paid')
                  AND LOWER(COALESCE(payment_method, '')) IN ('cash', 'manual', 'agent', 'bank_transfer')
                GROUP BY MONTH(created_at)
            ", [$yr]);
            $expenses = self::rows("
                SELECT MONTH(e.expense_date) AS month, COALESCE(SUM(e.amount), 0) AS total
                FROM expenses e
                WHERE YEAR(e.expense_date) = ? AND e.status = 'approved'
                GROUP BY MONTH(e.expense_date)
                ORDER BY month
            ", [$yr]);
            // Revenue sharing has been removed from the product; partner
            // payouts are reported as zero without querying that subsystem.

            $amountFor = static function (array $rows, int $month): float {
                foreach ($rows as $row) {
                    if ((int) $row['month'] === $month) {
                        return (float) ($row['total'] ?? 0);
                    }
                }

                return 0.0;
            };
            $months = [];
            $totalRevenue = 0.0;
            $totalExpenses = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $clickpesaAmount = $amountFor($clickpesa, $m);
                $manualAmount = $amountFor($manual, $m);
                $operatingExpenses = $amountFor($expenses, $m);
                $partnerPayoutAmount = 0.0;
                $revenue = $clickpesaAmount + $manualAmount;
                $expense = $operatingExpenses + $partnerPayoutAmount;
                $totalRevenue += $revenue;
                $totalExpenses += $expense;
                $months[] = [
                    'month' => $m,
                    'revenue' => $revenue,
                    'expenses' => $expense,
                    'operating_expenses' => $operatingExpenses,
                    'partner_payouts' => $partnerPayoutAmount,
                    'clickpesa' => $clickpesaAmount,
                    'manual' => $manualAmount,
                    'profit' => $revenue - $expense,
                ];
            }

            return self::ok([
                'year' => (int) $yr,
                'months' => $months,
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_profit' => $totalRevenue - $totalExpenses,
            ]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getFinancialDashboard()
    {
        try {
            $clickRows = self::rows('SELECT purpose, status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount FROM clickpesa_transactions GROUP BY purpose, status');
            $manualRows = self::rows("SELECT payment_method, status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount FROM payments WHERE LOWER(COALESCE(payment_method, '')) IN ('cash', 'manual', 'agent', 'bank_transfer') GROUP BY payment_method, status");
            $expenseTotal = (float) (self::one("SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE status = 'approved'")['total'] ?? 0);
            $budgetTotal = (float) (self::one('SELECT COALESCE(SUM(amount), 0) AS total FROM budgets')['total'] ?? 0);
            // Revenue sharing is no longer part of the product.
            $payoutTotal = 0.0;

            $isSettled = static fn ($status): bool => in_array(strtolower((string) $status), ['success', 'settled', 'completed', 'paid'], true);
            $clickpesaSettled = 0.0;
            $manualSettled = 0.0;
            foreach ($clickRows as $row) {
                if ($isSettled($row['status'])) {
                    $clickpesaSettled += (float) ($row['amount'] ?? 0);
                }
            }
            foreach ($manualRows as $row) {
                if ($isSettled($row['status'])) {
                    $manualSettled += (float) ($row['amount'] ?? 0);
                }
            }
            $totalRevenue = $clickpesaSettled + $manualSettled;
            $totalExpenses = $expenseTotal + $payoutTotal;

            $sourceSummary = static function (array $rows) use ($isSettled): array {
                $settledAmount = 0.0;
                $settledCount = 0;
                $pending = 0.0;
                $failed = 0.0;
                foreach ($rows as $row) {
                    $status = strtolower((string) ($row['status'] ?? ''));
                    if ($isSettled($row['status'])) {
                        $settledAmount += (float) ($row['amount'] ?? 0);
                        $settledCount += (int) ($row['count'] ?? 0);
                    } elseif (in_array($status, ['pending', 'processing'], true)) {
                        $pending += (float) ($row['amount'] ?? 0);
                    } elseif (in_array($status, ['failed', 'cancelled', 'canceled', 'timeout', 'reversed'], true)) {
                        $failed += (float) ($row['amount'] ?? 0);
                    }
                }

                return [
                    'settled' => $settledAmount,
                    'settled_count' => $settledCount,
                    'pending' => $pending,
                    'failed' => $failed,
                ];
            };

            return self::ok([
                'total_revenue' => $totalRevenue,
                'total_expenses' => $expenseTotal,
                'partner_payouts' => $payoutTotal,
                'net_profit' => $totalRevenue - $totalExpenses,
                'budget_allocated' => $budgetTotal,
                'budget_used' => $expenseTotal,
                'budget_utilization' => $budgetTotal > 0 ? (int) round(($expenseTotal / $budgetTotal) * 100) : 0,
                'revenue_by_source' => [
                    'clickpesa' => $sourceSummary($clickRows),
                    'manual' => $sourceSummary($manualRows),
                ],
                'expense_breakdown' => [
                    'operating_expenses' => $expenseTotal,
                    'partner_payouts' => $payoutTotal,
                ],
            ]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== ASSETS / INVENTORY ====================

    public function getAssets(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('search')) {
                $where[] = '(a.name LIKE ? OR a.asset_tag LIKE ?)';
                $search = '%'.$request->query('search').'%';
                array_push($bindings, $search, $search);
            }
            if ($request->query('category_id')) {
                $where[] = 'a.category_id = ?';
                $bindings[] = $request->query('category_id');
            }
            if ($request->query('branch_id')) {
                $where[] = 'a.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            if ($request->query('warranty_status') === 'expired') {
                $where[] = 'a.warranty_expiry < NOW()';
            } elseif ($request->query('warranty_status') === 'active') {
                $where[] = 'a.warranty_expiry >= NOW()';
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM assets a $whereSQL", $bindings);
            $rows = self::rows("
                SELECT a.*, ac.name AS category_name, b.name AS branch_name,
                  CASE WHEN a.warranty_expiry < NOW() THEN 'expired' ELSE 'active' END AS warranty_status
                FROM assets a
                LEFT JOIN asset_categories ac ON a.category_id = ac.id
                LEFT JOIN branches b ON a.branch_id = b.id
                $whereSQL
                ORDER BY a.name
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['assets' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createAsset(Request $request)
    {
        try {
            $id = DB::table('assets')->insertGetId([
                'name' => $request->input('name'),
                'asset_tag' => $request->input('asset_tag'),
                'category_id' => $request->input('category_id') ?: null,
                'branch_id' => $request->input('branch_id') ?: null,
                'purchase_date' => $request->input('purchase_date') ?: null,
                'purchase_cost' => $request->input('purchase_cost') ?: null,
                'warranty_expiry' => $request->input('warranty_expiry') ?: null,
                'serial_number' => $request->input('serial_number') ?: null,
                'notes' => $request->input('notes') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Asset created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateAsset(Request $request, int $id)
    {
        try {
            $new = [
                'name' => $request->input('name'),
                'asset_tag' => $request->input('asset_tag'),
                'category_id' => $request->input('category_id') ?: null,
                'branch_id' => $request->input('branch_id') ?: null,
                'purchase_date' => $request->input('purchase_date') ?: null,
                'purchase_cost' => $request->input('purchase_cost') ?: null,
                'warranty_expiry' => $request->input('warranty_expiry') ?: null,
                'serial_number' => $request->input('serial_number') ?: null,
                'notes' => $request->input('notes') ?: null,
                'status' => $request->input('status') ?: 'active',
            ];
            $old = self::one('SELECT * FROM assets WHERE id = ?', [$id]);
            DB::table('assets')->where('id', $id)->update($new);
            self::audit($request, 'asset', 'update', $id, $old, $new, 'Asset updated');

            return self::ok(['message' => 'Asset updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getAssetCategories()
    {
        try {
            return self::ok(self::rows("
                SELECT ac.*,
                  (SELECT COUNT(*) FROM assets a WHERE a.category_id = ac.id) AS asset_count
                FROM asset_categories ac
                ORDER BY ac.name
            "));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createAssetCategory(Request $request)
    {
        try {
            $id = DB::table('asset_categories')->insertGetId([
                'name' => $request->input('name'),
                'description' => $request->input('description') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Asset category created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== PURCHASE ORDERS ====================

    public function getPurchaseOrders(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('status')) {
                $where[] = 'po.status = ?';
                $bindings[] = $request->query('status');
            }
            if ($request->query('branch_id')) {
                $where[] = 'po.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM purchase_orders po $whereSQL", $bindings);
            $rows = self::rows("
                SELECT po.*, b.name AS branch_name, u.name AS requested_by_name
                FROM purchase_orders po
                LEFT JOIN branches b ON po.branch_id = b.id
                LEFT JOIN users u ON po.requested_by = u.id
                $whereSQL
                ORDER BY po.created_at DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);
            foreach ($rows as &$po) {
                $po['items'] = self::rows('SELECT * FROM purchase_order_items WHERE po_id = ?', [$po['id']]);
            }
            unset($po);

            return self::ok(['purchase_orders' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createPurchaseOrder(Request $request)
    {
        try {
            $items = $request->input('items', []);
            $totalAmount = 0.0;
            foreach ($items as $item) {
                $totalAmount += (float) $item['quantity'] * (float) $item['unit_price'];
            }
            $year = date('Y');
            $poCount = self::one('SELECT COUNT(*) AS c FROM purchase_orders WHERE order_number LIKE ?', ["PO-$year-%"])['c'] ?? 0;
            $orderNumber = 'PO-'.$year.'-'.str_pad((string) ((int) $poCount + 1), 4, '0', STR_PAD_LEFT);

            $poId = DB::table('purchase_orders')->insertGetId([
                'order_number' => $orderNumber,
                'branch_id' => $request->input('branch_id') ?: null,
                'supplier_name' => $request->input('supplier_name'),
                'total_amount' => $totalAmount,
                'notes' => $request->input('notes') ?: null,
                'requested_by' => self::adminId($request),
                'status' => 'draft',
                'created_at' => DB::raw('NOW()'),
            ]);
            foreach ($items as $item) {
                DB::table('purchase_order_items')->insert([
                    'po_id' => $poId,
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => (float) $item['quantity'] * (float) $item['unit_price'],
                ]);
            }
            self::audit($request, 'purchase_order', 'create', (int) $poId, null, $request->all(), 'Purchase order created');

            return self::ok(['id' => (int) $poId, 'message' => 'Purchase order created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updatePOStatus(Request $request, int $id)
    {
        try {
            $old = self::one('SELECT * FROM purchase_orders WHERE id = ?', [$id]);
            DB::table('purchase_orders')->where('id', $id)->update([
                'status' => $request->input('status'),
                'updated_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'purchase_order', 'update', $id, $old, $request->all(), 'PO status updated');

            return self::ok(['message' => 'PO status updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== TICKETS ====================

    public function getTickets(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            foreach (['status' => 't.status', 'priority' => 't.priority', 'branch_id' => 't.branch_id', 'category_id' => 't.category_id', 'assignee_id' => 't.assigned_to'] as $param => $column) {
                if ($request->query($param)) {
                    $where[] = "$column = ?";
                    $bindings[] = $request->query($param);
                }
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM support_tickets t $whereSQL", $bindings);
            $rows = self::rows("
                SELECT t.*, tc.name AS category_name, b.name AS branch_name,
                  u.name AS customer_name, a.name AS assignee_name
                FROM support_tickets t
                LEFT JOIN ticket_categories tc ON t.category_id = tc.id
                LEFT JOIN branches b ON t.branch_id = b.id
                LEFT JOIN users u ON t.submitted_by = u.id
                LEFT JOIN users a ON t.assigned_to = a.id
                $whereSQL
                ORDER BY
                  CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END,
                  t.created_at DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['tickets' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getTicketDetail(int $id)
    {
        try {
            $ticket = self::one("
                SELECT t.*, tc.name AS category_name, b.name AS branch_name,
                  u.name AS customer_name, a.name AS assignee_name
                FROM support_tickets t
                LEFT JOIN ticket_categories tc ON t.category_id = tc.id
                LEFT JOIN branches b ON t.branch_id = b.id
                LEFT JOIN users u ON t.submitted_by = u.id
                LEFT JOIN users a ON t.assigned_to = a.id
                WHERE t.id = ?
            ", [$id]);
            if ($ticket === null) {
                return self::fail('Ticket not found', 404);
            }
            $ticket['replies'] = self::rows("
                SELECT tr.*, u.name AS author_name
                FROM ticket_replies tr
                LEFT JOIN users u ON tr.user_id = u.id
                WHERE tr.ticket_id = ?
                ORDER BY tr.created_at ASC
            ", [$id]);

            return self::ok($ticket);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createTicket(Request $request)
    {
        try {
            $today = date('Ymd');
            $count = self::one('SELECT COUNT(*) AS c FROM support_tickets WHERE ticket_number LIKE ?', ["TKT-$today-%"])['c'] ?? 0;
            $ticketNumber = 'TKT-'.$today.'-'.str_pad((string) ((int) $count + 1), 4, '0', STR_PAD_LEFT);
            $id = DB::table('support_tickets')->insertGetId([
                'ticket_number' => $ticketNumber,
                'subject' => $request->input('subject'),
                'description' => $request->input('description') ?: null,
                'submitted_by' => $request->input('submitted_by'),
                'branch_id' => $request->input('branch_id') ?: null,
                'category_id' => $request->input('category_id') ?: null,
                'priority' => $request->input('priority') ?: 'normal',
                'assigned_to' => $request->input('assigned_to') ?: null,
                'status' => 'open',
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'ticket_number' => $ticketNumber, 'message' => 'Ticket created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateTicket(Request $request, int $id)
    {
        try {
            $fields = [];
            $bindings = [];
            $status = $request->input('status');
            if ($status) {
                $fields[] = 'status = ?';
                $bindings[] = $status;
            }
            if ($request->exists('assigned_to')) {
                $fields[] = 'assigned_to = ?';
                $bindings[] = $request->input('assigned_to') ?: null;
            }
            if ($request->input('priority')) {
                $fields[] = 'priority = ?';
                $bindings[] = $request->input('priority');
            }
            if ($request->input('category_id')) {
                $fields[] = 'category_id = ?';
                $bindings[] = $request->input('category_id');
            }
            if ($status === 'resolved') {
                $fields[] = 'resolved_at = NOW()';
            }
            if ($status === 'closed') {
                $fields[] = 'closed_at = NOW()';
            }
            $fields[] = 'updated_at = NOW()';
            $bindings[] = $id;
            $old = self::one('SELECT * FROM support_tickets WHERE id = ?', [$id]);
            DB::statement('UPDATE support_tickets SET '.implode(', ', $fields).' WHERE id = ?', $bindings);
            self::audit($request, 'support_ticket', 'update', $id, $old, $request->all(), 'Ticket updated');

            return self::ok(['message' => 'Ticket updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function addTicketReply(Request $request, int $id)
    {
        try {
            $replyId = DB::table('ticket_replies')->insertGetId([
                'ticket_id' => $id,
                'user_id' => self::adminId($request),
                'message' => $request->input('message'),
                'created_at' => DB::raw('NOW()'),
            ]);
            DB::table('support_tickets')->where('id', $id)->update(['updated_at' => DB::raw('NOW()')]);

            return self::ok(['id' => (int) $replyId, 'message' => 'Reply added']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getTicketCategories()
    {
        try {
            return self::ok(self::rows("
                SELECT tc.*,
                  (SELECT COUNT(*) FROM support_tickets t WHERE t.category_id = tc.id) AS ticket_count
                FROM ticket_categories tc
                ORDER BY tc.name
            "));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getTicketStats()
    {
        try {
            return self::ok([
                'total' => self::one('SELECT COUNT(*) AS count FROM support_tickets')['count'] ?? 0,
                'by_status' => self::rows('SELECT status, COUNT(*) AS count FROM support_tickets GROUP BY status'),
                'by_priority' => self::rows('SELECT priority, COUNT(*) AS count FROM support_tickets GROUP BY priority'),
                'sla_breaches' => self::one("SELECT COUNT(*) AS count FROM support_tickets WHERE sla_deadline IS NOT NULL AND sla_deadline < NOW() AND status NOT IN ('resolved', 'closed')")['count'] ?? 0,
            ]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== AUDIT TRAIL ====================

    public function getAuditTrail(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request, 50);
            $where = [];
            $bindings = [];
            if ($request->query('module')) {
                $where[] = 'al.entity_type = ?';
                $bindings[] = $request->query('module');
            }
            if ($request->query('action')) {
                $where[] = 'al.action = ?';
                $bindings[] = $request->query('action');
            }
            if ($request->query('user_id')) {
                $where[] = 'al.user_id = ?';
                $bindings[] = $request->query('user_id');
            }
            if ($request->query('date_from')) {
                $where[] = 'al.created_at >= ?';
                $bindings[] = $request->query('date_from');
            }
            if ($request->query('date_to')) {
                $where[] = 'al.created_at <= ?';
                $bindings[] = $request->query('date_to').' 23:59:59';
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM audit_trail al $whereSQL", $bindings);
            $rows = self::rows("
                SELECT al.*, u.name AS user_name
                FROM audit_trail al
                LEFT JOIN users u ON al.user_id = u.id
                $whereSQL
                ORDER BY al.created_at DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);
            $data = ['records' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];

            if ($request->query('stats') === 'true') {
                $data['stats'] = [
                    'total' => self::one('SELECT COUNT(*) AS count FROM audit_trail')['count'] ?? 0,
                    'today' => self::one('SELECT COUNT(*) AS count FROM audit_trail WHERE DATE(created_at) = CURDATE()')['count'] ?? 0,
                    'unique_users' => self::one('SELECT COUNT(DISTINCT user_id) AS count FROM audit_trail')['count'] ?? 0,
                    'by_action' => self::rows('SELECT action, COUNT(*) AS count FROM audit_trail GROUP BY action ORDER BY count DESC LIMIT 10'),
                ];
            }

            return self::ok($data);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== CONTRACTS ====================

    public function getContracts(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('status')) {
                $where[] = 'c.status = ?';
                $bindings[] = $request->query('status');
            }
            if ($request->query('type')) {
                $where[] = 'c.contract_type = ?';
                $bindings[] = $request->query('type');
            }
            if ($request->query('branch_id')) {
                $where[] = 'c.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM contracts c $whereSQL", $bindings);
            $rows = self::rows("
                SELECT c.*, b.name AS branch_name, u.name AS created_by_name
                FROM contracts c
                LEFT JOIN branches b ON c.branch_id = b.id
                LEFT JOIN users u ON c.created_by = u.id
                $whereSQL
                ORDER BY c.end_date ASC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['contracts' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getContractDetail(int $id)
    {
        try {
            $contract = self::one("
                SELECT c.*, b.name AS branch_name, u.name AS created_by_name
                FROM contracts c
                LEFT JOIN branches b ON c.branch_id = b.id
                LEFT JOIN users u ON c.created_by = u.id
                WHERE c.id = ?
            ", [$id]);
            if ($contract === null) {
                return self::fail('Contract not found', 404);
            }

            return self::ok($contract);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createContract(Request $request)
    {
        try {
            $year = date('Y');
            $count = self::one('SELECT COUNT(*) AS c FROM contracts WHERE contract_number LIKE ?', ["CTR-$year-%"])['c'] ?? 0;
            $contractNumber = 'CTR-'.$year.'-'.str_pad((string) ((int) $count + 1), 4, '0', STR_PAD_LEFT);
            $id = DB::table('contracts')->insertGetId([
                'contract_number' => $contractNumber,
                'title' => $request->input('title'),
                'contract_type' => $request->input('contract_type') ?: null,
                'party_name' => $request->input('party_name') ?: null,
                'party_contact' => $request->input('party_contact') ?: null,
                'branch_id' => $request->input('branch_id') ?: null,
                'start_date' => $request->input('start_date') ?: null,
                'end_date' => $request->input('end_date') ?: null,
                'value' => $request->input('value') ?: null,
                'terms' => $request->input('terms') ?: null,
                'document_url' => $request->input('document_url') ?: null,
                'created_by' => self::adminId($request),
                'status' => 'active',
                'created_at' => DB::raw('NOW()'),
            ]);
            self::audit($request, 'contract', 'create', (int) $id, null, $request->all(), 'Contract created');

            return self::ok(['id' => (int) $id, 'contract_number' => $contractNumber, 'message' => 'Contract created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateContract(Request $request, int $id)
    {
        try {
            $new = [
                'title' => $request->input('title'),
                'contract_type' => $request->input('contract_type') ?: null,
                'party_name' => $request->input('party_name') ?: null,
                'party_contact' => $request->input('party_contact') ?: null,
                'start_date' => $request->input('start_date') ?: null,
                'end_date' => $request->input('end_date') ?: null,
                'value' => $request->input('value') ?: null,
                'terms' => $request->input('terms') ?: null,
                'status' => $request->input('status') ?: 'active',
                'updated_at' => DB::raw('NOW()'),
            ];
            $old = self::one('SELECT * FROM contracts WHERE id = ?', [$id]);
            DB::table('contracts')->where('id', $id)->update($new);
            self::audit($request, 'contract', 'update', $id, $old, $request->all(), 'Contract updated');

            return self::ok(['message' => 'Contract updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== LICENSES ====================

    public function getLicenses(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = [];
            $bindings = [];
            if ($request->query('branch_id')) {
                $where[] = 'l.branch_id = ?';
                $bindings[] = $request->query('branch_id');
            }
            $status = $request->query('status');
            if ($status === 'expiring') {
                $where[] = 'l.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)';
            } elseif ($status === 'expired') {
                $where[] = 'l.expiry_date < NOW()';
            } elseif ($status) {
                $where[] = 'l.status = ?';
                $bindings[] = $status;
            }
            $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $total = self::count("SELECT COUNT(*) AS total FROM licenses_permits l $whereSQL", $bindings);
            $rows = self::rows("
                SELECT l.*, b.name AS branch_name,
                  CASE WHEN l.expiry_date < NOW() THEN 'expired'
                       WHEN l.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 'expiring'
                       ELSE 'active' END AS license_status
                FROM licenses_permits l
                LEFT JOIN branches b ON l.branch_id = b.id
                $whereSQL
                ORDER BY l.expiry_date ASC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['licenses' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getLicenseDetail(int $id)
    {
        try {
            $license = self::one("
                SELECT l.*, b.name AS branch_name,
                  CASE WHEN l.expiry_date < NOW() THEN 'expired'
                       WHEN l.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 'expiring'
                       ELSE 'active' END AS license_status
                FROM licenses_permits l
                LEFT JOIN branches b ON l.branch_id = b.id
                WHERE l.id = ?
            ", [$id]);
            if ($license === null) {
                return self::fail('License not found', 404);
            }

            return self::ok($license);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createLicense(Request $request)
    {
        try {
            $id = DB::table('licenses_permits')->insertGetId([
                'license_number' => $request->input('license_number'),
                'title' => $request->input('title'),
                'license_type' => $request->input('license_type') ?: 'other',
                'branch_id' => $request->input('branch_id') ?: null,
                'issuing_authority' => $request->input('issuing_authority'),
                'issue_date' => $request->input('issue_date'),
                'expiry_date' => $request->input('expiry_date'),
                'cost' => $request->input('cost') ?: 0,
                'notes' => $request->input('notes') ?: null,
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'License created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function updateLicense(Request $request, int $id)
    {
        try {
            $new = [
                'title' => $request->input('title'),
                'license_number' => $request->input('license_number'),
                'license_type' => $request->input('license_type') ?: 'other',
                'branch_id' => $request->input('branch_id') ?: null,
                'issuing_authority' => $request->input('issuing_authority'),
                'issue_date' => $request->input('issue_date'),
                'expiry_date' => $request->input('expiry_date'),
                'cost' => $request->input('cost') ?: 0,
                'notes' => $request->input('notes') ?: null,
                'status' => $request->input('status') ?: 'active',
                'updated_at' => DB::raw('NOW()'),
            ];
            $old = self::one('SELECT * FROM licenses_permits WHERE id = ?', [$id]);
            DB::table('licenses_permits')->where('id', $id)->update($new);
            self::audit($request, 'license', 'update', $id, $old, $request->all(), 'License updated');

            return self::ok(['message' => 'License updated']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getComplianceDashboard()
    {
        try {
            return self::ok([
                'expiring_contracts' => self::one("SELECT COUNT(*) AS count FROM contracts WHERE status = 'active' AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")['count'] ?? 0,
                'expired_contracts' => self::one("SELECT COUNT(*) AS count FROM contracts WHERE status = 'active' AND end_date < NOW()")['count'] ?? 0,
                'expiring_licenses' => self::one('SELECT COUNT(*) AS count FROM licenses_permits WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)')['count'] ?? 0,
                'expired_licenses' => self::one('SELECT COUNT(*) AS count FROM licenses_permits WHERE expiry_date < NOW()')['count'] ?? 0,
                'sla_breaches' => self::one("SELECT COUNT(*) AS count FROM support_tickets WHERE sla_deadline IS NOT NULL AND sla_deadline < NOW() AND status NOT IN ('resolved', 'closed')")['count'] ?? 0,
            ]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    // ==================== REPORTS ====================

    public function getReportTemplates()
    {
        try {
            return self::ok(self::rows("
                SELECT rt.*, u.name AS created_by_name
                FROM report_templates rt
                LEFT JOIN users u ON rt.created_by = u.id
                ORDER BY rt.name
            "));
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function createReportTemplate(Request $request)
    {
        try {
            $id = DB::table('report_templates')->insertGetId([
                'name' => $request->input('name'),
                'description' => $request->input('description') ?: null,
                'report_type' => $request->input('report_type'),
                'category' => $request->input('category') ?: 'other',
                'sql_query' => $request->input('sql_query') ?: null,
                'created_by' => self::adminId($request),
                'created_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'message' => 'Template created']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function getReportRuns(Request $request)
    {
        try {
            [$page, $limit, $offset] = self::paging($request);
            $where = '';
            $bindings = [];
            if ($request->query('template_id')) {
                $where = 'WHERE rr.template_id = ?';
                $bindings[] = $request->query('template_id');
            }
            $total = self::count("SELECT COUNT(*) AS total FROM report_runs rr $where", $bindings);
            $rows = self::rows("
                SELECT rr.*, rt.name AS template_name, u.name AS generated_by_name
                FROM report_runs rr
                LEFT JOIN report_templates rt ON rr.template_id = rt.id
                LEFT JOIN users u ON rr.generated_by = u.id
                $where
                ORDER BY rr.generated_at DESC
                LIMIT $limit OFFSET $offset
            ", $bindings);

            return self::ok(['runs' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    public function generateReport(Request $request)
    {
        try {
            $template = self::one('SELECT name FROM report_templates WHERE id = ?', [$request->input('template_id')]);
            $reportName = $template ? $template['name'] : 'Ad-hoc Report';
            $id = DB::table('report_runs')->insertGetId([
                'template_id' => $request->input('template_id'),
                'report_name' => $reportName,
                'generated_by' => self::adminId($request),
                'status' => 'pending',
                'generated_at' => DB::raw('NOW()'),
            ]);

            return self::ok(['id' => (int) $id, 'status' => 'pending', 'message' => 'Report generation started']);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }
}
