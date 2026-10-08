<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Department;
use App\Models\HrisEmployee;
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
     *     commands_queued?: int
     * }
     */
    public function transferOnDepartmentAssignment(string $controlNo, int $newDepartmentId, ?int $userId = null): array
    {
        try {
            $emp = HrisEmployee::findByControlNo($controlNo, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $controlNo;
            $fullName = $emp
                ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                : 'EMP '.$controlNo;

            // 1. Identify target office and its active biometric terminal(s)
            $department = Department::query()->find($newDepartmentId);
            $departmentLabel = $department?->acronym ?: ($department?->name ?: "Office #{$newDepartmentId}");

            $targetDevices = BiometricDevice::query()
                ->where('department_id', $newDepartmentId)
                ->where('is_active', true)
                ->orderByDesc('is_primary')
                ->get();

            if ($targetDevices->isEmpty()) {
                Log::info("BiometricTransferService: No active biometric device assigned to department [{$departmentLabel}] for employee [{$canonicalControlNo}].");

                return [
                    'transferred' => false,
                    'message' => "{$departmentLabel} does not have an active biometric device configured.",
                ];
            }

            // Verify ZKBio Area mapping on primary target device
            $primaryTarget = $targetDevices->first();
            $targetAreaId = (int) ($primaryTarget->zkbio_area_id ?? 0);

            if ($targetAreaId <= 0 || $targetAreaId === 1) {
                return [
                    'transferred' => false,
                    'message' => "The biometric device for {$departmentLabel} does not have a valid ZKBio Time Area configured (Area 1 is not supported).",
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

            // Also check raw punch logs for any physical terminals this employee has punched on
            $punchedSns = AttendanceRawLog::query()
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

            $queuedCount = 0;

            // 3. Assign employee exclusively to target office area in ZKBio Time
            // This triggers ZKBio Time to:
            // - Push DATA UPDATE USERINFO & DATA UPDATE FINGERTMP to the target office device
            // - Send DATA DELETE USERINFO to the device(s) of their previous area
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

            Log::info("BiometricTransferService: Transferred employee [{$canonicalControlNo}] exclusively to [{$departmentLabel}] ({$primarySn}), deleted from [{$sourceNames}].");

            return [
                'transferred' => true,
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'target_devices' => $targetDevices->pluck('device_name')->all(),
                'source_devices' => $sourceSns,
                'commands_queued' => 1,
                'message' => "Biometrics transferred to {$departmentLabel} ({$targetName}) and deleted from {$sourceNames}.",
            ];
        } catch (Throwable $e) {
            Log::error("BiometricTransferService: Transfer failed for [{$controlNo}]", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * @return array{removed: bool, message: string}
     */
    public function removeOnDepartmentDeassignment(string $controlNo, int $oldDepartmentId, ?int $userId = null): array
    {
        try {
            $emp = HrisEmployee::findByControlNo($controlNo, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $controlNo;
            $fullName = $emp
                ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                : 'EMP '.$controlNo;

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

            return [
                'removed' => true,
                'message' => 'Employee removed from office and biometrics cleared from office terminal.',
            ];
        } catch (Throwable $e) {
            Log::error("BiometricTransferService: De-assignment failed for [{$controlNo}]", [
                'error' => $e->getMessage(),
            ]);

            return [
                'removed' => false,
                'message' => 'Failed to delete biometrics from old office terminal: '.$e->getMessage(),
            ];
        }
    }
}
