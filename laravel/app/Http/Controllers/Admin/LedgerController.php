<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use App\Support\Meshline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The unified payment/wallet ledger registered by routes/api/admin.php.  These
 * paths are intentionally defined before admin-legacy.php so that the UNION
 * ledger is the single source of truth for the Vue Payments page.
 */
class LedgerController extends BaseController
{
    /** Extra WHERE fragments shared by /payments/ledger. */
    private function filterClause(array $filters): array
    {
        $clauses = [];
        $params = [];
        $map = [
            'business_id' => 'business_id = ?',
            'partner_id' => 'partner_id = ?',
            'status' => 'status = ?',
            'type' => 'type = ?',
            'from' => 'created_at >= ?',
            'to' => 'created_at <= ?',
        ];
        foreach ($map as $key => $sql) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[] = $sql;
                $params[] = $filters[$key];
            }
        }

        return [($clauses ? ' AND '.implode(' AND ', $clauses) : ''), $params];
    }

    public function paymentsLedger(Request $request): JsonResponse
    {
        try {
            [$clause, $params] = $this->filterClause($request->all());
            $rows = DB::select(
                "SELECT * FROM payments WHERE 1=1 {$clause} ORDER BY created_at DESC LIMIT 1000",
                $params
            );
            $totals = DB::select(
                "SELECT type, status, currency, COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM payments WHERE 1=1 {$clause} GROUP BY type, status, currency",
                $params
            );

            return ApiResponse::json(['success' => true, 'payments' => $rows, 'totals' => $totals]);
        } catch (Throwable $e) {
            return $this->plainError($e->getMessage());
        }
    }

    /** Provider callbacks are an audit trail, never counted twice in the ledger. */
    public function webhookEvents(Request $request): JsonResponse
    {
        try {
            $limit = $this->clampInt($request->query('limit'), 50, 1, 200);
            $events = DB::select(
                "SELECT id, provider, event_type, provider_ref, order_reference,
                        status, error_message, received_at, processed_at,
                        COALESCE(
                          JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.data.collectedAmount')),
                          JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.data.amount')),
                          JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.collectedAmount')),
                          JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.amount'))
                        ) AS reported_amount
                 FROM payment_events WHERE provider = 'clickpesa'
                 ORDER BY received_at DESC LIMIT {$limit}"
            );

            return ApiResponse::json(['events' => $events]);
        } catch (Throwable $e) {
            report($e);

            return $this->plainError('Failed to load ClickPesa webhook audit');
        }
    }

    private function ledgerSql(): string
    {
        return <<<'SQL'
SELECT CONCAT('payment-', p.id) AS id, p.created_at,
       COALESCE(NULLIF(p.payment_method, ''), 'payment') AS source,
       u.name AS user_name, u.company_name AS business_name, u.role AS user_role,
       u.phone_number AS phone, CONCAT('PAY-', p.id) AS reference,
       COALESCE(pkg.name, 'Package payment') AS purpose, p.amount,
       'inflow' AS direction, LOWER(p.status) AS status, p.status AS raw_status
FROM payments p
LEFT JOIN users u ON u.id = p.user_id
LEFT JOIN packages pkg ON pkg.id = p.package_id
UNION ALL
SELECT CONCAT('transaction-', t.id), t.created_at,
       CASE WHEN t.transaction_type = 'withdrawal' THEN 'withdrawal'
            ELSE 'wallet' END,
       u.name, u.company_name, u.role, u.phone_number,
       COALESCE(t.payment_reference, CONCAT('TXN-', t.id)),
       COALESCE(NULLIF(t.description, ''), REPLACE(t.transaction_type, '_', ' ')), t.amount,
       CASE WHEN t.transaction_type = 'withdrawal' THEN 'outflow' ELSE 'inflow' END,
       LOWER(t.status), t.status
FROM transactions t
LEFT JOIN users u ON u.id = t.user_id
UNION ALL
SELECT CONCAT('clickpesa-', c.id), c.created_at, 'clickpesa',
       u.name, u.company_name, u.role, COALESCE(u.phone_number, c.phone),
       c.order_reference, COALESCE(NULLIF(c.purpose, ''), 'ClickPesa payment'), c.amount,
       'inflow',
       CASE WHEN UPPER(c.status) IN ('SUCCESS', 'SETTLED', 'COMPLETED') THEN 'completed'
            WHEN UPPER(c.status) IN ('PENDING', 'PROCESSING') THEN 'pending' ELSE 'failed' END,
       c.status
FROM clickpesa_transactions c
LEFT JOIN users u ON u.id = c.user_id
WHERE c.processed = 0
SQL;
    }

    /** Unified ledger consumed by /admin/payments. */
    public function payments(Request $request): JsonResponse
    {
        try {
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 50, 1, 100);

            $filters = [];
            $values = [];
            if ($request->query('from')) {
                $filters[] = 'created_at >= ?';
                $values[] = $request->query('from');
            }
            if ($request->query('to')) {
                $filters[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
                $values[] = $request->query('to');
            }
            if ($request->query('source') && $request->query('source') !== 'all') {
                $filters[] = 'source = ?';
                $values[] = $request->query('source');
            }
            if ($request->query('status') && $request->query('status') !== 'all') {
                $filters[] = 'status = ?';
                $values[] = $request->query('status');
            }
            if ($request->query('role') && $request->query('role') !== 'all') {
                $filters[] = 'user_role = ?';
                $values[] = $request->query('role');
            }
            if ($request->query('q')) {
                $filters[] = '(user_name LIKE ? OR business_name LIKE ? OR phone LIKE ? OR reference LIKE ? OR purpose LIKE ?)';
                $term = '%'.mb_substr((string) $request->query('q'), 0, 100).'%';
                array_push($values, $term, $term, $term, $term, $term);
            }
            $where = $filters ? 'WHERE '.implode(' AND ', $filters) : '';
            $ledger = 'SELECT * FROM ('.$this->ledgerSql().') AS ledger '.$where;

            $countRow = DB::selectOne("SELECT COUNT(*) AS total FROM ({$ledger}) AS filtered", $values);
            $rows = DB::select("{$ledger} ORDER BY created_at DESC LIMIT {$limit} OFFSET ".($page - 1) * $limit, $values);
            $summaryRow = DB::selectOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN status = 'completed' AND direction = 'inflow' THEN amount ELSE 0 END), 0) AS inflow,
                    COALESCE(SUM(CASE WHEN status = 'completed' AND direction = 'outflow' THEN amount ELSE 0 END), 0) AS outflow,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                    SUM(status = 'pending') AS pending_count,
                    SUM(status = 'failed') AS failed_count,
                    COUNT(*) AS total_count
                 FROM ({$ledger}) AS filtered",
                $values
            );
            $sourceRows = DB::select(
                "SELECT source, COUNT(*) AS count,
                    COALESCE(SUM(CASE WHEN direction = 'inflow' AND status = 'completed' THEN amount ELSE 0 END), 0) AS inflow,
                    COALESCE(SUM(CASE WHEN direction = 'outflow' AND status = 'completed' THEN amount ELSE 0 END), 0) AS outflow,
                    SUM(status = 'pending') AS pending
                 FROM ({$ledger}) AS filtered GROUP BY source ORDER BY source",
                $values
            );
            $accounts = DB::select(
                "SELECT u.id, u.name, u.company_name, u.phone_number, u.email, u.role,
                        w.balance AS wallet_balance, r.balance AS reseller_balance, r.tier,
                        COALESCE(t.inflow, 0) AS ledger_inflow, COALESCE(t.outflow, 0) AS ledger_outflow,
                        COALESCE(t.pending, 0) AS ledger_pending, t.last_activity
                 FROM users u
                 LEFT JOIN wallets w ON w.user_id = u.id
                 LEFT JOIN resellers r ON r.user_id = u.id
                 LEFT JOIN (
                    SELECT user_id,
                      SUM(CASE WHEN status = 'completed' AND transaction_type != 'withdrawal' THEN amount ELSE 0 END) AS inflow,
                      SUM(CASE WHEN status = 'completed' AND transaction_type = 'withdrawal' THEN amount ELSE 0 END) AS outflow,
                      SUM(status = 'pending') AS pending, MAX(created_at) AS last_activity
                    FROM transactions GROUP BY user_id
                 ) t ON t.user_id = u.id
                 WHERE u.role IN ('reseller', 'admin', 'super_admin')
                 ORDER BY COALESCE(u.company_name, u.name)
                 LIMIT 1000"
            );

            $summary = [
                'inflow' => $this->num($summaryRow->inflow ?? 0),
                'outflow' => $this->num($summaryRow->outflow ?? 0),
                'net' => $this->num($summaryRow->inflow ?? 0) - $this->num($summaryRow->outflow ?? 0),
                'pending_amount' => $this->num($summaryRow->pending_amount ?? 0),
                'pending_count' => (int) ($summaryRow->pending_count ?? 0),
                'failed_count' => (int) ($summaryRow->failed_count ?? 0),
                'total_count' => (int) ($summaryRow->total_count ?? 0),
            ];
            $bySource = array_map(fn ($row) => [
                'source' => $row->source,
                'count' => (int) $row->count,
                'inflow' => $this->num($row->inflow),
                'outflow' => $this->num($row->outflow),
                'pending' => (int) ($row->pending ?? 0),
            ], $sourceRows);
            $accountRows = array_map(static function ($account): array {
                $row = (array) $account;

                return [
                    'id' => $row['id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'company_name' => $row['company_name'] ?? null,
                    'phone_number' => $row['phone_number'] ?? null,
                    'email' => $row['email'] ?? null,
                    'role' => $row['role'] ?? null,
                    'wallet_balance' => (float) ($row['wallet_balance'] ?? 0),
                    'reseller_balance' => $row['reseller_balance'] === null ? null : (float) $row['reseller_balance'],
                    'tier' => $row['tier'] ?? null,
                    'ledger_inflow' => $row['ledger_inflow'] ?? 0,
                    'ledger_outflow' => $row['ledger_outflow'] ?? 0,
                    'ledger_pending' => $row['ledger_pending'] ?? 0,
                    'last_activity' => $row['last_activity'] ?? null,
                    'ledger' => [
                        'inflow' => (float) ($row['ledger_inflow'] ?? 0),
                        'outflow' => (float) ($row['ledger_outflow'] ?? 0),
                        'pending' => (int) ($row['ledger_pending'] ?? 0),
                        'last_activity' => $row['last_activity'] ?? null,
                    ],
                ];
            }, $accounts);
            $total = (int) ($countRow->total ?? 0);

            return ApiResponse::json([
                'payments' => $rows,
                'bySource' => $bySource,
                'accounts' => $accountRows,
                'summary' => $summary,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => max(1, (int) ceil($total / $limit)),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->plainError('Failed to load payments ledger');
        }
    }

    public function withdrawals(Request $request): JsonResponse
    {
        try {
            $page = $this->clampInt($request->query('page'), 1, 1, 1000000);
            $limit = $this->clampInt($request->query('limit'), 20, 1, 100);
            $status = $request->query('status', 'pending');
            $conditions = ["t.transaction_type = 'withdrawal'"];
            $values = [];
            if ($status !== 'all') {
                $conditions[] = 't.status = ?';
                $values[] = $status;
            }
            $condition = implode(' AND ', $conditions);
            $countRow = DB::selectOne("SELECT COUNT(*) AS total FROM transactions t WHERE {$condition}", $values);
            $offset = ($page - 1) * $limit;
            $rows = DB::select(
                "SELECT t.id, t.user_id, t.amount, t.payment_phone, t.payment_method, t.payment_reference,
                        t.status, t.created_at, COALESCE(u.name, CONCAT('User #', t.user_id)) AS user_name,
                        u.phone_number, u.email, u.company_name
                 FROM transactions t LEFT JOIN users u ON u.id = t.user_id
                 WHERE {$condition} ORDER BY t.created_at DESC LIMIT {$limit} OFFSET {$offset}",
                $values
            );
            $total = (int) ($countRow->total ?? 0);

            return ApiResponse::json([
                'success' => true,
                'data' => $rows,
                'withdrawals' => $rows,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => max(1, (int) ceil($total / $limit)),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->plainError('Failed to load withdrawals');
        }
    }

    public function withdrawalStats(): JsonResponse
    {
        try {
            $rows = DB::select(
                "SELECT status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total,
                        SUM(DATE(created_at) = CURDATE()) AS today_count,
                        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS today_total
                 FROM transactions WHERE transaction_type = 'withdrawal' GROUP BY status"
            );
            $stats = [
                'pending' => ['count' => 0, 'total' => 0],
                'completed' => ['count' => 0, 'total' => 0],
                'today' => ['count' => 0, 'total' => 0],
                'total' => ['count' => 0, 'total' => 0],
            ];
            foreach ($rows as $row) {
                $count = (int) ($row->count ?? 0);
                $total = $this->num($row->total);
                $stats['total']['count'] += $count;
                $stats['total']['total'] += $total;
                $stats['today']['count'] += (int) ($row->today_count ?? 0);
                $stats['today']['total'] += $this->num($row->today_total);
                if (isset($stats[$row->status])) {
                    $stats[$row->status] = ['count' => $count, 'total' => $total];
                }
            }

            return ApiResponse::json($stats);
        } catch (Throwable $e) {
            report($e);

            return $this->plainError('Failed to load withdrawal stats');
        }
    }

    /** POST /withdrawals/{id}/{approve|reject} — mirrors walletController.processWithdrawal. */
    public function processWithdrawal(Request $request, int|string $id, string $action): JsonResponse
    {
        $status = $action === 'approve' ? 'completed' : 'failed';
        $adminId = $this->uid($request);
        $reference = $request->input('payment_reference');

        try {
            $result = DB::transaction(function () use ($id, $status, $reference, $adminId): array {
                $transaction = DB::table('transactions')
                    ->where('id', $id)
                    ->where('transaction_type', 'withdrawal')
                    ->lockForUpdate()
                    ->first();
                if (! $transaction) {
                    return ['error' => 'Transaction not found', 'status' => 404];
                }
                if ($status === 'failed') {
                    DB::table('wallets')->where('id', $transaction->wallet_id)->update([
                        'balance' => DB::raw('balance + '.(float) $transaction->amount),
                        'total_withdrawn' => DB::raw('total_withdrawn - '.(float) $transaction->amount),
                    ]);
                }
                DB::table('transactions')->where('id', $id)->update([
                    'status' => $status,
                    'payment_reference' => $reference,
                    'processed_at' => now(),
                ]);

                $amountText = 'TZS '.number_format((float) $transaction->amount);
                $message = $status === 'completed'
                    ? "Your withdrawal of {$amountText} has been completed. Reference: {$reference}"
                    : "Your withdrawal of {$amountText} failed and has been refunded to your wallet.";
                Meshline::notify(
                    (int) $transaction->user_id,
                    $status === 'completed' ? 'success' : 'error',
                    $status === 'completed' ? 'Withdrawal Completed' : 'Withdrawal Failed',
                    $message
                );
                Meshline::activity($adminId, 'process_withdrawal', 'transaction', (int) $id, "Processed withdrawal: {$status}");

                return ['transaction' => $transaction, 'status' => $status];
            });
        } catch (Throwable $e) {
            return $this->plainError($e->getMessage());
        }

        if (isset($result['error'])) {
            return $this->plainError($result['error'], $result['status']);
        }

        return ApiResponse::json(['success' => true, 'message' => 'Withdrawal '.$result['status']]);
    }

    /** ClickPesa balance is owned by the payments agent; degrade when absent. */
    public function clickpesaBalance(): JsonResponse
    {
        foreach ([
            'App\\Services\\Payment\\ClickPesaClient',
            'App\\Services\\Payment\\ClickPesaService',
            'App\\Services\\ClickPesa\\ClickPesaService',
        ] as $class) {
            if (! class_exists($class)) {
                continue;
            }
            try {
                $service = app($class);
                if (method_exists($service, 'getBalance')) {
                    return ApiResponse::json(['success' => true, 'balance' => $service->getBalance()]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ApiResponse::json(['success' => true, 'balance' => null, 'message' => 'Balance unavailable']);
    }
}
