<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Voucher generation, batches and reporting from adminController.generateVoucher. */
class VoucherController extends BaseController
{
    /** Hard-coded catalog retained from the Node implementation. */
    private const PACKAGE_VALUES = [
        'MASAA 6' => ['id' => 1, 'price' => 500],
        'MASAA 24' => ['id' => 2, 'price' => 1000],
        'WIKI 1' => ['id' => 3, 'price' => 6000],
        'MWEZI 1' => ['id' => 4, 'price' => 40000],
    ];

    public function generate(Request $request): JsonResponse
    {
        $issuedto = $request->input('issuedto');
        $vouchers = $request->input('vouchers');
        if (! $issuedto || ! trim((string) $issuedto)) {
            return $this->plainFail('Tafadhali ingiza jina la mtoa huduma');
        }
        if (! is_array($vouchers) || ! $vouchers) {
            return $this->plainFail('Tafadhali chagua angalau kifurushi kimoja');
        }

        $totalRequested = 0;
        foreach ($vouchers as $package => $count) {
            $quantity = (int) $count;
            if ((string) $count !== '' && (! is_numeric($count) || $quantity < 0)) {
                return $this->plainFail("Idadi ya {$package} si sahihi");
            }
            $totalRequested += $quantity;
        }
        if ($totalRequested === 0) {
            return $this->plainFail('Tafadhali ingiza idadi ya vouchers');
        }
        if ($totalRequested > 50000) {
            return $this->plainFail('Idadi ya jumla ya vouchers haizidi 50,000 kwa mara moja');
        }

        try {
            $result = DB::transaction(function () use ($issuedto, $vouchers, $request): array {
                $nextBatchNumber = ((int) DB::table('voucher_batches')->max('id')) + 1;
                $batchName = 'BATCH-'.strtoupper(now()->format('M')).'-'.str_pad((string) $nextBatchNumber, 3, '0', STR_PAD_LEFT);
                $batchId = DB::table('voucher_batches')->insertGetId([
                    'batch_name' => $batchName,
                    'issued_to' => trim((string) $issuedto),
                    'total_vouchers' => 0,
                    'total_value' => 0,
                    'created_by' => $this->uid($request) ?? 3,
                    'created_at' => now(),
                ]);

                $expiresAt = now()->addDays(365);
                $rows = [];
                $generated = 0;
                $totalValue = 0;
                foreach ($vouchers as $package => $count) {
                    $quantity = (int) $count;
                    if ($quantity <= 0 || ! isset(self::PACKAGE_VALUES[$package])) {
                        continue;
                    }
                    $info = self::PACKAGE_VALUES[$package];
                    for ($i = 0; $i < $quantity; $i++) {
                        $rows[] = [
                            'voucher_code' => $this->generateUniqueVoucher(),
                            'package_id' => $info['id'],
                            'price' => $info['price'],
                            'is_used' => 0,
                            'used_at' => null,
                            'used_by' => null,
                            'batch_id' => $batchId,
                            'expires_at' => $expiresAt,
                        ];
                        $generated++;
                        $totalValue += $info['price'];
                    }
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('vouchers')->insert($chunk);
                }
                if ($generated > 0) {
                    DB::table('voucher_batches')->where('id', $batchId)->update([
                        'total_vouchers' => $generated,
                        'total_value' => $totalValue,
                    ]);
                }

                return [
                    'batchId' => $batchId,
                    'count' => $generated,
                    'totalValue' => $totalValue,
                    'batchName' => $batchName,
                ];
            });

            return $this->plainFailAndSuccess($result, 'Vouchers '.number_format($result['count']).' zimeundwa kikamilifu!');
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kuunda vouchers');
        }
    }

    private function plainFailAndSuccess(array $result, string $message): JsonResponse
    {
        return ApiResponse::json([
            'success' => true,
            'message' => $message,
            'batchId' => $result['batchId'],
            'count' => $result['count'],
            'totalValue' => $result['totalValue'],
            'batchName' => $result['batchName'],
        ]);
    }

    private function generateUniqueVoucher(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'HOT'.random_int(100000, 999999);
            if (! DB::table('vouchers')->where('voucher_code', $code)->exists()) {
                return (string) $code;
            }
        }

