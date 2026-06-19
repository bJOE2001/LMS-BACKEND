<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\COCApplicationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HRAccessControlController;
use App\Http\Controllers\HRDashboardController;
use App\Http\Controllers\HRDepartmentLibraryController;
use App\Http\Controllers\HRIllnessLibraryController;
use App\Http\Controllers\HRLeaveBalanceImportController;
use App\Http\Controllers\HRLeaveTypeController;
use App\Http\Controllers\HRReportController;
use App\Http\Controllers\HRUserManagementController;
use App\Http\Controllers\HRWorkScheduleController;
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
| ERMS Integration Routes (trusted frontend origins or API key protected)
|--------------------------------------------------------------------------
*/
Route::prefix('erms')->middleware('erms.auth')->group(function () {
    Route::get('/employee-access', [LeaveApplicationController::class, 'ermsEmployeeAccess']);
    Route::get('/leave-balance/{id}', [LeaveApplicationController::class, 'ermsGetLeaveBalance']);
    Route::get('/leave-balances/{controlNo}', [LeaveApplicationController::class, 'ermsGetLeaveBalances']);
    Route::get('/leave-records', [LeaveApplicationController::class, 'ermsIndex']);
    Route::get('/apply-leave', [LeaveApplicationController::class, 'ermsIndex']);
    Route::get('/apply-leave/{id}', [LeaveApplicationController::class, 'ermsShow']);
    Route::get('/leave-applications/{id}', [LeaveApplicationController::class, 'ermsShow']);
    Route::post('/apply-leave', [LeaveApplicationController::class, 'ermsStore']);
    Route::post('/apply-leave/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/apply-leave/{id}/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/apply-leave/request-cancel', [LeaveApplicationController::class, 'ermsRequestCancel']);
    Route::post('/apply-leave/{id}/request-cancel', [LeaveApplicationController::class, 'ermsRequestCancel']);
    Route::get('/illnesses/options', [HRIllnessLibraryController::class, 'options']);
    Route::get('/coc-records', [COCApplicationController::class, 'ermsIndex']);
    Route::get('/apply-coc', [COCApplicationController::class, 'ermsIndex']);
    Route::post('/apply-coc', [COCApplicationController::class, 'ermsStore']);
    Route::post('/leave-applications/{id}/cancel', [LeaveApplicationController::class, 'ermsCancel']);
    Route::post('/leave-applications/{id}/request-update', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/leave-applications/{id}/request-cancel', [LeaveApplicationController::class, 'ermsRequestCancel']);
    Route::post('/leave-applications/{id}/edit-request', [LeaveApplicationController::class, 'ermsRequestUpdate']);
    Route::post('/leave-applications/{id}/request-edit', [LeaveApplicationController::class, 'ermsRequestEdit']);
    Route::get('/admin/department-head', [EmployeeController::class, 'ermsDepartmentHead']);
    Route::get('/city-administrator', [EmployeeController::class, 'ermsCityAdministrator']);
    Route::get('/city-mayor', [EmployeeController::class, 'ermsCityMayor']);
    Route::get('/city-vice-mayor', [EmployeeController::class, 'ermsCityViceMayor']);
    Route::get('/settings/signatories', [SettingsController::class, 'ermsSignatories']);
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
    Route::get('/city-administrator', [EmployeeController::class, 'cityAdministrator']);
    Route::get('/city-mayor', [EmployeeController::class, 'cityMayor']);
    Route::get('/city-vice-mayor', [EmployeeController::class, 'cityViceMayor']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{id}/application', [NotificationController::class, 'applicationDetails']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/delete', [NotificationController::class, 'destroy']);
    Route::get('/settings/profile', [SettingsController::class, 'getProfile']);
    Route::post('/settings/profile/update', [SettingsController::class, 'updateProfile']);
    Route::post('/settings/password/update', [SettingsController::class, 'updatePassword']);
    Route::get('/settings/signatories', [SettingsController::class, 'getSignatories']);
    Route::post('/settings/signatories/chrmo-leave-in-charge/update', [SettingsController::class, 'updateChrmoLeaveInCharge']);
    Route::get('/illnesses/options', [HRIllnessLibraryController::class, 'options']);

    Route::middleware('department_admin')->prefix('admin')->group(function () {
        // Employee management
        Route::get('/employee-options', [EmployeeController::class, 'adminEmployeeOptions']);
        Route::get('/employees/{controlNo}/leave-history', [EmployeeController::class, 'leaveHistory']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::post('/employees/{controlNo}/update', [EmployeeController::class, 'update']);
        Route::post('/employees/{controlNo}/delete', [EmployeeController::class, 'destroy']);
        Route::get('/department-head', [EmployeeController::class, 'departmentHead']);
        Route::post('/department-head', [EmployeeController::class, 'upsertDepartmentHead']);
        Route::post('/department-head/update', [EmployeeController::class, 'upsertDepartmentHead']);
        Route::post('/department-head/delete', [EmployeeController::class, 'deleteDepartmentHead']);

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
        Route::get('/leave-applications/{id}', [LeaveApplicationController::class, 'adminShow']);
        Route::get('/leave-applications/{id}/attachment', [LeaveApplicationController::class, 'adminViewAttachment']);
        Route::post('/leave-applications/{id}/approve', [LeaveApplicationController::class, 'adminApprove']);
        Route::post('/leave-applications/{id}/reject', [LeaveApplicationController::class, 'adminReject']);
        Route::get('/coc-applications', [COCApplicationController::class, 'adminIndex']);
        Route::get('/coc-applications/{id}', [COCApplicationController::class, 'adminShow']);
        Route::post('/coc-applications/{id}/approve', [COCApplicationController::class, 'adminApprove']);
        Route::post('/coc-applications/{id}/reject', [COCApplicationController::class, 'adminReject']);
        Route::get('/employees-for-coc', [COCApplicationController::class, 'adminEmployees']);
        Route::post('/coc-applications', [COCApplicationController::class, 'adminStore']);
        Route::post('/coc-applications/self', [COCApplicationController::class, 'adminStoreSelf']);

        // Apply leave on behalf of employee
        Route::get('/employees-for-leave', [LeaveApplicationController::class, 'adminEmployees']);
        Route::post('/leave-applications', [LeaveApplicationController::class, 'adminStore']);
    });

    Route::middleware('hr')->prefix('hr')->group(function () {
        Route::middleware('hr.module:employee_management')->group(function () {
            // Employee management
            Route::get('/employee-options', [EmployeeController::class, 'employeeOptions']);
            Route::get('/employees/{controlNo}/leave-history', [EmployeeController::class, 'leaveHistory']);
            Route::get('/employees/{controlNo}/leave-balance-ledger', [EmployeeController::class, 'leaveCreditsLedger']);
            Route::get('/employees/{controlNo}/leave-credits-ledger', [EmployeeController::class, 'leaveCreditsLedger']);

            // Leave balance management
            Route::get('/leave-balances/available-types', [HRLeaveBalanceImportController::class, 'availableTypes']);
            Route::post('/leave-balances', [HRLeaveBalanceImportController::class, 'store']);
            Route::post('/leave-balances/update', [HRLeaveBalanceImportController::class, 'update']);
        });

        Route::middleware('hr.module:dashboard')->group(function () {
            // Dashboard
            Route::get('/dashboard', [HRDashboardController::class, 'index']);
            Route::get('/calendar', [HRDashboardController::class, 'calendarLeaves']);
            Route::get('/department-statistics', [HRDashboardController::class, 'departmentStatistics']);
        });

        Route::middleware('hr.module:work_schedules')->group(function () {
            // Work schedule and leave deduction settings
            Route::get('/work-schedules', [HRWorkScheduleController::class, 'index']);
            Route::post('/work-schedules/default/update', [HRWorkScheduleController::class, 'updateDefault']);
            Route::post('/work-schedules/overrides', [HRWorkScheduleController::class, 'storeOverride']);
            Route::post('/work-schedules/overrides/{id}/update', [HRWorkScheduleController::class, 'updateOverride']);
            Route::post('/work-schedules/overrides/{id}/delete', [HRWorkScheduleController::class, 'destroyOverride']);
        });

        Route::middleware('hr.module:user_management')->group(function () {
            // User management
            Route::get('/user-management/department-admins', [HRUserManagementController::class, 'index']);
            Route::get('/user-management/eligible-employees', [HRUserManagementController::class, 'eligibleEmployees']);
            Route::get('/user-management/departments/{departmentId}/eligible-employees', [HRUserManagementController::class, 'eligibleEmployees']);
            Route::post('/user-management/department-admins', [HRUserManagementController::class, 'store']);
            Route::post('/user-management/department-admins/{id}/update', [HRUserManagementController::class, 'update']);
            Route::post('/user-management/department-admins/{id}/reactivate', [HRUserManagementController::class, 'reactivate']);
            Route::post('/user-management/department-admins/{id}/reset-password', [HRUserManagementController::class, 'resetDepartmentAdminPassword']);
            Route::post('/user-management/hr-accounts/{id}/reset-password', [HRUserManagementController::class, 'resetHrAccountPassword']);
            Route::post('/user-management/department-admins/{id}/delete', [HRUserManagementController::class, 'destroy']);
            Route::post('/user-management/hr-accounts/{id}/delete', [HRUserManagementController::class, 'destroyHrAccount']);
        });

        Route::middleware('hr.module:access_control')->prefix('access-control')->group(function () {
            Route::get('/modules', [HRAccessControlController::class, 'modules']);
            Route::get('/hr-admins', [HRAccessControlController::class, 'hrAdmins']);
            Route::post('/hr-admins/{id}/modules', [HRAccessControlController::class, 'updateHrAdminModules']);
        });

        Route::middleware('hr.module:office_library')->prefix('departments')->group(function () {
            Route::get('/', [HRDepartmentLibraryController::class, 'index']);
            Route::post('/', [HRDepartmentLibraryController::class, 'store']);
            Route::post('/{id}/update', [HRDepartmentLibraryController::class, 'update']);
            Route::post('/{id}/delete', [HRDepartmentLibraryController::class, 'destroy']);
        });

        Route::middleware('hr.module:illness_library')->prefix('illnesses')->group(function () {
            Route::get('/', [HRIllnessLibraryController::class, 'index']);
            Route::post('/', [HRIllnessLibraryController::class, 'store']);
            Route::post('/{id}/update', [HRIllnessLibraryController::class, 'update']);
            Route::post('/{id}/delete', [HRIllnessLibraryController::class, 'destroy']);
        });

        Route::middleware('hr.module:leave_types')->prefix('leave-types')->group(function () {
            Route::get('/', [HRLeaveTypeController::class, 'index']);
            Route::post('/', [HRLeaveTypeController::class, 'store']);
            Route::post('/{id}/update', [HRLeaveTypeController::class, 'update']);
            Route::post('/{id}/delete', [HRLeaveTypeController::class, 'destroy']);
        });

        Route::middleware('hr.module:applications')->group(function () {
            // Leave application review
            Route::get('/leave-applications', [LeaveApplicationController::class, 'hrIndex']);
            Route::get('/leave-application-edit-requests', [LeaveApplicationController::class, 'hrApplicationEditRequests']);
            Route::get('/leave-applications/{id}', [LeaveApplicationController::class, 'hrShow']);
            Route::get('/leave-applications/{id}/attachment', [LeaveApplicationController::class, 'hrViewAttachment']);
            Route::post('/leave-applications/{id}/receive', [LeaveApplicationController::class, 'hrReceive']);
            Route::post('/leave-applications/{id}/undo-receive', [LeaveApplicationController::class, 'hrUndoReceive']);
            Route::post('/leave-applications/{id}/edit', [LeaveApplicationController::class, 'hrSaveApplicationEdit']);
            Route::post('/leave-application-edit-requests/{id}/approve', [LeaveApplicationController::class, 'hrApproveApplicationEditRequest']);
            Route::post('/leave-application-edit-requests/{id}/reject', [LeaveApplicationController::class, 'hrRejectApplicationEditRequest']);
            Route::post('/leave-applications/{id}/pay-status/update', [LeaveApplicationController::class, 'hrUpdatePayStatus']);
            Route::post('/leave-applications/{id}/cmo-cbmo-review', [LeaveApplicationController::class, 'hrCmoCbmoReview']);
            Route::post('/leave-applications/{id}/release', [LeaveApplicationController::class, 'hrRelease']);
            Route::post('/leave-applications/{id}/undo-release', [LeaveApplicationController::class, 'hrUndoRelease']);
            Route::post('/leave-applications/{id}/update-receive', [LeaveApplicationController::class, 'hrReceiveUpdate']);
            Route::post('/leave-applications/{id}/update-release', [LeaveApplicationController::class, 'hrReleaseUpdate']);
            Route::post('/leave-applications/{id}/approve', [LeaveApplicationController::class, 'hrApprove']);
            Route::post('/leave-applications/{id}/reject', [LeaveApplicationController::class, 'hrReject']);
            Route::post('/leave-applications/{id}/recall', [LeaveApplicationController::class, 'hrRecall']);
        });

        Route::middleware('hr.module:coc_applications')->group(function () {
            Route::get('/coc-applications', [COCApplicationController::class, 'hrIndex']);
            Route::get('/coc-applications/late-filings', [COCApplicationController::class, 'hrLateFilingIndex']);
            Route::get('/coc-applications/{id}', [COCApplicationController::class, 'hrShow']);
            Route::post('/coc-applications/{id}/receive', [COCApplicationController::class, 'hrReceive']);
            Route::post('/coc-applications/{id}/undo-receive', [COCApplicationController::class, 'hrUndoReceive']);
            Route::post('/coc-applications/{id}/cmo-cbmo-review', [COCApplicationController::class, 'hrCmoCbmoReview']);
            Route::post('/coc-applications/{id}/release', [COCApplicationController::class, 'hrRelease']);
            Route::post('/coc-applications/{id}/undo-release', [COCApplicationController::class, 'hrUndoRelease']);
            Route::post('/coc-applications/{id}/late-filing/approve', [COCApplicationController::class, 'hrApproveLateFiling']);
            Route::post('/coc-applications/{id}/late-filing/reject', [COCApplicationController::class, 'hrRejectLateFiling']);
            Route::post('/coc-applications/{id}/approve', [COCApplicationController::class, 'hrApprove']);
            Route::post('/coc-applications/{id}/reject', [COCApplicationController::class, 'hrReject']);
            Route::post('/coc-balances/import', [COCApplicationController::class, 'hrImportBalances']);
        });

        Route::middleware('hr.module:reports_monitoring')->prefix('reports')->group(function () {
            // Reports
            Route::get('/lwop', [HRReportController::class, 'lwopReports']);
            Route::get('/leave-balances', [HRReportController::class, 'leaveBalancesReports']);
            Route::get('/monetization', [HRReportController::class, 'monetizationReports']);
            Route::get('/cto-availment', [HRReportController::class, 'ctoAvailmentReports']);
            Route::get('/coc-balances', [HRReportController::class, 'cocBalanceReports']);
            Route::get('/leave-availment', [HRReportController::class, 'leaveAvailmentReports']);
            Route::get('/summary', [HRReportController::class, 'getSummaryStats']);
            Route::get('/departments', [HRReportController::class, 'getDepartmentStats']);
            Route::get('/leave-types', [HRReportController::class, 'getLeaveTypeStats']);
            Route::get('/generate', [HRReportController::class, 'generateReport']);
        });
    });
});
