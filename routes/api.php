<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmployeeAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDashboardController;
use App\Http\Controllers\HRDashboardController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\LeaveBalanceController;
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
    Route::post('/employees/{employee}/generate-credentials', [EmployeeController::class, 'generateCredentials']);

    // Employee account (first-login password change)
    Route::post('/employee/change-password', [EmployeeAuthController::class, 'changePassword']);

    // Employee dashboard (application summary, recent applications)
    Route::get('/employee/dashboard', [EmployeeDashboardController::class, 'index']);
    Route::get('/employee/dashboard/leave-summary', [EmployeeDashboardController::class, 'leaveSummary']);

    // Leave balance initialization (one-time, employee-only)
    Route::get('/employee/leave-balances/init-types', [LeaveBalanceController::class, 'initializableTypes']);
    Route::post('/employee/leave-balances/initialize', [LeaveBalanceController::class, 'initialize']);

    // Leave applications (apply leave, list my applications)
    Route::get('/employee/leave-applications', [LeaveApplicationController::class, 'index']);
    Route::post('/employee/leave-applications', [LeaveApplicationController::class, 'store']);
    Route::get('/employee/leave-applications/{leave_application}', [LeaveApplicationController::class, 'show']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Department Admin — dashboard
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
    Route::get('/admin/leave-credits', [AdminDashboardController::class, 'leaveCredits']);
    Route::get('/admin/leave-balances/init-types', [AdminDashboardController::class, 'initializableTypes']);
    Route::post('/admin/leave-balances/initialize', [AdminDashboardController::class, 'initializeBalance']);
    Route::post('/admin/leave-applications/self', [AdminDashboardController::class, 'storeSelfLeave']);

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

    // HR — leave application review
    Route::get('/hr/leave-applications', [LeaveApplicationController::class, 'hrIndex']);
    Route::post('/hr/leave-applications/{id}/approve', [LeaveApplicationController::class, 'hrApprove']);
    Route::post('/hr/leave-applications/{id}/reject', [LeaveApplicationController::class, 'hrReject']);

});
