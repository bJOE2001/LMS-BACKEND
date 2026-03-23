<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\COCApplicationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HRDashboardController;
use App\Http\Controllers\HRDepartmentLibraryController;
use App\Http\Controllers\HRLeaveBalanceImportController;
use App\Http\Controllers\HRLeaveTypeController;
use App\Http\Controllers\HRReportController;
use App\Http\Controllers\HRUserManagementController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (no auth required)
|--------------------------------------------------------------------------
*/

Route::get('/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'LMS API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Authentication
Route::post('/login', [AuthController::class, 'login']);

// Password reset
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| ERMS Integration Routes (external, API key protected)
|--------------------------------------------------------------------------
*/
Route::prefix('erms')->middleware('erms.auth')->group(function () {
    Route::get('/leave-balance/{id}', [LeaveApplicationController::class, 'ermsGetLeaveBalance']);
    Route::get('/leave-balances/{controlNo}', [LeaveApplicationController::class, 'ermsGetLeaveBalances']);
    Route::get('/apply-leave', [LeaveApplicationController::class, 'ermsIndex']);
    Route::post('/apply-leave', [LeaveApplicationController::class, 'ermsStore']);
    Route::post('/apply-leave/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/apply-leave/{id}/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::get('/apply-coc', [COCApplicationController::class, 'ermsIndex']);
    Route::post('/apply-coc', [COCApplicationController::class, 'ermsStore']);
    Route::post('/leave-applications/{id}/cancel', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/leave-applications/{id}/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/leave-applications/{id}/edit-request', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/leave-applications/{id}/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (auth:sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Shared LMS account routes
    Route::get('/departments', [EmployeeController::class, 'departments']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{id}/application', [NotificationController::class, 'applicationDetails']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/settings/profile', [SettingsController::class, 'getProfile']);
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::put('/settings/password', [SettingsController::class, 'updatePassword']);

    Route::middleware('department_admin')->prefix('admin')->group(function () {
        // Employee management
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{controlNo}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{controlNo}', [EmployeeController::class, 'destroy']);
        Route::get('/department-head', [EmployeeController::class, 'departmentHead']);
        Route::post('/department-head', [EmployeeController::class, 'upsertDepartmentHead']);
        Route::put('/department-head', [EmployeeController::class, 'upsertDepartmentHead']);
        Route::delete('/department-head', [EmployeeController::class, 'deleteDepartmentHead']);

        // Dashboard
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/leave-credits', [AdminDashboardController::class, 'leaveCredits']);
        Route::get('/leave-balances/init-types', [AdminDashboardController::class, 'initializableTypes']);
        Route::post('/leave-balances/initialize', [AdminDashboardController::class, 'initializeBalance']);
        Route::post('/leave-applications/self', [AdminDashboardController::class, 'storeSelfLeave']);
        Route::get('/self-leave-balance/{leaveTypeId}', [AdminDashboardController::class, 'selfLeaveBalance']);
        Route::get('/employee-leave-balance/{employeeControlNo}/{leaveTypeId}', [AdminDashboardController::class, 'employeeLeaveBalance']);

        // Leave application review
        Route::get('/leave-applications', [LeaveApplicationController::class, 'adminIndex']);
        Route::get('/leave-applications/{id}/attachment', [LeaveApplicationController::class, 'adminViewAttachment']);
        Route::post('/leave-applications/{id}/approve', [LeaveApplicationController::class, 'adminApprove']);
        Route::post('/leave-applications/{id}/reject', [LeaveApplicationController::class, 'adminReject']);
        Route::get('/coc-applications', [COCApplicationController::class, 'adminIndex']);
        Route::post('/coc-applications/{id}/approve', [COCApplicationController::class, 'adminApprove']);
        Route::post('/coc-applications/{id}/reject', [COCApplicationController::class, 'adminReject']);

        // Apply leave on behalf of employee
        Route::get('/employees-for-leave', [LeaveApplicationController::class, 'adminEmployees']);
        Route::post('/leave-applications', [LeaveApplicationController::class, 'adminStore']);
    });

    Route::middleware('hr')->prefix('hr')->group(function () {
        // Employee management
        Route::get('/employees/{controlNo}/leave-history', [EmployeeController::class, 'leaveHistory']);
        Route::get('/employees/{controlNo}/leave-balance-ledger', [EmployeeController::class, 'leaveCreditsLedger']);
        Route::get('/employees/{controlNo}/leave-credits-ledger', [EmployeeController::class, 'leaveCreditsLedger']);

        // Dashboard
        Route::get('/dashboard', [HRDashboardController::class, 'index']);
        Route::get('/calendar', [HRDashboardController::class, 'calendarLeaves']);

        // Leave balance management
        Route::post('/leave-balances', [HRLeaveBalanceImportController::class, 'store']);

        // User management
        Route::get('/user-management/department-admins', [HRUserManagementController::class, 'index']);
        Route::get('/user-management/eligible-employees', [HRUserManagementController::class, 'eligibleEmployees']);
        Route::get('/user-management/departments/{departmentId}/eligible-employees', [HRUserManagementController::class, 'eligibleEmployees']);
        Route::post('/user-management/department-admins', [HRUserManagementController::class, 'store']);
        Route::put('/user-management/department-admins/{id}', [HRUserManagementController::class, 'update']);
        Route::delete('/user-management/department-admins/{id}', [HRUserManagementController::class, 'destroy']);

        Route::prefix('departments')->group(function () {
            Route::get('/', [HRDepartmentLibraryController::class, 'index']);
            Route::post('/', [HRDepartmentLibraryController::class, 'store']);
            Route::put('/{id}', [HRDepartmentLibraryController::class, 'update']);
            Route::delete('/{id}', [HRDepartmentLibraryController::class, 'destroy']);
        });

        Route::prefix('leave-types')->group(function () {
            Route::get('/', [HRLeaveTypeController::class, 'index']);
            Route::post('/', [HRLeaveTypeController::class, 'store']);
            Route::put('/{id}', [HRLeaveTypeController::class, 'update']);
            Route::delete('/{id}', [HRLeaveTypeController::class, 'destroy']);
        });

        // Leave application review
        Route::get('/leave-applications', [LeaveApplicationController::class, 'hrIndex']);
        Route::get('/leave-applications/{id}/attachment', [LeaveApplicationController::class, 'hrViewAttachment']);
        Route::post('/leave-applications/{id}/approve', [LeaveApplicationController::class, 'hrApprove']);
        Route::post('/leave-applications/{id}/reject', [LeaveApplicationController::class, 'hrReject']);
        Route::post('/leave-applications/{id}/recall', [LeaveApplicationController::class, 'hrRecall']);
        Route::get('/coc-applications', [COCApplicationController::class, 'hrIndex']);
        Route::post('/coc-applications/{id}/approve', [COCApplicationController::class, 'hrApprove']);
        Route::post('/coc-applications/{id}/reject', [COCApplicationController::class, 'hrReject']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/summary', [HRReportController::class, 'getSummaryStats']);
            Route::get('/departments', [HRReportController::class, 'getDepartmentStats']);
            Route::get('/leave-types', [HRReportController::class, 'getLeaveTypeStats']);
            Route::get('/generate', [HRReportController::class, 'generateReport']);
        });
    });
});
