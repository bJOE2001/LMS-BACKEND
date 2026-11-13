<?php

namespace App\Services\Attendance;

use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\BiometricEnrollment;
use App\Models\Attendance\BiometricTemplate;
use App\Models\HrisEmployee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service for standalone LAN TCP port 4370 biometric user and fingerprint synchronization.
 * Isolates templates under communication_mode = 'LAN_POLL' so ADMS and LAN socket templates never cross-contaminate.
 */
class LanBioTemplateSyncService
{
    /**
     * Pull enrolled users and fingerprint templates from a LAN_POLL terminal into tblBiometricTemplates.
     *
     * @return array{
     *     success: bool,
     *     users_read: int,
     *     templates_saved: int,
     *     message: string
     * }
     */
    public function pullTemplatesFromDevice(
        BiometricDevice $device,
        ?string $onlyControlNo = null,
        ?ZkSocketClient $client = null
    ): array {
        if (empty(trim((string) $device->ip_address))) {
            return [
                'success' => false,
                'users_read' => 0,
                'templates_saved' => 0,
                'message' => "Device '{$device->device_name}' has no IP address configured.",
            ];
        }

        $lockKey = 'zk_lan_tpl_lock_'.($device->id ?: $device->serial_number);
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return [
                'success' => false,
                'users_read' => 0,
                'templates_saved' => 0,
                'message' => "Device '{$device->device_name}' is busy with another socket operation.",
            ];
        }

        $ownsConnection = $client === null;

        try {
            $zk = $client ?? new ZkSocketClient(
                ip: (string) $device->ip_address,
                port: $device->port ?: 4370,
                timeout: 3.5,
                commKey: (int) ($device->comm_key ?? 0)
            );

            if (! $zk->connect()) {
                return [
                    'success' => false,
                    'users_read' => 0,
                    'templates_saved' => 0,
                    'message' => "Unable to connect to '{$device->device_name}' ({$device->ip_address}:{$device->port}).",
                ];
            }

            $users = $zk->getUsers();
            $rawTemplates = $zk->getFingerprintTemplates();

            if ($ownsConnection) {
                $zk->disconnect();
            }

            $communicationMode = $device->getTemplateCommunicationMode();
            $uidToUser = [];
            foreach ($users as $u) {
                $uidToUser[$u['uid']] = $u;
            }

            $normalizedFilter = $onlyControlNo !== null ? ltrim(trim($onlyControlNo), '0') : null;
            $savedCount = 0;

            foreach ($rawTemplates as $tpl) {
                $userRec = $uidToUser[$tpl['uid']] ?? null;
                if (! $userRec) {
                    continue;
                }

                $rawPin = trim($userRec['pin']);
                if ($rawPin === '') {
                    continue;
                }

                $emp = HrisEmployee::findByControlNo($rawPin, true);
                $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $rawPin;

                if ($onlyControlNo !== null) {
                    $matchesExact = $canonicalControlNo === trim($onlyControlNo) || $rawPin === trim($onlyControlNo);
                    $matchesNormalized = $normalizedFilter !== '' && (
                        ltrim($canonicalControlNo, '0') === $normalizedFilter
                        || ltrim($rawPin, '0') === $normalizedFilter
                    );
                    if (! $matchesExact && ! $matchesNormalized) {
                        continue;
                    }
                }

                $port = $device->port ?: 4370;
                $rawPayload = "PROTOCOL=LAN_TCP;IP={$device->ip_address}:{$port};CMD=7;UID={$tpl['uid']};FID={$tpl['fid']};SIZE={$tpl['size']}";

                $encodedTemplate = base64_encode($tpl['template']);
                BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'communication_mode' => $communicationMode,
                        'biometric_type' => BiometricTemplate::TYPE_FINGERPRINT,
                        'finger_id' => $tpl['fid'],
                    ],
                    [
                        'template_size' => $tpl['size'],
                        'valid' => $tpl['valid'],
                        'template_version' => '10',
                        'template_data' => $encodedTemplate,
                        'raw_payload' => $rawPayload,
                        'source_device_sn' => $device->serial_number,
                    ]
                );

