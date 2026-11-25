<?php

use App\Http\Controllers\Modules\HqController;
use Illuminate\Support\Facades\Route;

// Mounted by routes/web.php inside the /api prefix -> /api/admin/hq/*
Route::middleware('role:admin')->prefix('admin/hq')->group(function (): void {
    // Regions
    Route::get('/regions', [HqController::class, 'getRegions']);
    Route::post('/regions', [HqController::class, 'createRegion']);
    Route::put('/regions/{id}', [HqController::class, 'updateRegion'])->whereNumber('id');

    // Districts
    Route::get('/districts', [HqController::class, 'getDistricts']);
    Route::post('/districts', [HqController::class, 'createDistrict']);
    Route::put('/districts/{id}', [HqController::class, 'updateDistrict'])->whereNumber('id');

    // Branches
    Route::get('/branches', [HqController::class, 'getBranches']);
    Route::get('/branches/{id}', [HqController::class, 'getBranchDetails'])->whereNumber('id');
    Route::post('/branches', [HqController::class, 'createBranch']);
    Route::put('/branches/{id}', [HqController::class, 'updateBranch'])->whereNumber('id');

    // Wards (regions -> districts -> branches -> wards)
    Route::get('/wards', [HqController::class, 'getWards']);
    Route::get('/wards/{id}', [HqController::class, 'getWardDetails'])->whereNumber('id');
    Route::post('/wards', [HqController::class, 'createWard']);
    Route::put('/wards/{id}', [HqController::class, 'updateWard'])->whereNumber('id');

    // Staff
    Route::get('/staff', [HqController::class, 'getStaff']);
    Route::post('/staff', [HqController::class, 'createStaff']);
    Route::put('/staff/{id}', [HqController::class, 'updateStaff'])->whereNumber('id');

    // Shifts
    Route::get('/shifts', [HqController::class, 'getShifts']);
    Route::post('/shifts', [HqController::class, 'createShift']);
    Route::put('/shifts/{id}', [HqController::class, 'updateShift'])->whereNumber('id');
    Route::get('/staff-shifts', [HqController::class, 'getStaffShifts']);
    Route::post('/staff-shifts', [HqController::class, 'createStaffShift']);
    Route::put('/staff-shifts/{id}', [HqController::class, 'updateStaffShift'])->whereNumber('id');
    Route::delete('/staff-shifts/{id}', [HqController::class, 'deleteStaffShift'])->whereNumber('id');

    // Attendance
    Route::get('/attendance', [HqController::class, 'getAttendance']);
    Route::post('/attendance', [HqController::class, 'markAttendance']);

    // Leave requests
    Route::get('/leave-requests', [HqController::class, 'getLeaveRequests']);
    Route::put('/leave-requests/{id}', [HqController::class, 'updateLeaveStatus'])->whereNumber('id');

    // Expenses and categories
    Route::get('/expenses', [HqController::class, 'getExpenses']);
    Route::post('/expenses', [HqController::class, 'createExpense']);
    Route::put('/expenses/{id}/approve', [HqController::class, 'approveExpense'])->whereNumber('id');
    Route::get('/expense-categories', [HqController::class, 'getExpenseCategories']);
    Route::post('/expense-categories', [HqController::class, 'createExpenseCategory']);

    // Budgets and financials
    Route::get('/budgets', [HqController::class, 'getBudgets']);
    Route::post('/budgets', [HqController::class, 'createBudget']);
    Route::get('/profit-loss', [HqController::class, 'getProfitLoss']);
    Route::get('/financial-dashboard', [HqController::class, 'getFinancialDashboard']);

    // Assets and categories
    Route::get('/assets', [HqController::class, 'getAssets']);
    Route::post('/assets', [HqController::class, 'createAsset']);
    Route::put('/assets/{id}', [HqController::class, 'updateAsset'])->whereNumber('id');
    Route::get('/asset-categories', [HqController::class, 'getAssetCategories']);
    Route::post('/asset-categories', [HqController::class, 'createAssetCategory']);

    // Purchase orders
    Route::get('/purchase-orders', [HqController::class, 'getPurchaseOrders']);
    Route::post('/purchase-orders', [HqController::class, 'createPurchaseOrder']);
    Route::put('/purchase-orders/{id}/status', [HqController::class, 'updatePOStatus'])->whereNumber('id');

    // Tickets
    Route::get('/tickets', [HqController::class, 'getTickets']);
    Route::get('/tickets/{id}', [HqController::class, 'getTicketDetail'])->whereNumber('id');
    Route::post('/tickets', [HqController::class, 'createTicket']);
    Route::put('/tickets/{id}', [HqController::class, 'updateTicket'])->whereNumber('id');
    Route::post('/tickets/{id}/replies', [HqController::class, 'addTicketReply'])->whereNumber('id');
    Route::get('/ticket-categories', [HqController::class, 'getTicketCategories']);
    Route::get('/ticket-stats', [HqController::class, 'getTicketStats']);

    // Audit trail
    Route::get('/audit-trail', [HqController::class, 'getAuditTrail']);

    // Contracts, licences, compliance
    Route::get('/contracts', [HqController::class, 'getContracts']);
    Route::get('/contracts/{id}', [HqController::class, 'getContractDetail'])->whereNumber('id');
    Route::post('/contracts', [HqController::class, 'createContract']);
    Route::put('/contracts/{id}', [HqController::class, 'updateContract'])->whereNumber('id');
    Route::get('/licenses', [HqController::class, 'getLicenses']);
    Route::get('/licenses/{id}', [HqController::class, 'getLicenseDetail'])->whereNumber('id');
    Route::post('/licenses', [HqController::class, 'createLicense']);
    Route::put('/licenses/{id}', [HqController::class, 'updateLicense'])->whereNumber('id');
    Route::get('/compliance-dashboard', [HqController::class, 'getComplianceDashboard']);

    // Reports
    Route::get('/report-templates', [HqController::class, 'getReportTemplates']);
    Route::post('/report-templates', [HqController::class, 'createReportTemplate']);
    Route::get('/report-runs', [HqController::class, 'getReportRuns']);
    Route::post('/reports/generate', [HqController::class, 'generateReport']);
});
