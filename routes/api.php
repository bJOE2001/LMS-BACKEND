<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HRDashboardController;
use App\Http\Controllers\HRLeaveBalanceImportController;
use App\Http\Controllers\HRLeaveTypeController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\NotificationController;
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
    Route::post('/cancel-leave/{id?}', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/leave-applications/{id}/cancel', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/request-edit-leave/{id?}', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/{id}/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/{id}/edit-request', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/{id}/actions/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
});

// HRPDS/LMS integration compatibility endpoints (API key protected)
Route::middleware('erms.auth')->group(function () {
    Route::get('/apply-leave', [LeaveApplicationController::class, 'ermsIndex']);
    Route::post('/apply-leave', [LeaveApplicationController::class, 'ermsStore']);
    Route::post('/cancel-leave/{id?}', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/request-edit-leave/{id?}', [LeaveApplicationController::class, 'ermsRequestEdit']);

    // Aliases used by some ERMS/HRPDS clients when probing list routes
    Route::get('/leave-applications', [LeaveApplicationController::class, 'ermsIndex']);
    Route::get('/personal-leave-records', [LeaveApplicationController::class, 'ermsIndex']);
    Route::get('/leave-records', [LeaveApplicationController::class, 'ermsIndex']);
    Route::post('/leave-applications/{id}/cancel', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/leave-applications/{id}/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/{id}/edit-request', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/{id}/actions/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::post('/leave-applications/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (auth:sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Employee Management (local LMS_DB)
    Route::get('/departments', [EmployeeController::class, 'departments']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/admin/employees', [EmployeeController::class, 'store']);
    Route::put('/admin/employees/{controlNo}', [EmployeeController::class, 'update']);
    Route::delete('/admin/employees/{controlNo}', [EmployeeController::class, 'destroy']);
    Route::get('/admin/department-head', [EmployeeController::class, 'departmentHead']);
    Route::put('/admin/department-head', [EmployeeController::class, 'upsertDepartmentHead']);
    Route::delete('/admin/department-head', [EmployeeController::class, 'deleteDepartmentHead']);
    Route::get('/hr/employees/{controlNo}/leave-history', [EmployeeController::class, 'leaveHistory'])->middleware('hr');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{id}/application', [NotificationController::class, 'applicationDetails']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Department Admin — dashboard
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
    Route::get('/admin/leave-credits', [AdminDashboardController::class, 'leaveCredits']);
    Route::get('/admin/leave-balances/init-types', [AdminDashboardController::class, 'initializableTypes']);
    Route::post('/admin/leave-balances/initialize', [AdminDashboardController::class, 'initializeBalance']);
    Route::post('/admin/leave-applications/self', [AdminDashboardController::class, 'storeSelfLeave']);
    Route::get('/admin/self-leave-balance/{leaveTypeId}', [AdminDashboardController::class, 'selfLeaveBalance']);
    Route::get('/admin/employee-leave-balance/{employeeId}/{leaveTypeId}', [AdminDashboardController::class, 'employeeLeaveBalance']);

    // Department Admin — leave application review
    Route::get('/admin/leave-applications', [LeaveApplicationController::class, 'adminIndex']);
    Route::post('/admin/leave-applications/{id}/approve', [LeaveApplicationController::class, 'adminApprove']);
    Route::post('/admin/leave-applications/{id}/reject', [LeaveApplicationController::class, 'adminReject']);

    // Department Admin — apply leave on behalf of employee
    Route::get('/admin/employees-for-leave', [LeaveApplicationController::class, 'adminEmployees']);
    Route::post('/admin/leave-applications', [LeaveApplicationController::class, 'adminStore']);

    // HR — dashboard
    Route::get('/hr/dashboard', [HRDashboardController::class, 'index']);
    Route::get('/hr/calendar', [HRDashboardController::class, 'calendarLeaves']);

    // HR — manual leave balance entry (HR only)
    Route::post('/hr/leave-balances', [HRLeaveBalanceImportController::class, 'store'])->middleware('hr');

    // HR — leave balance CSV import (HR only)
    Route::post('/hr/leave-balances/import', [HRLeaveBalanceImportController::class, 'import'])->middleware('hr');

    Route::middleware('hr')->prefix('hr/leave-types')->group(function () {
        Route::get('/', [HRLeaveTypeController::class, 'index']);
        Route::post('/', [HRLeaveTypeController::class, 'store']);
        Route::put('/{id}', [HRLeaveTypeController::class, 'update']);
        Route::delete('/{id}', [HRLeaveTypeController::class, 'destroy']);
    });

    // HR — leave application review
    Route::get('/hr/leave-applications', [LeaveApplicationController::class, 'hrIndex']);
    Route::post('/hr/leave-applications/{id}/approve', [LeaveApplicationController::class, 'hrApprove']);
    Route::post('/hr/leave-applications/{id}/reject', [LeaveApplicationController::class, 'hrReject']);

    // HR — Reports
    Route::prefix('hr/reports')->middleware('hr')->group(function () {
        Route::get('/summary', [\App\Http\Controllers\HRReportController::class, 'getSummaryStats']);
        Route::get('/departments', [\App\Http\Controllers\HRReportController::class, 'getDepartmentStats']);
        Route::get('/leave-types', [\App\Http\Controllers\HRReportController::class, 'getLeaveTypeStats']);
        Route::get('/generate', [\App\Http\Controllers\HRReportController::class, 'generateReport']);
    });

    // Settings
    Route::get('/settings/profile', [\App\Http\Controllers\SettingsController::class, 'getProfile']);
    Route::put('/settings/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/settings/password', [\App\Http\Controllers\SettingsController::class, 'updatePassword']);

});