        return 'HOT'.substr((string) round(microtime(true) * 1000), -8);
    }

    public function batches(): JsonResponse
    {
        try {
            $batches = DB::select(
                'SELECT vb.*, u.name as created_by_name,
                        DATE_FORMAT(vb.created_at, \'%Y-%m-%d %H:%i:%s\') as formatted_date
                 FROM voucher_batches vb LEFT JOIN users u ON vb.created_by = u.id
                 ORDER BY vb.created_at DESC LIMIT 50'
            );

            return ApiResponse::json(['success' => true, 'data' => $batches, 'count' => count($batches)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata batches');
        }
    }

    public function vouchersByBatch(int|string $batchId): JsonResponse
    {
        try {
            $vouchers = DB::select(
                'SELECT v.voucher_code, p.name as package_name, v.price, v.is_used, v.used_at,
                        u.name as used_by_name, u.phone_number
                 FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id
                 LEFT JOIN users u ON v.used_by = u.id
                 WHERE v.batch_id = ? ORDER BY v.id DESC',
                [$batchId]
            );
            $batch = DB::selectOne(
                'SELECT batch_name, issued_to, total_vouchers, total_value, created_at FROM voucher_batches WHERE id = ?',
                [$batchId]
            );

            return ApiResponse::json([
                'success' => true,
                'data' => $vouchers,
                'count' => count($vouchers),
                'batchInfo' => $batch,
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata vouchers');
        }
    }

    public function voucherReport(Request $request): JsonResponse
    {
        try {
            $limit = $this->clampInt($request->query('limit'), 100, 1, 100000);
            $sql = 'SELECT v.voucher_code, p.name as package_name, v.price, v.is_used, v.used_at,
                           u.name as used_by_name, u.phone_number, vb.batch_name, vb.issued_to, vb.created_at as batch_date
                    FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id
                    LEFT JOIN users u ON v.used_by = u.id
                    LEFT JOIN voucher_batches vb ON v.batch_id = vb.id WHERE 1=1';
            $params = [];
            if ($request->query('startDate') && $request->query('endDate')) {
                $sql .= ' AND DATE(vb.created_at) BETWEEN ? AND ?';
                $params[] = $request->query('startDate');
                $params[] = $request->query('endDate');
            }
            $sql .= " ORDER BY v.created_at DESC LIMIT {$limit}";
            $vouchers = DB::select($sql, $params);

            return ApiResponse::json(['success' => true, 'data' => $vouchers, 'count' => count($vouchers)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata ripoti ya vouchers');
        }
    }

    public function salesSummary(): JsonResponse
    {
        try {
            $today = now()->format('Y-m-d');
            $todaySales = DB::selectOne('SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?', [$today]);
            $totalUsers = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE role = "customer"');
            $activeSubs = DB::selectOne('SELECT COUNT(*) as count FROM users WHERE usage_until > NOW() AND role = "customer"');
            $totalRevenue = DB::selectOne('SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1');
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $daily = DB::selectOne(
                    'SELECT COALESCE(SUM(price), 0) as total FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?',
                    [$date]
                );
                $trend[] = ['date' => $date, 'total' => $this->num($daily->total ?? 0)];
            }

            return ApiResponse::json([
                'success' => true,
                'todaySales' => $this->num($todaySales->total ?? 0),
                'totalUsers' => (int) ($totalUsers->count ?? 0),
                'activeSubscriptions' => (int) ($activeSubs->count ?? 0),
                'totalRevenue' => $this->num($totalRevenue->total ?? 0),
                'trend' => $trend,
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata muhtasari wa mauzo');
        }
    }

    public function voucherStats(): JsonResponse
    {
        try {
            $usedToday = DB::selectOne('SELECT COUNT(*) as count FROM vouchers WHERE is_used = 1 AND DATE(used_at) = ?', [now()->format('Y-m-d')]);
            $available = DB::selectOne('SELECT COUNT(*) as count FROM vouchers WHERE is_used = 0');

            return ApiResponse::json([
                'success' => true,
                'usedToday' => (int) ($usedToday->count ?? 0),
                'availableVouchers' => (int) ($available->count ?? 0),
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata takwimu za vouchers');
        }
    }
}
