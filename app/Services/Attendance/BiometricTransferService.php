<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTemplate;
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
 *
 * Enforces strict protocol family isolation:
 * - ADMS <-> ADMS: ZKFinger VX10.0 + ZKFace VX7.0 templates via BiometricDeviceCommand
 * - LAN <-> LAN (TCP 4370): Standalone fingerprint templates via LanBioTemplateSyncService
 */
class BiometricTransferService
{
    /**
     * CHRMO department ID (Central Human Resource Management Office).
     */
    public const CHRMO_DEPARTMENT_ID = 18;

    private readonly LanBioTemplateSyncService $lanTemplateService;

    public function __construct(?LanBioTemplateSyncService $lanTemplateService = null)
    {
        $this->lanTemplateService = $lanTemplateService ?? new LanBioTemplateSyncService;
    }

    /**
     * Transfer an employee's biometric profile and same-family templates to the target office's
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
     *     templates_transferred?: int,
     *     requires_local_scan?: bool,
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

            $primaryTarget = $targetDevices->first();
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

            // Include explicit source department active devices when provided
            if ($sourceDepartmentId !== null) {
                $explicitSourceSns = BiometricDevice::query()
                    ->where('department_id', $sourceDepartmentId)
                    ->where('is_active', true)
                    ->pluck('serial_number')
                    ->filter()
                    ->all();
                $sourceSns = array_merge($sourceSns, $explicitSourceSns);
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

            // If employee registered in CHRMO, include CHRMO device(s) as default source if not already present
            $chrmoDevices = BiometricDevice::query()
                ->where('department_id', self::CHRMO_DEPARTMENT_ID)
                ->where('is_active', true)
                ->get();

            foreach ($chrmoDevices as $chrmoDev) {
                if ($chrmoDev->serial_number && ! in_array($chrmoDev->serial_number, $sourceSns, true)) {
                    $sourceSns[] = $chrmoDev->serial_number;
                }
            }

            // Exclude target devices from source list to prevent deleting from the new office device
            $sourceSns = array_values(array_unique(array_filter($sourceSns, fn ($sn) => ! in_array($sn, $targetSns, true))));

            // Resolve source department and devices
            $sourceDevices = $sourceSns !== []
                ? BiometricDevice::query()->whereIn('serial_number', $sourceSns)->where('is_active', true)->get()
                : collect();

            if ($sourceDepartmentId === null && $sourceDevices->isNotEmpty()) {
                $sourceDepartmentId = $sourceDevices->whereNotNull('department_id')->first()?->department_id;
            }
            if ($sourceDepartmentId === null) {
                $sourceDepartmentId = self::CHRMO_DEPARTMENT_ID;
            }
            $sourceOfficeName = $this->resolveOfficeName($sourceDepartmentId);
            $sourceDeviceSn = count($sourceSns) > 0 ? implode(', ', $sourceSns) : null;

            // 3. Before deleting from source LAN_POLL devices, harvest any uncached LAN_POLL templates into BIO_DB
            foreach ($sourceDevices as $srcDevice) {
                if ($srcDevice->communication_mode === BiometricDevice::MODE_LAN_POLL) {
                    $srcMode = $srcDevice->getTemplateCommunicationMode();
                    $hasCached = BiometricTemplate::query()
                        ->where('employee_control_no', $canonicalControlNo)
                        ->where('communication_mode', $srcMode)
                        ->exists();

                    if (! $hasCached) {
                        $this->lanTemplateService->pullTemplatesFromDevice($srcDevice, $canonicalControlNo);
                    }
                }
            }

            // 4. Provision employee + matching communication_mode templates to target terminal(s)
            $totalCommandsQueued = 0;
            $maxTemplatesTransferred = 0;

            foreach ($targetDevices as $targetDevice) {
                $prov = $this->provisionEmployeeToDevice($targetDevice, $canonicalControlNo, $fullName, $userId);
                $totalCommandsQueued += $prov['commands_queued'];
                if ($prov['templates_transferred'] > $maxTemplatesTransferred) {
                    $maxTemplatesTransferred = $prov['templates_transferred'];
                }
            }

            // 5. Delete employee from old / source terminal(s)
            foreach ($sourceDevices as $sourceDevice) {
                $totalCommandsQueued += $this->removeEmployeeFromDevice($sourceDevice, $canonicalControlNo, $fullName, $userId);
            }

            $requiresLocalScan = $maxTemplatesTransferred === 0;

            // 6. Update BiometricEnrollment record
            if (! $enrollment) {
                $enrollment = new BiometricEnrollment([
                    'employee_control_no' => $canonicalControlNo,
                ]);
            }

            $primarySn = $primaryTarget->serial_number;
            $primaryMode = $primaryTarget->getTemplateCommunicationMode();
            $targetName = $primaryTarget->device_name ?: "Terminal {$primarySn}";
            $sourceNames = $sourceDevices->isNotEmpty()
                ? $sourceDevices->pluck('device_name')->filter()->implode(', ')
                : 'previous terminal';

            $enrollment->employee_name = $fullName;
            $enrollment->status = ($maxTemplatesTransferred > 0 || $enrollment->isRegistered())
                ? BiometricEnrollment::STATUS_REGISTERED
                : BiometricEnrollment::STATUS_PENDING_ENROLLMENT;
            $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
            $enrollment->synced_devices = $targetSns;
            $enrollment->notes = $requiresLocalScan
                ? "Provisioned PIN on {$departmentLabel} ({$targetName}, {$primaryMode}); local {$primaryMode} biometric scan required."
                : "Transferred {$maxTemplatesTransferred} {$primaryMode} template(s) exclusively to {$departmentLabel} ({$targetName}), deleted from {$sourceNames}";
            $enrollment->save();

            $successMsg = $requiresLocalScan
                ? "User profile provisioned on {$departmentLabel} ({$targetName}). No {$primaryMode} templates were cached yet—employee must scan once on {$targetName}."
                : "Biometrics ({$maxTemplatesTransferred} {$primaryMode} template(s)) transferred to {$departmentLabel} ({$targetName}) and deleted from {$sourceNames}.";

            // 7. Audit Transaction in tblBiometricTransferLogs
            $log = $this->recordTransferLog([
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'action_type' => BiometricTransferLog::ACTION_TRANSFER,
                'source_department_id' => $sourceDepartmentId,
                'source_office_name' => $sourceOfficeName,
                'source_device_sn' => $sourceDeviceSn,
                'target_department_id' => $newDepartmentId,
                'target_office_name' => $departmentLabel,
                'target_device_sn' => $primarySn,
                'status' => BiometricTransferLog::STATUS_SUCCESS,
                'remarks' => $successMsg,
                'performed_by_user_id' => $userId,
                'performed_by_name' => $performedByName,
            ]);

            Log::info("BiometricTransferService: Transferred employee [{$canonicalControlNo}] ({$maxTemplatesTransferred} {$primaryMode} templates) to [{$departmentLabel}] ({$primarySn}), deleted from [{$sourceNames}].");

            return [
                'transferred' => true,
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'target_devices' => $targetDevices->pluck('device_name')->all(),
                'source_devices' => $sourceSns,
                'commands_queued' => max(1, $totalCommandsQueued),
                'templates_transferred' => $maxTemplatesTransferred,
                'requires_local_scan' => $requiresLocalScan,
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
     * from that office's LMS pulled employee roster. Templates remain preserved in BIO_DB.
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
        $oldDevices = BiometricDevice::query()
            ->where('department_id', $oldDepartmentId)
            ->where('is_active', true)
            ->get();
        $oldDevice = $oldDevices->first();

        try {
            foreach ($oldDevices as $dev) {
                if ($dev->communication_mode === BiometricDevice::MODE_LAN_POLL) {
                    $commMode = $dev->getTemplateCommunicationMode();
                    $hasCached = BiometricTemplate::query()
                        ->where('employee_control_no', $canonicalControlNo)
                        ->where('communication_mode', $commMode)
                        ->exists();

                    if (! $hasCached) {
                        $this->lanTemplateService->pullTemplatesFromDevice($dev, $canonicalControlNo);
                    }
                }

                $this->removeEmployeeFromDevice($dev, $canonicalControlNo, $fullName, $userId);
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
                'target_department_id' => self::CHRMO_DEPARTMENT_ID,
                'target_office_name' => 'CHRMO',
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
     * Provision an employee's USERINFO and matching communication_mode templates to a specific device.
     *
     * @return array{
     *     commands_queued: int,
     *     templates_transferred: int,
     *     user_provisioned: bool,
     *     communication_mode: string,
     *     requires_local_scan: bool
     * }
     */
    public function provisionEmployeeToDevice(
        BiometricDevice $targetDevice,
        string $controlNo,
        string $fullName,
        ?int $userId = null
    ): array {
        $targetMode = $targetDevice->getTemplateCommunicationMode();

        if ($targetDevice->communication_mode === BiometricDevice::MODE_LAN_POLL) {
            $cachedCount = BiometricTemplate::query()
                ->where('employee_control_no', $controlNo)
                ->where('communication_mode', $targetMode)
                ->count();

            if ($cachedCount === 0) {
                $candidateDevices = BiometricDevice::query()
                    ->where('communication_mode', BiometricDevice::MODE_LAN_POLL)
                    ->where('is_active', true)
                    ->where('serial_number', '!=', $targetDevice->serial_number)
                    ->whereNotNull('ip_address')
                    ->get();

                foreach ($candidateDevices as $cand) {
                    if ($cand->getTemplateCommunicationMode() === $targetMode) {
                        $pull = $this->lanTemplateService->pullTemplatesFromDevice($cand, $controlNo);
                        if ($pull['templates_saved'] > 0) {
                            break;
                        }
                    }
                }
            }

            $pushResult = $this->lanTemplateService->pushEmployeeToDevice(
                $targetDevice,
                $controlNo,
                $fullName,
                true
            );

            $pushedTemplates = $pushResult['templates_pushed'];

            return [
                'commands_queued' => $pushResult['user_pushed'] ? (1 + $pushedTemplates) : 0,
                'templates_transferred' => $pushedTemplates,
                'user_provisioned' => $pushResult['user_pushed'],
                'communication_mode' => $targetMode,
                'requires_local_scan' => $pushedTemplates === 0,
            ];
        }

        return $this->queueAdmsEmployeeProvisioning($targetDevice, $controlNo, $fullName, $userId);
    }

