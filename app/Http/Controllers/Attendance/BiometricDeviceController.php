<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\BiometricDevice;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisOffice;
use App\Services\Attendance\LanBioPunchSyncService;
use App\Services\Attendance\ZkSocketClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricDeviceController extends Controller
{
    /**
     * List all registered ZKTeco biometric devices mapped to offices.
     * Enriched with live telemetry from Native ADMS and LAN heartbeats.
     */
    public function listDevices(): JsonResponse
    {
        $configuredDevices = BiometricDevice::query()
            ->with(['department:id,name'])
            ->orderBy('id')
            ->get();

        $devices = [];
        $pendingCount = 0;

        foreach ($configuredDevices as $dev) {
            $isRecent = false;
            if ($dev->last_heartbeat_at) {
                try {
                    $isRecent = Carbon::parse($dev->last_heartbeat_at)->diffInSeconds(now()) <= 180;
                } catch (\Throwable) {
                    $isRecent = false;
                }
            }

            $isOnline = $dev->is_active && $isRecent;

            if ($dev->department_id === null) {
                $pendingCount++;
                $status = 'PENDING_APPROVAL';
            } elseif (! $dev->is_active) {
                $status = 'BLOCKED';
            } else {
                $status = $isOnline ? 'ONLINE' : 'OFFLINE';
            }

            $officeAcronym = HrisOffice::acronymForDepartment($dev->department, $dev->department_name);

            $devices[] = [
                'id' => $dev->id,
                'device_name' => $dev->device_name,
                'serial_number' => $dev->serial_number,
                'ip_address' => $dev->ip_address,
                'port' => $dev->port ?: 4370,
                'communication_mode' => $dev->communication_mode ?: 'ADMS',
                'model' => $dev->model_name,
                'model_name' => $dev->model_name,
                'firmware_version' => $dev->firmware_version,
                'comm_key' => $dev->comm_key,
                'department_id' => $dev->department_id,
                'department_name' => $dev->department?->name ?? $dev->department_name,
                'office_acronym' => $officeAcronym,
                'location' => $officeAcronym
                    ? "{$officeAcronym} - {$dev->department?->name}"
                    : ($dev->department_name ?: ($dev->department_id ? 'Office Terminal' : 'Unassigned')),
                'is_primary' => (bool) $dev->is_primary,
                'is_active' => (bool) $dev->is_active,
                'is_online' => $isOnline,
                'status' => $status,
                'last_activity_at' => $dev->last_heartbeat_at,
                'last_heartbeat_at' => $dev->last_heartbeat_at,
                'last_sync_at' => $dev->last_sync_at,
                'user_count' => $dev->device_user_count,
                'device_user_count' => $dev->device_user_count,
                'fingerprint_count' => $dev->device_finger_count,
                'device_finger_count' => $dev->device_finger_count,
                'face_count' => $dev->device_face_count,
                'device_face_count' => $dev->device_face_count,
                'log_count' => $dev->device_log_count,
                'device_log_count' => $dev->device_log_count,
            ];
        }

        return response()->json([
            'devices' => $devices,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * Register / Authorize a new ZKTeco Biometric Terminal.
     * Accessible only to HR accounts.
     */
    public function storeDevice(Request $request): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can authorize new biometric devices.',
            ], 403);
        }

        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:100'],
            'device_name' => ['required', 'string', 'max:150'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'department_id' => ['required', 'integer', 'exists:tblDepartments,id'],
            'communication_mode' => ['nullable', 'string', 'in:ADMS,LAN_POLL,OFFLINE_USB'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $mode = $validated['communication_mode'] ?? 'ADMS';

        if ($mode === 'LAN_POLL' && empty($validated['ip_address'])) {
            return response()->json([
                'message' => 'IP Address is required for devices in LAN Direct Poll mode.',
            ], 422);
        }

        $serialNumber = trim($validated['serial_number']);
        $deptId = (int) $validated['department_id'];
        $department = Department::query()->findOrFail($deptId);

        $isPrimary = $request->boolean('is_primary', true);
        if ($isPrimary) {
            BiometricDevice::query()
                ->where('department_id', $deptId)
                ->update(['is_primary' => false]);
        }

        $cleanIp = ($mode === 'LAN_POLL' && ! empty($validated['ip_address']))
            ? trim($validated['ip_address'])
            : null;

        $existing = BiometricDevice::query()->where('serial_number', $serialNumber)->first();
        if ($existing) {
            $existing->update([
                'device_name' => trim($validated['device_name']),
                'model_name' => trim($validated['model_name'] ?? '') ?: $existing->model_name,
                'department_id' => $deptId,
                'department_name' => $department->name,
                'communication_mode' => $mode,
                'is_primary' => $isPrimary,
                'comm_key' => trim($validated['comm_key'] ?? '') ?: ($existing->comm_key ?: '0'),
                'ip_address' => $cleanIp ?: ($existing->ip_address ?: null),
                'port' => $validated['port'] ?? ($existing->port ?: 4370),
                'is_active' => true,
            ]);

            return response()->json([
                'message' => "Biometric terminal '{$existing->device_name}' successfully authorized and assigned to {$department->name}.",
                'device' => $existing->fresh(),
            ], 200);
        }

        $device = BiometricDevice::query()->create([
            'serial_number' => $serialNumber,
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? ''),
            'department_id' => $deptId,
            'department_name' => $department->name,
            'communication_mode' => $mode,
            'is_primary' => $isPrimary,
            'comm_key' => trim($validated['comm_key'] ?? '') ?: '0',
            'ip_address' => $cleanIp,
            'port' => $validated['port'] ?? 4370,
            'is_active' => true,
            'status' => 'OFFLINE',
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' successfully authorized for {$department->name}.",
            'device' => $device,
        ], 201);
    }

    /**
     * Authorize an auto-detected biometric device awaiting HR approval.
     */
    public function authorizeDevice(Request $request, int $id): JsonResponse
    {
        return $this->updateDevice($request, $id);
    }

    /**
     * Update an authorized biometric device details.
     * Accessible only to HR accounts.
     */
    public function updateDevice(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can update biometric devices.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:150'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'department_id' => ['required', 'integer', 'exists:tblDepartments,id'],
            'communication_mode' => ['nullable', 'string', 'in:ADMS,LAN_POLL,OFFLINE_USB'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $mode = $validated['communication_mode'] ?? ($device->communication_mode ?: 'ADMS');

        if ($mode === 'LAN_POLL' && empty($validated['ip_address'])) {
            return response()->json([
                'message' => 'IP Address is required for devices in LAN Direct Poll mode.',
            ], 422);
        }

        $deptId = (int) $validated['department_id'];
        $department = Department::query()->findOrFail($deptId);

        $isPrimary = $request->boolean('is_primary', true);
        if ($isPrimary) {
            BiometricDevice::query()
                ->where('department_id', $deptId)
                ->where('id', '!=', $device->id)
                ->update(['is_primary' => false]);
        }

        $cleanIp = ($mode === 'LAN_POLL' && ! empty($validated['ip_address']))
            ? trim($validated['ip_address'])
            : null;

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: $device->model_name,
            'department_id' => $deptId,
            'department_name' => $department->name,
            'communication_mode' => $mode,
            'is_primary' => $isPrimary,
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => $cleanIp ?: ($device->ip_address ?: null),
            'port' => $validated['port'] ?? ($device->port ?: 4370),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' updated successfully.",
            'device' => $device->fresh(),
        ]);
    }

    /**
     * Toggle active authorization status for a device (Enable/Disable).
     * Accessible only to HR accounts.
     */
    public function toggleDeviceStatus(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can modify biometric device authorization.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);
        $device->is_active = ! $device->is_active;
        if (! $device->is_active) {
            $device->status = 'OFFLINE';
        }
        $device->save();

        $action = $device->is_active ? 'enabled' : 'disabled';

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' has been {$action}.",
            'device' => $device,
        ]);
    }

    /**
     * Delete an authorized biometric terminal.
     * Accessible only to HR accounts.
     */
    public function deleteDevice(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can delete biometric devices.',
            ], 403);
        }

        if ($id === 0 && $request->filled('serial_number')) {
            $serialNumber = trim((string) $request->input('serial_number'));
            BiometricDevice::query()->where('serial_number', $serialNumber)->delete();

            return response()->json([
                'message' => "Biometric terminal '{$serialNumber}' has been removed.",
            ]);
        }

        $device = BiometricDevice::query()->findOrFail($id);
        $name = $device->device_name;
        $device->delete();

        return response()->json([
            'message' => "Biometric terminal '{$name}' has been deleted.",
        ]);
    }

    /**
     * Test connection to a LAN biometric terminal over TCP port 4370.
     * Accessible only to HR accounts.
     */
    public function testConnection(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can test device connectivity.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);

        $ip = trim((string) $device->ip_address);
        if ($ip === '') {
            return response()->json([
                'success' => false,
                'message' => "Device '{$device->device_name}' does not have an IP address configured.",
            ], 422);
        }

        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '169.254.')) {
            return response()->json([
                'success' => false,
                'message' => 'Testing connection to loopback or cloud metadata IP addresses is not permitted.',
            ], 422);
        }

        $client = new ZkSocketClient(
            ip: $device->ip_address,
            port: $device->port ?: 4370,
            timeout: 2.5,
            commKey: (int) ($device->comm_key ?? 0)
        );

        $result = $client->testConnection();

        if ($result['success']) {
            $device->update([
                'status' => BiometricDevice::STATUS_ONLINE,
                'last_heartbeat_at' => now(),
            ]);
        } else {
            $device->update([
                'status' => BiometricDevice::STATUS_OFFLINE,
            ]);
        }

        return response()->json([
            'success' => $result['success'],
            'latency_ms' => $result['latency_ms'],
            'message' => $result['message'],
            'status' => $result['success'] ? BiometricDevice::STATUS_ONLINE : BiometricDevice::STATUS_OFFLINE,
            'device' => $device->fresh(),
        ]);
    }

    /**
     * Trigger immediate manual sync for a City Hall LAN terminal.
     * Accessible only to HR accounts.
     */
    public function syncLanDevice(Request $request, int $id, LanBioPunchSyncService $syncService): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can sync biometric devices.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);

        if (empty(trim((string) $device->ip_address))) {
            return response()->json([
                'success' => false,
                'message' => "Device '{$device->device_name}' does not have an IP address configured for direct socket communication.",
            ], 422);
        }

        $result = $syncService->syncDevice($device);

        $cleanMessage = mb_convert_encoding($result['message'], 'UTF-8', 'UTF-8');

        return response()->json([
            'success' => $result['success'],
            'message' => $cleanMessage,
            'imported' => $result['imported'],
            'duplicates' => $result['duplicates'],
            'total_read' => $result['total_read'],
            'device' => $device->fresh(),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Pull punches from biometric device(s) assigned to the current office/department.
     * Connects directly to LAN devices (port 4370); ADMS devices push punches automatically in real-time.
     * Accessible to Department Admin (for their own office) and HR.
     */
    public function syncOfficeDevices(
        Request $request,
        LanBioPunchSyncService $lanSyncService
    ): JsonResponse {
        $account = $request->user();
        $isHr = $account instanceof HRAccount;
        $isAdmin = $account instanceof DepartmentAdmin;

        if (! $isHr && ! $isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access. Only Office Administrators or HR can pull punches.',
            ], 403);
        }

        $departmentId = $isAdmin
            ? (int) $account->department_id
            : (int) ($request->input('department_id') ?: 0);

        if (! $departmentId) {
            return response()->json([
                'success' => false,
                'message' => 'Department ID is required to identify office biometric devices.',
            ], 422);
        }

        $department = Department::query()->find($departmentId);
        $officeLabel = HrisOffice::labelForDepartment($department) ?: "Office #{$departmentId}";

        $devices = BiometricDevice::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->get();

        if ($devices->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "No active biometric terminal is registered for {$officeLabel}. Please contact HR to register or assign your office device.",
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
                'devices_synced' => 0,
            ], 404);
        }

        $directPollDevices = $devices->filter(fn ($dev) => ! empty(trim((string) $dev->ip_address)));

        // If all devices are pure ADMS Cloud Push without direct IP:
        if ($directPollDevices->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => "Biometric devices for {$officeLabel} are in ADMS mode and push attendance punches in real-time.",
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
                'devices_synced' => $devices->count(),
                'device_names' => $devices->pluck('device_name')->all(),
            ]);
        }

        // Connect directly to physical LAN terminals over TCP 4370
        $totalImported = 0;
        $totalDuplicates = 0;
        $totalRead = 0;
        $successfulDevices = [];
        $failedDevices = [];

        foreach ($directPollDevices as $device) {
            $result = $lanSyncService->syncDevice($device);

            if ($result['success']) {
                $totalImported += (int) ($result['imported'] ?? 0);
                $totalDuplicates += (int) ($result['duplicates'] ?? 0);
                $totalRead += (int) ($result['total_read'] ?? 0);
                $successfulDevices[] = $device->device_name;
            } else {
                $cleanErrMsg = mb_convert_encoding($result['message'] ?? 'Connection error', 'UTF-8', 'UTF-8');
                $failedDevices[] = "{$device->device_name}: {$cleanErrMsg}";
            }
        }

        if (empty($successfulDevices) && ! empty($failedDevices)) {
            return response()->json([
                'success' => false,
                'message' => mb_convert_encoding($failedDevices[0], 'UTF-8', 'UTF-8'),
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
                'devices_synced' => 0,
            ], 422);
        }

        $devCount = count($successfulDevices);
        $devNames = implode(', ', $successfulDevices);

        if ($totalImported > 0) {
            $logWord = $totalImported === 1 ? 'log' : 'logs';
            $summary = "Successfully synced {$totalImported} new attendance {$logWord}.";
        } elseif ($totalRead > 0) {
            $summary = "Attendance logs are up to date ({$totalRead} logs checked).";
        } else {
            $summary = "Connected to {$devNames}: No punches recorded on terminal.";
        }

        if (! empty($failedDevices)) {
            $summary .= ' (Warning: '.implode('; ', $failedDevices).')';
        }

        $cleanSummary = mb_convert_encoding($summary, 'UTF-8', 'UTF-8');

        return response()->json([
            'success' => true,
            'message' => $cleanSummary,
            'imported' => $totalImported,
            'duplicates' => $totalDuplicates,
            'total_read' => $totalRead,
            'devices_synced' => $devCount,
            'device_names' => $successfulDevices,
        ]);
    }
}
