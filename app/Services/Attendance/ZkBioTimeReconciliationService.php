<?php

namespace App\Services\Attendance;

use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZkBioTimeReconciliationService
{
    private function zkBioUrl(string $path): string
    {
        return rtrim((string) config('services.zkbio.base_url', 'http://127.0.0.1'), '/').'/'.ltrim($path, '/');
    }

    private function getZkBioToken(): ?string
    {
        try {
            return Http::timeout(10)->post($this->zkBioUrl('api-token-auth/'), [
                'username' => config('services.zkbio.api_user'),
                'password' => config('services.zkbio.api_pass'),
            ])->json('token');
        } catch (Throwable $e) {
            Log::warning('ZkBioTimeReconciliationService: Token request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Retrieve the biometric enrollment state of an employee from ZKBio Time.
     * Prefers the official REST API; falls back to read-only DB query if unreachable.
     *
     * @return array{
     *     found: bool,
     *     has_fingerprint: bool,
     *     fingerprint: string,
     *     enroll_sn: ?string,
     *     enrolled_at: ?Carbon,
     *     zkbio_id: ?int
     * }
     */
    public function getEmployeeBiometricState(string $empCode): array
    {
        $token = $this->getZkBioToken();
        if ($token) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Authorization' => 'Token '.$token])
                    ->get($this->zkBioUrl('personnel/api/employees/'), ['emp_code' => $empCode]);

                if ($response->successful()) {
                    $empData = $response->json('data.0');
                    if ($empData) {
                        $fpRaw = trim((string) ($empData['fingerprint'] ?? '-'));
                        $hasFp = $fpRaw !== '' && $fpRaw !== '-';
                        $enrollSn = ! empty($empData['enroll_sn']) ? (string) $empData['enroll_sn'] : null;
                        $updateTimeStr = ! empty($empData['update_time']) ? (string) $empData['update_time'] : null;
                        $enrolledAt = $updateTimeStr ? Carbon::parse($updateTimeStr) : null;

                        return [
                            'found' => true,
                            'has_fingerprint' => $hasFp,
                            'fingerprint' => $fpRaw,
                            'enroll_sn' => $enrollSn,
                            'enrolled_at' => $enrolledAt,
                            'zkbio_id' => isset($empData['id']) ? (int) $empData['id'] : null,
                        ];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('ZkBioTimeReconciliationService: API query failed, falling back to read-only DB', [
                    'emp_code' => $empCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Read-only query on zkbiotime database
        try {
            $bio = DB::connection('zkbio')->table('iclock_biodata')
                ->join('personnel_employee', 'iclock_biodata.employee_id', '=', 'personnel_employee.id')
                ->where('personnel_employee.emp_code', $empCode)
                ->where('iclock_biodata.bio_type', 1) // 1 = Fingerprint
                ->where('iclock_biodata.valid', 1)
                ->select(
                    'personnel_employee.id as zkbio_id',
                    'iclock_biodata.sn as enroll_sn',
                    'iclock_biodata.change_time as enrolled_at',
                    'iclock_biodata.major_ver'
                )
                ->first();

            if ($bio) {
                return [
                    'found' => true,
                    'has_fingerprint' => true,
                    'fingerprint' => 'Ver '.($bio->major_ver ?: '10').':1',
                    'enroll_sn' => $bio->enroll_sn ? (string) $bio->enroll_sn : null,
                    'enrolled_at' => $bio->enrolled_at ? Carbon::parse($bio->enrolled_at) : null,
                    'zkbio_id' => (int) $bio->zkbio_id,
                ];
            }

            // Check if employee exists even without fingerprint
            $emp = DB::connection('zkbio')->table('personnel_employee')
                ->where('emp_code', $empCode)
                ->value('id');

            return [
                'found' => $emp !== null,
                'has_fingerprint' => false,
                'fingerprint' => '-',
                'enroll_sn' => null,
                'enrolled_at' => null,
                'zkbio_id' => $emp !== null ? (int) $emp : null,
            ];
        } catch (Throwable $e) {
            Log::error('ZkBioTimeReconciliationService: DB fallback query failed', [
                'emp_code' => $empCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'found' => false,
                'has_fingerprint' => false,
                'fingerprint' => '-',
                'enroll_sn' => null,
                'enrolled_at' => null,
                'zkbio_id' => null,
            ];
        }
    }

    /**
     * Retrieve target devices that have successfully received and acknowledged
     * the fingerprint template synchronization from ZKBio Time (return_value = 0).
     *
     * @return array<int, string> List of verified device serial numbers.
     */
    public function getSyncedTargetDevices(string $empCode): array
    {
        try {
            $employee = DB::connection('zkbio')->table('personnel_employee')
                ->where('emp_code', $empCode)
                ->first();

            if (! $employee) {
                return [];
            }

            $activeAreaIds = DB::connection('zkbio')->table('personnel_employee_area')
                ->where('employee_id', $employee->id)
                ->pluck('area_id')
                ->all();

            if (empty($activeAreaIds)) {
                return [];
            }

            $rows = DB::connection('zkbio')->table('iclock_terminalcommandlog as cmd')
                ->join('iclock_terminal as t', 'cmd.terminal_id', '=', 't.id')
                ->where('cmd.content', 'like', "%PIN={$empCode}%")
                ->where('cmd.content', 'like', '%FINGERTMP%')
                ->where('cmd.return_value', 0)
                ->whereIn('t.area_id', $activeAreaIds)
                ->select('t.sn as device_sn')
                ->distinct()
                ->get();

            return $rows->pluck('device_sn')->filter()->values()->all();
        } catch (Throwable $e) {
            Log::error('ZkBioTimeReconciliationService: Failed to query iclock_terminalcommandlog', [
                'emp_code' => $empCode,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Reconcile a single BiometricEnrollment against ZKBio Time state.
     * Idempotent: safe to run repeatedly.
     */
    public function reconcileEnrollment(BiometricEnrollment $enrollment): BiometricEnrollment
    {
        $controlNo = $enrollment->employee_control_no;
        $bioState = $this->getEmployeeBiometricState($controlNo);

        if (! $bioState['has_fingerprint']) {
            return $enrollment;
        }

        $enrollSn = $bioState['enroll_sn'] ?: $enrollment->enrollment_device_sn;
        $enrolledAt = $bioState['enrolled_at'] ?: $enrollment->enrolled_at ?: now();

        $targetSyncs = $this->getSyncedTargetDevices($controlNo);

        // Check if enrollment device belongs to the employee's current active areas
        $enrollSnInActiveArea = false;
        if ($enrollSn) {
            $employee = DB::connection('zkbio')->table('personnel_employee')
                ->where('emp_code', $controlNo)
                ->first();

            if ($employee) {
                $activeAreaIds = DB::connection('zkbio')->table('personnel_employee_area')
                    ->where('employee_id', $employee->id)
                    ->pluck('area_id')
                    ->all();

                $enrollTerminal = DB::connection('zkbio')->table('iclock_terminal')
                    ->where('sn', $enrollSn)
                    ->first();

                if ($enrollTerminal && in_array((string) $enrollTerminal->area_id, array_map('strval', $activeAreaIds), true)) {
                    $enrollSnInActiveArea = true;
                }
            }
        }

        // Build list of all verified devices strictly belonging to employee's active areas
        $allDevices = array_values(array_unique(array_filter(array_merge(
            $enrollSnInActiveArea ? [$enrollSn] : [],
            $targetSyncs
        ))));

        // A device is synced if target sync succeeded or employee is active on enrollment terminal
        $hasVerifiedDevice = count($allDevices) > 0;

        if ($hasVerifiedDevice) {
            $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
            $enrollment->notes = 'Enrolled on '.($enrollSn ?: 'terminal').' and synchronized to target terminal(s)';
        } else {
            $enrollment->status = BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED;
            $enrollment->notes = 'Enrolled on '.($enrollSn ?: 'terminal').'; awaiting target device synchronization';
        }

        if ($enrollSn) {
            $enrollment->enrollment_device_sn = $enrollSn;
        }
        $enrollment->enrolled_at = $enrolledAt;
        $enrollment->synced_devices = $allDevices;
        $enrollment->save();

        // Idempotently close any pending local device commands for this employee
        try {
            BiometricDeviceCommand::query()
                ->where('employee_control_no', $controlNo)
                ->where('status', BiometricDeviceCommand::STATUS_PENDING)
                ->update([
                    'status' => BiometricDeviceCommand::STATUS_SUCCESS,
                    'executed_at' => now(),
                    'response_payload' => 'Completed via ZKBio Time biometric reconciliation',
                ]);
        } catch (Throwable $e) {
            Log::warning('ZkBioTimeReconciliationService: Failed to update local commands', [
                'control_no' => $controlNo,
                'error' => $e->getMessage(),
            ]);
        }

        return $enrollment;
    }

    /**
     * Reconcile all pending biometric enrollments.
     * Only queries records with PENDING_ENROLLMENT or FINGERPRINT_ENROLLED to avoid unnecessary queries.
     *
     * @return int Number of records updated.
     */
    public function reconcilePendingEnrollments(): int
    {
        $pending = BiometricEnrollment::query()
            ->whereIn('status', [
                BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
                BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED,
            ])
            ->get();

        $updatedCount = 0;
        foreach ($pending as $enrollment) {
            $oldStatus = $enrollment->status;
            $this->reconcileEnrollment($enrollment);
            if ($enrollment->status !== $oldStatus) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * Exclusively assign an employee to a specific ZKBio Time Area and trigger terminal sync.
     * When an employee is assigned exclusively to one Area:
     * 1. ZKBio Time automatically issues DATA UPDATE USERINFO and DATA UPDATE FINGERTMP to that Area's device(s).
     * 2. ZKBio Time automatically issues DATA DELETE USERINFO to the device(s) in their previous Area(s).
     * 3. Devices in other Areas across City Hall never receive the employee (no multi-terminal cross-sync).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     zkbio_id?: int,
     *     area_id?: int
     * }
     */
    public function assignEmployeeExclusivelyToArea(string $empCode, int $areaId, ?string $fullName = null): array
    {
        $token = $this->getZkBioToken();
        if (! $token) {
            return [
                'success' => false,
                'message' => 'Unable to authenticate with ZKBio Time API. Please check credentials in configuration.',
            ];
        }

        $client = Http::timeout(15)->withHeaders(['Authorization' => 'Token '.$token]);

        try {
            $state = $this->getEmployeeBiometricState($empCode);
            $zkbioId = $state['zkbio_id'] ?? null;

            if ($state['found'] && $zkbioId) {
                // Update employee area exclusively
                $patchResponse = $client->patch($this->zkBioUrl("personnel/api/employees/{$zkbioId}/"), [
                    'area' => [$areaId],
                ]);

                if (! $patchResponse->successful()) {
                    Log::error('ZkBioTimeReconciliationService: PATCH employee area failed', [
                        'emp_code' => $empCode,
                        'zkbio_id' => $zkbioId,
                        'area_id' => $areaId,
                        'status' => $patchResponse->status(),
                        'body' => $patchResponse->body(),
                    ]);

                    return [
                        'success' => false,
                        'message' => "ZKBio Time returned HTTP {$patchResponse->status()} on area update: {$patchResponse->body()}",
                    ];
                }
            } else {
                // Create employee in ZKBio Time assigned exclusively to this area
                $createResponse = $client->post($this->zkBioUrl('personnel/api/employees/'), [
                    'emp_code' => $empCode,
                    'first_name' => $fullName ?: 'EMP '.$empCode,
                    'department' => 1,
                    'area' => [$areaId],
                ]);

                if (! $createResponse->successful()) {
                    Log::error('ZkBioTimeReconciliationService: CREATE employee in ZKBio Time failed', [
                        'emp_code' => $empCode,
                        'area_id' => $areaId,
                        'status' => $createResponse->status(),
                        'body' => $createResponse->body(),
                    ]);

                    return [
                        'success' => false,
                        'message' => "ZKBio Time returned HTTP {$createResponse->status()} on employee creation: {$createResponse->body()}",
                    ];
                }

                $zkbioId = (int) $createResponse->json('id');
            }

            // Trigger resync_to_device so ZKBio Time immediately sends commands to the target terminal
            $resyncResponse = $client->post($this->zkBioUrl('personnel/api/employees/resync_to_device/'), [
                'employees' => [$zkbioId],
            ]);

            if (! $resyncResponse->successful()) {
                Log::warning('ZkBioTimeReconciliationService: resync_to_device warning', [
                    'emp_code' => $empCode,
                    'zkbio_id' => $zkbioId,
                    'body' => $resyncResponse->body(),
                ]);
            }

            Log::info("ZkBioTimeReconciliationService: Employee [{$empCode}] assigned exclusively to Area [{$areaId}]", [
                'zkbio_id' => $zkbioId,
            ]);

            return [
                'success' => true,
                'message' => "Employee biometrics routed to target terminal (Area {$areaId}) and cleared from previous terminal.",
                'zkbio_id' => $zkbioId,
                'area_id' => $areaId,
            ];
        } catch (Throwable $e) {
            Log::error('ZkBioTimeReconciliationService: assignEmployeeExclusivelyToArea exception', [
                'emp_code' => $empCode,
                'area_id' => $areaId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to assign employee area in ZKBio Time: '.$e->getMessage(),
            ];
        }
    }
}