    /**
     * Remove an employee's user record and biometric templates from a specific device.
     */
    public function removeEmployeeFromDevice(
        BiometricDevice $device,
        string $controlNo,
        string $fullName,
        ?int $userId = null
    ): int {
        if ($device->communication_mode === 'LAN_POLL') {
            return $this->lanTemplateService->deleteEmployeeFromDevice($device, $controlNo) ? 1 : 0;
        }

        $sn = trim((string) $device->serial_number);
        if ($sn === '') {
            return 0;
        }

        BiometricDeviceCommand::query()
            ->where('device_serial_number', $sn)
            ->where('employee_control_no', $controlNo)
            ->where('status', BiometricDeviceCommand::STATUS_PENDING)
            ->delete();

        BiometricDeviceCommand::query()->create([
            'device_serial_number' => $sn,
            'command_type' => BiometricDeviceCommand::CMD_DELETE_USER,
            'command_payload' => BiometricDeviceCommand::buildDeleteUserCommand($controlNo),
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
            'created_by_user_id' => $userId,
        ]);

        return 1;
    }

    /**
     * Queue ADMS DATA UPDATE USERINFO + ADMS ZKFinger VX10.0 & ZKFace VX7.0 template commands.
     *
     * @return array{
     *     commands_queued: int,
     *     templates_transferred: int,
     *     user_provisioned: bool,
     *     communication_mode: string,
     *     requires_local_scan: bool
     * }
     */
    private function queueAdmsEmployeeProvisioning(
        BiometricDevice $device,
        string $controlNo,
        string $fullName,
        ?int $userId = null
    ): array {
        $sn = trim((string) $device->serial_number);
        $communicationMode = $device->getTemplateCommunicationMode();

        if ($sn === '') {
            return [
                'commands_queued' => 0,
                'templates_transferred' => 0,
                'user_provisioned' => false,
                'communication_mode' => $communicationMode,
                'requires_local_scan' => true,
            ];
        }

        BiometricDeviceCommand::query()
            ->where('device_serial_number', $sn)
            ->where('employee_control_no', $controlNo)
            ->where('status', BiometricDeviceCommand::STATUS_PENDING)
            ->delete();

        BiometricDeviceCommand::query()->create([
            'device_serial_number' => $sn,
            'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
            'command_payload' => BiometricDeviceCommand::buildDataUserCommand($controlNo, $fullName),
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
            'created_by_user_id' => $userId,
        ]);

        $templates = BiometricTemplate::query()
            ->where('employee_control_no', $controlNo)
            ->where('communication_mode', $communicationMode)
            ->orderBy('biometric_type')
            ->orderBy('finger_id')
            ->get();

        $templatesQueued = 0;
        foreach ($templates as $template) {
            $payload = BiometricDeviceCommand::buildTemplateCommand($template);
            if ($payload === null) {
                continue;
            }

            BiometricDeviceCommand::query()->create([
                'device_serial_number' => $sn,
                'command_type' => BiometricDeviceCommand::CMD_UPDATE_TEMPLATE,
                'command_payload' => $payload,
                'employee_control_no' => $controlNo,
                'employee_name' => $fullName,
                'status' => BiometricDeviceCommand::STATUS_PENDING,
                'created_by_user_id' => $userId,
            ]);
            $templatesQueued++;
        }

        return [
            'commands_queued' => 1 + $templatesQueued,
            'templates_transferred' => $templatesQueued,
            'user_provisioned' => true,
            'communication_mode' => $communicationMode,
            'requires_local_scan' => $templatesQueued === 0,
        ];
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