                $fullName = $emp
                    ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                    : ($userRec['name'] !== '' ? $userRec['name'] : 'EMP '.$canonicalControlNo);

                $this->markEmployeeRegisteredOnDevice($canonicalControlNo, $fullName, $device->serial_number);
                $savedCount++;
            }

            try {
                $device->update([
                    'device_user_count' => count($users),
                    'device_finger_count' => count($rawTemplates),
                    'last_heartbeat_at' => now(),
                    'status' => BiometricDevice::STATUS_ONLINE,
                ]);
            } catch (Throwable) {
                // Ignore non-critical device counter update failure
            }

            return [
                'success' => true,
                'users_read' => count($users),
                'templates_saved' => $savedCount,
                'message' => "Pulled {$savedCount} {$communicationMode} fingerprint template(s) from '{$device->device_name}'.",
            ];
        } catch (Throwable $e) {
            if ($ownsConnection && isset($zk)) {
                $zk->disconnect();
            }
            Log::warning("LanBioTemplateSyncService: Pull failed on {$device->serial_number}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'users_read' => 0,
                'templates_saved' => 0,
                'message' => 'Failed pulling templates from terminal: '.$e->getMessage(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Push an employee's user profile and matching communication_mode fingerprint templates to a target LAN_POLL terminal.
     *
     * @return array{
     *     success: bool,
     *     user_pushed: bool,
     *     templates_pushed: int,
     *     message: string
     * }
     */
    public function pushEmployeeToDevice(
        BiometricDevice $device,
        string $controlNo,
        string $fullName,
        bool $includeTemplates = true,
        ?ZkSocketClient $client = null
    ): array {
        if (empty(trim((string) $device->ip_address))) {
            return [
                'success' => false,
                'user_pushed' => false,
                'templates_pushed' => 0,
                'message' => "Target terminal '{$device->device_name}' has no IP address configured.",
            ];
        }

        $lockKey = 'zk_lan_tpl_lock_'.($device->id ?: $device->serial_number);
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return [
                'success' => false,
                'user_pushed' => false,
                'templates_pushed' => 0,
                'message' => "Target terminal '{$device->device_name}' is busy with another socket operation.",
            ];
        }

        $ownsConnection = $client === null;

        try {
            $zk = $client ?? new ZkSocketClient(
                ip: (string) $device->ip_address,
                port: $device->port ?: 4370,
                timeout: 3.5,
                commKey: (int) ($device->comm_key ?? 0)
            );

            if (! $zk->connect()) {
                return [
                    'success' => false,
                    'user_pushed' => false,
                    'templates_pushed' => 0,
                    'message' => "Unable to connect to '{$device->device_name}' ({$device->ip_address}:{$device->port}).",
                ];
            }

            $existingUsers = $zk->getUsers();
            $uid = $this->resolveOrAllocateUid($existingUsers, $controlNo);

            $userOk = $zk->setUser($uid, $controlNo, $fullName, 0, '', 0);
            if (! $userOk) {
                if ($ownsConnection) {
                    $zk->disconnect();
                }

                return [
                    'success' => false,
                    'user_pushed' => false,
                    'templates_pushed' => 0,
                    'message' => "Terminal '{$device->device_name}' rejected user profile write for PIN {$controlNo}.",
                ];
            }

            $templatesPushed = 0;
            if ($includeTemplates) {
                $communicationMode = $device->getTemplateCommunicationMode();
                $templates = BiometricTemplate::query()
                    ->where('employee_control_no', $controlNo)
                    ->where('communication_mode', $communicationMode)
                    ->where('biometric_type', BiometricTemplate::TYPE_FINGERPRINT)
                    ->orderBy('finger_id')
                    ->get();

                foreach ($templates as $tpl) {
                    $rawBytes = base64_decode((string) $tpl->template_data, true);
                    if ($rawBytes === false || $rawBytes === '') {
                        continue;
                    }

                    if ($zk->setFingerprintTemplate($uid, (int) $tpl->finger_id, $rawBytes, (int) ($tpl->valid ?? 1))) {
                        $templatesPushed++;
                    }
                }
            }

            if ($ownsConnection) {
                $zk->disconnect();
            }

            return [
                'success' => true,
                'user_pushed' => true,
                'templates_pushed' => $templatesPushed,
                'message' => $templatesPushed > 0
                    ? "Pushed user profile and {$templatesPushed} LAN fingerprint template(s) to '{$device->device_name}'."
                    : "Pushed user profile (PIN {$controlNo}) to '{$device->device_name}'.",
            ];
        } catch (Throwable $e) {
            if ($ownsConnection && isset($zk)) {
                $zk->disconnect();
            }
            Log::warning("LanBioTemplateSyncService: Push failed on {$device->serial_number}", [
                'control_no' => $controlNo,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_pushed' => false,
                'templates_pushed' => 0,
                'message' => 'Failed pushing biometrics to terminal: '.$e->getMessage(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete an employee's user profile and fingerprint templates from a LAN_POLL terminal.
     */
    public function deleteEmployeeFromDevice(
        BiometricDevice $device,
        string $controlNo,
        ?ZkSocketClient $client = null
    ): bool {
        if (empty(trim((string) $device->ip_address))) {
            return false;
        }

        $lockKey = 'zk_lan_tpl_lock_'.($device->id ?: $device->serial_number);
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return false;
        }

        $ownsConnection = $client === null;

        try {
            $zk = $client ?? new ZkSocketClient(
                ip: (string) $device->ip_address,
                port: $device->port ?: 4370,
                timeout: 3.0,
                commKey: (int) ($device->comm_key ?? 0)
            );

            if (! $zk->connect()) {
                return false;
            }

            $deleted = $zk->deleteUserByPin($controlNo);

            if ($ownsConnection) {
                $zk->disconnect();
            }

            return $deleted;
        } catch (Throwable $e) {
            if ($ownsConnection && isset($zk)) {
                $zk->disconnect();
            }
            Log::warning("LanBioTemplateSyncService: Delete failed on {$device->serial_number}", [
                'control_no' => $controlNo,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Find an existing UID matching controlNo or allocate the lowest unused positive 2-byte UID.
     *
     * @param  array<int, array{uid: int, pin: string, name: string, privilege: int, password: string, card: int}>  $existingUsers
     */
    private function resolveOrAllocateUid(array $existingUsers, string $controlNo): int
    {
        $normalizedTarget = ltrim(trim($controlNo), '0');
        $usedUids = [];

        foreach ($existingUsers as $u) {
            $usedUids[$u['uid']] = true;
            $candidate = trim($u['pin']);
            if ($candidate === trim($controlNo) || ($normalizedTarget !== '' && ltrim($candidate, '0') === $normalizedTarget)) {
                return $u['uid'];
            }
        }

        $uid = 1;
        while (isset($usedUids[$uid]) && $uid < 65534) {
            $uid++;
        }

        return $uid;
    }

    private function markEmployeeRegisteredOnDevice(string $controlNo, string $fullName, string $serialNumber): void
    {
        $enrollment = BiometricEnrollment::query()
            ->where('employee_control_no', $controlNo)
            ->first();

        if (! $enrollment) {
            BiometricEnrollment::query()->create([
                'employee_control_no' => $controlNo,
                'employee_name' => $fullName,
                'status' => BiometricEnrollment::STATUS_REGISTERED,
                'enrollment_device_sn' => $serialNumber,
                'enrolled_at' => now(),
                'synced_devices' => [$serialNumber],
            ]);

            return;
        }

        $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
        $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
        $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: $serialNumber;
        $synced = is_array($enrollment->synced_devices) ? $enrollment->synced_devices : [];
        if (! in_array($serialNumber, $synced, true)) {
            $synced[] = $serialNumber;
            $enrollment->synced_devices = $synced;
        }
        $enrollment->save();
    }
}
