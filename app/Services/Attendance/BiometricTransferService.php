<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTransferLog;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to manage cross-terminal biometric migration when employees
 * are pulled, transferred, or de-assigned between departments.
 */
class BiometricTransferService
{
    /**
     * CHRMO department ID (Central Human Resource Management Office).
     */
    public const CHRMO_DEPARTMENT_ID = 18;

    /**
     * Central enrollment pool area in ZKBio Time (CHRMO).
     */
    public const CHRMO_AREA_ID = 3;

    public function __construct(
        protected ZkBioTimeReconciliationService $zkBioReconciliation
    ) {}

    /**
     * Transfer an employee's biometric profile and templates to the target office's
     * assigned biometric terminal, and delete their profile from the source (CHRMO/old) terminal.
     *
     * @return array{
     *     transferred: bool,
     *     message: string,
     *     employee_control_no?: string,
     *     employee_name?: string,
     *     target_devices?: array<int, string>,
     *     source_devices?: array<int, string>,
     *     commands_queued?: int,
     *     transfer_log_id?: int|null
     * }
     */
    public function transferOnDepartmentAssignment(
        string $controlNo,
        int $newDepartmentId,
        ?int $userId = null,
        ?int $sourceDepartmentId = null
    ): array {
        $emp = HrisEmployee::findByControlNo($controlNo, true);
        $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $controlNo;
        $fullName = $emp
            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
            : 'EMP '.$controlNo;

        $performedByName = $this->resolveUserName($userId);

        try {
            // 1. Identify target office and its active biometric terminal(s)
            $department = Department::query()->find($newDepartmentId);
            $departmentLabel = $department?->acronym ?: ($department?->name ?: "Office #{$newDepartmentId}");

            $targetDevices = BiometricDevice::query()
                ->where('department_id', $newDepartmentId)
                ->where('is_active', true)
                ->orderByDesc('is_primary')
                ->get();

            if ($targetDevices->isEmpty()) {
                $failMsg = "{$departmentLabel} does not have an active biometric device configured.";
                Log::info("BiometricTransferService: No active biometric device assigned to department [{$departmentLabel}] for employee [{$canonicalControlNo}].");

                $this->recordTransferLog([
                    'employee_control_no' => $canonicalControlNo,
                    'employee_name' => $fullName,
                    'action_type' => BiometricTransferLog::ACTION_TRANSFER,
                    'source_department_id' => $sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID,
                    'source_office_name' => $this->resolveOfficeName($sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID),
                    'target_department_id' => $newDepartmentId,
                    'target_office_name' => $departmentLabel,
                    'status' => BiometricTransferLog::STATUS_FAILED,
                    'remarks' => $failMsg,
                    'performed_by_user_id' => $userId,
                    'performed_by_name' => $performedByName,
                ]);

                return [
                    'transferred' => false,
                    'message' => $failMsg,
                ];
            }

            // Verify ZKBio Area mapping on primary target device
            $primaryTarget = $targetDevices->first();
            $targetAreaId = (int) ($primaryTarget->zkbio_area_id ?? 0);

            if ($targetAreaId <= 0 || $targetAreaId === 1) {
                $failMsg = "The biometric device for {$departmentLabel} does not have a valid ZKBio Time Area configured (Area 1 is not supported).";

                $this->recordTransferLog([
                    'employee_control_no' => $canonicalControlNo,
                    'employee_name' => $fullName,
                    'action_type' => BiometricTransferLog::ACTION_TRANSFER,
                    'source_department_id' => $sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID,
                    'source_office_name' => $this->resolveOfficeName($sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID),
                    'target_department_id' => $newDepartmentId,
                    'target_office_name' => $departmentLabel,
                    'target_device_sn' => $primaryTarget->serial_number,
                    'target_area_id' => $targetAreaId,
                    'status' => BiometricTransferLog::STATUS_FAILED,
                    'remarks' => $failMsg,
                    'performed_by_user_id' => $userId,
                    'performed_by_name' => $performedByName,
                ]);

                return [
                    'transferred' => false,
                    'message' => $failMsg,
                ];
            }

            $targetSns = $targetDevices->pluck('serial_number')->filter()->values()->all();

            // 2. Locate employee biometric enrollment and determine source device(s)
            $enrollment = BiometricEnrollment::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->orWhere('employee_control_no', $controlNo)
                ->orWhere('employee_control_no', ltrim($controlNo, '0'))
                ->first();

            $sourceSns = [];
            if ($enrollment) {
                if ($enrollment->enrollment_device_sn) {
                    $sourceSns[] = $enrollment->enrollment_device_sn;
                }
                if (is_array($enrollment->synced_devices)) {
                    $sourceSns = array_merge($sourceSns, $enrollment->synced_devices);
                }
            }

            // Also check punch logs for any physical terminals this employee has punched on
            $punchedSns = AttendanceLog::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->orWhere('biometric_pin', $canonicalControlNo)
                ->pluck('device_serial_number')
                ->filter()
                ->unique()
                ->all();

            $sourceSns = array_merge($sourceSns, $punchedSns);

            // If employee registered in CHRMO, include CHRMO device as default source if not already present
            $chrmoDevice = BiometricDevice::query()
                ->where('department_id', self::CHRMO_DEPARTMENT_ID)
                ->where('is_active', true)
                ->first();

            if ($chrmoDevice && ! in_array($chrmoDevice->serial_number, $sourceSns, true)) {
                $sourceSns[] = $chrmoDevice->serial_number;
            }

            // Exclude target devices from source list to prevent deleting from the new office device
            $sourceSns = array_values(array_unique(array_filter($sourceSns, fn ($sn) => ! in_array($sn, $targetSns, true))));

            // Resolve source department and device
            if ($sourceDepartmentId === null && ! empty($sourceSns)) {
                $sourceDev = BiometricDevice::query()->whereIn('serial_number', $sourceSns)->whereNotNull('department_id')->first();
                $sourceDepartmentId = $sourceDev?->department_id;
            }
            if ($sourceDepartmentId === null) {
                $sourceDepartmentId = self::CHRMO_DEPARTMENT_ID;
            }
            $sourceOfficeName = $this->resolveOfficeName($sourceDepartmentId);
            $sourceDeviceSn = count($sourceSns) > 0 ? implode(', ', $sourceSns) : null;
            $sourceAreaId = ($sourceDepartmentId === self::CHRMO_DEPARTMENT_ID) ? self::CHRMO_AREA_ID : null;

            // 3. Assign employee exclusively to target office area in ZKBio Time
            $syncResult = $this->zkBioReconciliation->assignEmployeeExclusivelyToArea(
                $canonicalControlNo,
                $targetAreaId,
                $fullName
            );

            if (! $syncResult['success']) {
                Log::warning("BiometricTransferService: ZKBio Time exclusive area sync warning for [{$canonicalControlNo}]", [
                    'message' => $syncResult['message'],
                ]);
            }

            // 4. Update BiometricEnrollment record
            if (! $enrollment) {
                $enrollment = new BiometricEnrollment([
                    'employee_control_no' => $canonicalControlNo,
                ]);
            }

            $primarySn = $primaryTarget->serial_number;
            $targetName = $primaryTarget->device_name ?: "Terminal {$primarySn}";
            $sourceNames = count($sourceSns) > 0
                ? BiometricDevice::query()->whereIn('serial_number', $sourceSns)->pluck('device_name')->implode(', ')
                : 'previous terminal';

            $enrollment->employee_name = $fullName;
            $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
            $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
            $enrollment->synced_devices = [$primarySn];
            $enrollment->notes = "Transferred exclusively to {$departmentLabel} ({$targetName}), deleted from {$sourceNames}";
            $enrollment->save();

            $successMsg = "Biometrics transferred to {$departmentLabel} ({$targetName}) and deleted from {$sourceNames}.";

            // 5. Audit Transaction in tblBiometricTransferLogs
            $log = $this->recordTransferLog([
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'action_type' => BiometricTransferLog::ACTION_TRANSFER,
                'source_department_id' => $sourceDepartmentId,
                'source_office_name' => $sourceOfficeName,
                'source_device_sn' => $sourceDeviceSn,
                'source_area_id' => $sourceAreaId,
                'target_department_id' => $newDepartmentId,
                'target_office_name' => $departmentLabel,
                'target_device_sn' => $primarySn,
                'target_area_id' => $targetAreaId,
                'status' => BiometricTransferLog::STATUS_SUCCESS,
                'remarks' => $successMsg,
                'performed_by_user_id' => $userId,
                'performed_by_name' => $performedByName,
            ]);

            Log::info("BiometricTransferService: Transferred employee [{$canonicalControlNo}] exclusively to [{$departmentLabel}] ({$primarySn}), deleted from [{$sourceNames}].");

            return [
                'transferred' => true,
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'target_devices' => $targetDevices->pluck('device_name')->all(),
                'source_devices' => $sourceSns,
                'commands_queued' => 1,
                'transfer_log_id' => $log?->id,
                'message' => $successMsg,
            ];
        } catch (Throwable $e) {
            Log::error("BiometricTransferService: Transfer failed for [{$controlNo}]", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->recordTransferLog([
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'action_type' => BiometricTransferLog::ACTION_TRANSFER,
                'source_department_id' => $sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID,
                'source_office_name' => $this->resolveOfficeName($sourceDepartmentId ?? self::CHRMO_DEPARTMENT_ID),
                'target_department_id' => $newDepartmentId,
                'status' => BiometricTransferLog::STATUS_FAILED,
                'remarks' => 'Failed to execute biometric transfer: '.$e->getMessage(),
                'performed_by_user_id' => $userId,
                'performed_by_name' => $performedByName,
            ]);

            return [
                'transferred' => false,
                'message' => 'Failed to queue biometric transfer: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Remove an employee's biometric data from an office terminal when they are removed
     * from that office's LMS pulled employee roster.
     *
     * @return array{removed: bool, message: string, transfer_log_id?: int|null}
     */
    public function removeOnDepartmentDeassignment(string $controlNo, int $oldDepartmentId, ?int $userId = null): array
    {
        $emp = HrisEmployee::findByControlNo($controlNo, true);
        $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $controlNo;
        $fullName = $emp
            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
            : 'EMP '.$controlNo;

        $performedByName = $this->resolveUserName($userId);
        $sourceOfficeName = $this->resolveOfficeName($oldDepartmentId);
        $oldDevice = BiometricDevice::query()->where('department_id', $oldDepartmentId)->first();

        try {
            // Reset employee area back to CHRMO central enrollment area (Area 3)
            // This causes ZKBio Time to automatically send DATA DELETE USERINFO to the office device
            $resetResult = $this->zkBioReconciliation->assignEmployeeExclusivelyToArea(
                $canonicalControlNo,
                self::CHRMO_AREA_ID,
                $fullName
            );

            if (! $resetResult['success']) {
                Log::warning("BiometricTransferService: De-assignment area reset warning for [{$canonicalControlNo}]", [
                    'message' => $resetResult['message'],
                ]);
            }

            // Update synced_devices in enrollment
            $enrollment = BiometricEnrollment::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->first();

            if ($enrollment) {
                $enrollment->synced_devices = [];
                $enrollment->notes = 'Removed from office roster, reset to central enrollment pool';
                $enrollment->save();
            }

            $successMsg = 'Employee removed from office and biometrics cleared from office terminal.';

            // Audit transaction
            $log = $this->recordTransferLog([
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'action_type' => BiometricTransferLog::ACTION_REMOVE,
                'source_department_id' => $oldDepartmentId,
                'source_office_name' => $sourceOfficeName,
                'source_device_sn' => $oldDevice?->serial_number,
                'source_area_id' => $oldDevice?->zkbio_area_id,
                'target_department_id' => self::CHRMO_DEPARTMENT_ID,
                'target_office_name' => 'CHRMO',
                'target_area_id' => self::CHRMO_AREA_ID,
                'status' => BiometricTransferLog::STATUS_SUCCESS,
                'remarks' => $successMsg,
                'performed_by_user_id' => $userId,
                'performed_by_name' => $performedByName,
            ]);

            return [
                'removed' => true,
                'transfer_log_id' => $log?->id,
                'message' => $successMsg,
            ];
        } catch (Throwable $e) {
            Log::error("BiometricTransferService: De-assignment failed for [{$controlNo}]", [
                'error' => $e->getMessage(),
            ]);

            $this->recordTransferLog([
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'action_type' => BiometricTransferLog::ACTION_REMOVE,
                'source_department_id' => $oldDepartmentId,
                'source_office_name' => $sourceOfficeName,
                'source_device_sn' => $oldDevice?->serial_number,
                'status' => BiometricTransferLog::STATUS_FAILED,
                'remarks' => 'Failed to delete biometrics from old office terminal: '.$e->getMessage(),
                'performed_by_user_id' => $userId,
                'performed_by_name' => $performedByName,
            ]);

            return [
                'removed' => false,
                'message' => 'Failed to delete biometrics from old office terminal: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Resolve the office name or acronym for a given department ID.
     */
    private function resolveOfficeName(?int $departmentId): ?string
    {
        if (! $departmentId) {
            return null;
        }

        $dept = Department::query()->find($departmentId);

        return $dept?->acronym ?: ($dept?->name ?: "Office #{$departmentId}");
    }

    /**
     * Resolve a user's full name across available user types.
     */
    private function resolveUserName(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        try {
            $deptAdmin = DepartmentAdmin::query()->find($userId);
            if ($deptAdmin) {
                return $deptAdmin->full_name ?: $deptAdmin->username;
            }

            $hr = HRAccount::query()->find($userId);
            if ($hr) {
                return $hr->full_name ?: $hr->username;
            }

            $user = User::query()->find($userId);
            if ($user) {
                return $user->name ?: $user->email;
            }
        } catch (Throwable) {
            // Ignore resolution errors
        }

        return "User #{$userId}";
    }

    /**
     * Safely persist an audit record to tblBiometricTransferLogs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordTransferLog(array $attributes): ?BiometricTransferLog
    {
        try {
            return BiometricTransferLog::query()->create($attributes);
        } catch (Throwable $e) {
            Log::warning('BiometricTransferService: Failed to record BiometricTransferLog', [
                'error' => $e->getMessage(),
                'payload' => $attributes,
            ]);

            return null;
        }
    }
}
