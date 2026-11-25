<?php

use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/*
 * Port of routes/adminLegacy.js + routes/routerRoutes.js.  Paths owned by
 * routes/api/admin.php (payments, withdrawals, withdrawal-stats,
 * clickpesa-balance) are deliberately NOT redefined here.
 */
Route::prefix('admin')->middleware(['role:admin', 'throttle:300,1'])->group(function (): void {
    // ── Admin wallet ──────────────────────────────────────────────────
    Route::get('/wallet', [LegacyController::class, 'wallet']);
    Route::get('/wallet/transactions', [LegacyController::class, 'walletTransactions']);
    Route::post('/wallet/deposit', [LegacyController::class, 'walletDeposit']);

    // ── Resellers ─────────────────────────────────────────────────────
    Route::get('/resellers', [LegacyController::class, 'resellers']);
    Route::get('/resellers/{id}', [LegacyController::class, 'reseller']);
    Route::post('/resellers/{id}/deposit', [LegacyController::class, 'resellerDeposit']);
    Route::post('/resellers/{id}/deduct', [LegacyController::class, 'resellerDeduct']);

    // ── Packages: literal paths before the generic id routes ──────────
    Route::get('/packages/all', [LegacyController::class, 'allPackages']);
    Route::get('/packages', [AdminController::class, 'getPackages']);
    Route::post('/packages', [LegacyController::class, 'createPackage']);
    Route::put('/packages/{id}', [LegacyController::class, 'updatePackage']);
    Route::delete('/packages/{id}', [LegacyController::class, 'deletePackage']);

    // ── Voucher browsing/statistics ───────────────────────────────────
    Route::get('/vouchers/list', [LegacyController::class, 'voucherList']);
    Route::get('/vouchers/stats-full', [LegacyController::class, 'voucherStatsFull']);
    Route::get('/vouchers/batch/{batchId}', [VoucherController::class, 'vouchersByBatch']);
    Route::get('/voucher-report', [VoucherController::class, 'voucherReport']);
    Route::get('/voucher-stats', [VoucherController::class, 'voucherStats']);
    Route::get('/sales-summary', [VoucherController::class, 'salesSummary']);
    Route::get('/batches', [VoucherController::class, 'batches']);
    Route::post('/generate-voucher', [VoucherController::class, 'generate']);

    // ── Dashboards, analytics, users ──────────────────────────────────
    Route::post('/change-password', [AdminController::class, 'changePassword']);
    Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats']);
    Route::get('/analytics-data', [AdminController::class, 'getAnalyticsData']);
    Route::get('/reports-data', [AdminController::class, 'reportsData']);
    Route::get('/analytics', [AdminController::class, 'adminAnalytics']);
    Route::get('/analytics-stats', [AdminController::class, 'getAnalyticsStats']);
    Route::get('/customers', [AdminController::class, 'getCustomers']);
    Route::get('/online-customers', [AdminController::class, 'getOnlineCustomers']);
    Route::post('/suspend-customer/{id}', [AdminController::class, 'suspendCustomer']);
    Route::post('/unsuspend/{id}', [AdminController::class, 'unsuspendCustomer']);
    Route::post('/adjust-subscription/{id}', [AdminController::class, 'adjustCustomerSubscription']);
    Route::delete('/delete-customer/{id}', [AdminController::class, 'deleteCustomer']);
    Route::post('/set-unlimited', [AdminController::class, 'setUnlimitedStatus']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    Route::get('/user-stats', [AdminController::class, 'getUserStats']);

    // ── System & notifications ────────────────────────────────────────
    Route::get('/system-settings', [AdminController::class, 'getSystemSettings']);
    Route::put('/system-settings', [AdminController::class, 'updateSystemSettings']);
    Route::get('/notifications', [AdminController::class, 'getNotifications']);
    Route::put('/notification-read/{id}', [AdminController::class, 'markNotificationRead']);
    Route::get('/recent-activity', [AdminController::class, 'getRecentActivity']);

    // ── Revenue & exports ──────────────────────────────────────────────
    Route::get('/revenue', [ReportController::class, 'revenue']);
    Route::get('/download-report', [ReportController::class, 'downloadReport']);

    // ── Router management: static paths must precede /routers/{id} ────
    Route::get('/router-sessions/{routerID}', [RouterController::class, 'sessions']);
    Route::get('/routers-status', [RouterController::class, 'statusAll']);
    Route::get('/router-health', [RouterController::class, 'health']);
    Route::post('/add-router', [RouterController::class, 'store']);
    Route::put('/update-router/{id}', [RouterController::class, 'update']);
    Route::post('/reboot-router/{routerID}', [RouterController::class, 'reboot']);

    Route::get('/routers', [RouterController::class, 'index']);
    Route::post('/routers', [RouterController::class, 'store']);
    Route::get('/routers/health', [RouterController::class, 'health']);
    Route::get('/routers/status-all', [RouterController::class, 'statusAll']);
    Route::get('/routers/wireguard/peers', [RouterController::class, 'wireguardPeers']);
    Route::get('/routers/wireguard/server-config', [RouterController::class, 'wireguardServerConfig']);
    Route::get('/routers/wireguard/vps-guide', [RouterController::class, 'wireguardVpsGuide']);
    Route::post('/routers/wireguard/generate/{routerID}', [RouterController::class, 'wireguardGenerate']);
    Route::get('/routers/{routerID}/status', [RouterController::class, 'liveStatus']);
    Route::get('/routers/{routerID}/sessions', [RouterController::class, 'sessions']);
    Route::get('/routers/{routerID}/hotspot/sessions', [RouterController::class, 'hotspotSessions']);
    Route::get('/routers/{routerID}/hotspot/users', [RouterController::class, 'hotspotUsers']);
    Route::get('/routers/{routerID}/users', [RouterController::class, 'hotspotUsers']);
    Route::post('/routers/{routerID}/hotspot/kick', [RouterController::class, 'kick']);
    Route::post('/routers/{routerID}/kick', [RouterController::class, 'kick']);
    Route::get('/routers/{routerID}/bindings', [RouterController::class, 'bindings']);
    Route::get('/routers/{routerID}/interfaces', [RouterController::class, 'interfaces']);
    Route::get('/routers/{routerID}/logs', [RouterController::class, 'logs']);
    Route::get('/routers/{routerID}/details', [RouterController::class, 'details']);
    Route::get('/routers/{routerID}/active-users', [RouterController::class, 'activeUsers']);
    Route::post('/routers/{routerID}/reboot', [RouterController::class, 'reboot']);
    Route::post('/routers/{routerID}/connect', [RouterController::class, 'connect']);
    Route::get('/routers/{id}', [RouterController::class, 'show']);
    Route::put('/routers/{id}', [RouterController::class, 'update']);
    Route::delete('/routers/{id}', [RouterController::class, 'destroy']);
});
