<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Revenue dashboard and downloadable reports from adminLegacy.js. */
class ReportController extends BaseController
{
    public function revenue(Request $request): JsonResponse
    {
        try {
            $params = [];
            $clickWhere = "WHERE ct.status IN ('SUCCESS','SETTLED')";
            if ($request->query('from')) {
                $clickWhere .= ' AND ct.created_at >= ?';
                $params[] = $request->query('from').' 00:00:00';
            }
            if ($request->query('to')) {
                $clickWhere .= ' AND ct.created_at <= ?';
                $params[] = $request->query('to').' 23:59:59';
            }
            $clickTransactions = DB::select(
                "SELECT ct.*, u.name AS user_name, u.phone_number
                 FROM clickpesa_transactions ct LEFT JOIN users u ON ct.user_id = u.id
                 {$clickWhere} ORDER BY ct.created_at DESC LIMIT 200",
                $params
            );
            $transactions = array_map(static function ($row): array {
                $data = (array) $row;

                return array_merge($data, [
                    'phone' => $data['phone'] ?? $data['phone_number'] ?? null,
                    'channel' => $data['channel'] ?? 'clickpesa',
                ]);
            }, $clickTransactions);
            usort($transactions, static fn ($a, $b) => strtotime((string) ($b['created_at'] ?? 0)) <=> strtotime((string) ($a['created_at'] ?? 0)));
            $transactions = array_slice($transactions, 0, 200);

            $clickTotal = DB::selectOne("SELECT COALESCE(SUM(ct.amount),0) AS totalRevenue FROM clickpesa_transactions ct {$clickWhere}", $params);
            $totalRevenue = $this->num($clickTotal->totalRevenue ?? 0);
            $todayClick = DB::selectOne(
                "SELECT COALESCE(SUM(ct.amount),0) AS todayRevenue, COUNT(*) AS totalOrders FROM clickpesa_transactions ct {$clickWhere} AND DATE(ct.created_at) = CURDATE()",
                $params
            );
            $vouchersAgg = DB::selectOne(
                'SELECT COUNT(*) AS vouchersSold, COALESCE(SUM(price),0) AS vouchersRevenue FROM vouchers WHERE is_used = 1 AND used_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
            );
            $chartRows = DB::select(
                "SELECT day, COALESCE(SUM(total),0) AS total FROM (
                    SELECT DATE(ct.created_at) AS day, ct.amount AS total FROM clickpesa_transactions ct
                    {$clickWhere} AND ct.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 ) x GROUP BY day ORDER BY day",
                $params
            );

            return ApiResponse::json([
                'success' => true,
                'transactions' => $transactions,
                'totalRevenue' => $totalRevenue,
                'todayRevenue' => $this->num($todayClick->todayRevenue ?? 0),
                'totalOrders' => (int) ($todayClick->totalOrders ?? 0),
                'vouchersSold' => (int) ($vouchersAgg->vouchersSold ?? 0),
                'chartData' => [
                    'labels' => array_map(static fn ($row) => (new \DateTime($row->day))->format('d M'), $chartRows),
                    'data' => array_map(static fn ($row) => (float) $row->total, $chartRows),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainError('Failed to load revenue data');
        }
    }

    public function downloadReport(Request $request): Response
    {
        $format = $request->query('format');
        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $reportType = $request->query('reportType');
        if (! $startDate || ! $endDate) {
            return ApiResponse::json([
                'success' => false,
                'message' => 'Tarehe za kuanzia na mwisho zinahitajika',
            ], 400);
        }

        try {
            $data = [];
            $headers = [];
            if ($reportType === 'all' || $reportType === 'users') {
                $users = DB::select(
                    'SELECT id, name, phone, email, role, created_at, free_bytes FROM users WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC',
                    [$startDate, $endDate]
                );
                if ($reportType === 'users') {
                    $data = $users;
                    $headers = ['ID', 'Jina', 'Simu', 'Email', 'Role', 'Tarehe', 'Data (MB)'];
                }
            }
            if ($reportType === 'all' || $reportType === 'revenue') {
                $payments = DB::select(
                    "SELECT ct.id, COALESCE(u.name, ct.phone) as name, ct.amount, ct.payment_method, ct.status, ct.created_at
                     FROM clickpesa_transactions ct LEFT JOIN users u ON ct.user_id = u.id
                     WHERE ct.status IN ('SUCCESS','SETTLED') AND ct.created_at BETWEEN ? AND ?
                     ORDER BY ct.created_at DESC",
                    [$startDate, $endDate]
                );
                if ($reportType === 'revenue') {
                    $data = $payments;
                    $headers = ['ID', 'Mtumiaji', 'Kiasi', 'Njia', 'Hali', 'Tarehe'];
                }
            }

            if ($format === 'csv') {
                $csv = implode(',', $headers)."\n";
                foreach ($data as $row) {
                    $values = array_map(static function ($value) {
                        if ($value === null) {
                            return '';
                        }

                        return '"'.str_replace('"', '""', (string) $value).'"';
                    }, array_values((array) $row));
                    $csv .= implode(',', $values)."\n";
                }

                return response($csv, 200, [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => "attachment; filename=meshline_ripoti_{$reportType}_{$startDate}_to_{$endDate}.csv",
                ]);
            }
            if ($format === 'excel') {
                $tsv = implode("\t", $headers)."\n";
                foreach ($data as $row) {
                    $values = array_map(static fn ($value) => $value === null ? '' : (string) $value, array_values((array) $row));
                    $tsv .= implode("\t", $values)."\n";
                }

                return response($tsv, 200, [
                    'Content-Type' => 'application/vnd.ms-excel',
                    'Content-Disposition' => "attachment; filename=meshline_ripoti_{$reportType}_{$startDate}_to_{$endDate}.xls",
                ]);
            }
            if ($format === 'pdf') {
                $headerCells = implode('', array_map(static fn ($header) => "<th>{$header}</th>", $headers));
                $bodyRows = '';
                foreach ($data as $row) {
                    $cells = implode('', array_map(static function ($value) {
                        return '<td>'.($value === null ? '' : $value).'</td>';
                    }, array_values((array) $row)));
                    $bodyRows .= "<tr>{$cells}</tr>";
                }
                $displayDate = now()->format('d/m/Y');
                $reportTitle = strtoupper((string) $reportType);
                $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Meshline WiFi Ripoti</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1 { color: #FF7A30; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { background: #FF7A30; color: white; padding: 10px; text-align: left; }
    td { border: 1px solid #ddd; padding: 8px; }
    tr:nth-child(even) { background-color: #f9f9f9; }
  </style>
</head>
<body>
  <h1>Meshline WiFi Ripoti - {$reportTitle}</h1>
  <p><strong>Kipindi:</strong> {$startDate} hadi {$endDate}</p>
  <p><strong>Tarehe ya Uchapisho:</strong> {$displayDate}</p>
  <table>
    <thead>
      <tr>{$headerCells}</tr>
    </thead>
    <tbody>
      {$bodyRows}
    </tbody>
  </table>
  <script>window.print();</script>
</body>
</html>
HTML;

                return response($html, 200, ['Content-Type' => 'text/html']);
            }

            return ApiResponse::json(['success' => false, 'message' => 'Format si sahihi'], 400);
        } catch (Throwable $e) {
            return ApiResponse::json([
                'success' => false,
                'message' => 'Imeshindikana kupakua ripoti',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
