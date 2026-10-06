<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTemplate;
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

            // 1. Identify target office's active biometric terminal(s)
            $targetDevices = BiometricDevice::query()
                ->where('department_id', $newDepartmentId)
                ->where('is_active', true)
                ->get();

            if ($targetDevices->isEmpty()) {
                Log::info("BiometricTransferService: No active biometric device assigned to department ID [{$newDepartmentId}] for employee [{$canonicalControlNo}].");

                return [
                    'transferred' => false,
                    'message' => 'No active biometric terminal mapped to this office.',
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

            // 3. Queue DATA USER and template commands to target office terminal(s)
            $templates = BiometricTemplate::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->get();

            foreach ($targetDevices as $targetDev) {
                // A. Create/Update user on target device
                $userPayload = BiometricDeviceCommand::buildDataUserCommand($canonicalControlNo, $fullName);
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $targetDev->serial_number,
                    'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
                    'command_payload' => $userPayload,
                    'employee_control_no' => $canonicalControlNo,
                    'employee_name' => $fullName,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                    'created_by_user_id' => $userId,
                ]);
                $queuedCount++;

                // B. Upload stored templates (fingerprint and face) to target device
                if ($templates->isNotEmpty()) {
                    foreach ($templates as $tpl) {
                        $templatePayload = $tpl->biometric_type === BiometricTemplate::TYPE_FACE
                            ? BiometricDeviceCommand::buildDataBiodataCommand(
                                $canonicalControlNo,
                                9,
                                (int) $tpl->finger_id,
                                (string) $tpl->template_data,
                                (int) $tpl->valid
                            )
                            : BiometricDeviceCommand::buildDataFpCommand(
                                $canonicalControlNo,
                                (int) $tpl->finger_id,
                                (int) $tpl->template_size,
                                (int) $tpl->valid,
                                (string) $tpl->template_data
                            );

                        BiometricDeviceCommand::query()->create([
                            'device_serial_number' => $targetDev->serial_number,
                            'command_type' => BiometricDeviceCommand::CMD_UPDATE_TEMPLATE,
                            'command_payload' => $templatePayload,
                            'employee_control_no' => $canonicalControlNo,
                            'employee_name' => $fullName,
                            'status' => BiometricDeviceCommand::STATUS_PENDING,
                            'created_by_user_id' => $userId,
                        ]);
                        $queuedCount++;
                    }
                }
            }

            // C. If server does not have templates yet, query source terminal(s) before deleting
            if ($templates->isEmpty() && count($sourceSns) > 0) {
                foreach ($sourceSns as $srcSn) {
                    BiometricDeviceCommand::query()->create([
                        'device_serial_number' => $srcSn,
                        'command_type' => BiometricDeviceCommand::CMD_QUERY,
                        'command_payload' => BiometricDeviceCommand::buildQueryTemplateCommand($canonicalControlNo),
                        'employee_control_no' => $canonicalControlNo,
                        'employee_name' => $fullName,
                        'status' => BiometricDeviceCommand::STATUS_PENDING,
                        'created_by_user_id' => $userId,
                    ]);
                    BiometricDeviceCommand::query()->create([
                        'device_serial_number' => $srcSn,
                        'command_type' => BiometricDeviceCommand::CMD_QUERY,
                        'command_payload' => BiometricDeviceCommand::buildQueryBiodataCommand($canonicalControlNo),
                        'employee_control_no' => $canonicalControlNo,
                        'employee_name' => $fullName,
                        'status' => BiometricDeviceCommand::STATUS_PENDING,
                        'created_by_user_id' => $userId,
                    ]);
                    $queuedCount += 2;
                }
            }

            // 4. Queue DATA DELETE user on source terminal(s) (e.g. CHRMO machine)
            foreach ($sourceSns as $srcSn) {
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $srcSn,
                    'command_type' => BiometricDeviceCommand::CMD_DELETE_USER,
                    'command_payload' => BiometricDeviceCommand::buildDeleteUserCommand($canonicalControlNo),
                    'employee_control_no' => $canonicalControlNo,
                    'employee_name' => $fullName,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                    'created_by_user_id' => $userId,
                ]);
                $queuedCount++;
            }

            // 5. Update BiometricEnrollment record
            if (! $enrollment) {
                $enrollment = new BiometricEnrollment([
                    'employee_control_no' => $canonicalControlNo,
                ]);
            }

            $currentSynced = is_array($enrollment->synced_devices) ? $enrollment->synced_devices : [];
            $updatedSynced = array_values(array_unique(array_merge(
                array_diff($currentSynced, $sourceSns),
                $targetSns
            )));

            $targetNames = $targetDevices->pluck('device_name')->implode(', ');
            $sourceNames = count($sourceSns) > 0
                ? BiometricDevice::query()->whereIn('serial_number', $sourceSns)->pluck('device_name')->implode(', ')
                : 'source device';

            $enrollment->employee_name = $fullName;
            $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
            $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
            $enrollment->synced_devices = $updatedSynced;
            $enrollment->notes = "Transferred to {$targetNames} (deleted from {$sourceNames})";
            $enrollment->save();

            Log::info("BiometricTransferService: Transferred employee [{$canonicalControlNo}] to [{$targetNames}], queued delete for [{$sourceNames}].");

            return [
                'transferred' => true,
                'employee_control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'target_devices' => $targetDevices->pluck('device_name')->all(),
                'source_devices' => $sourceSns,
                'commands_queued' => $queuedCount,
                'message' => "Biometrics transferred to {$targetNames} and deleted from {$sourceNames}.",
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

            $oldDevices = BiometricDevice::query()
                ->where('department_id', $oldDepartmentId)
                ->where('is_active', true)
                ->get();

            if ($oldDevices->isEmpty()) {
                return [
                    'removed' => false,
                    'message' => 'No active devices for this department.',
                ];
            }

            foreach ($oldDevices as $dev) {
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $dev->serial_number,
                    'command_type' => BiometricDeviceCommand::CMD_DELETE_USER,
                    'command_payload' => BiometricDeviceCommand::buildDeleteUserCommand($canonicalControlNo),
                    'employee_control_no' => $canonicalControlNo,
                    'employee_name' => $fullName,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                    'created_by_user_id' => $userId,
                ]);
            }

            // Update synced_devices in enrollment
            $enrollment = BiometricEnrollment::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->first();

            if ($enrollment && is_array($enrollment->synced_devices)) {
                $oldSns = $oldDevices->pluck('serial_number')->all();
                $enrollment->synced_devices = array_values(array_diff($enrollment->synced_devices, $oldSns));
                $enrollment->save();
            }

            return [
                'removed' => true,
                'message' => 'Biometric deletion queued for old office terminal(s).',
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
