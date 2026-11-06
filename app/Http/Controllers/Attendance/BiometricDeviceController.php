<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Services\Attendance\LanBioPunchSyncService;
use App\Services\Attendance\ZkBioPunchSyncService;
use App\Services\Attendance\ZkBioTerminalProvisioningService;
use App\Services\Attendance\ZkSocketClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BiometricDeviceController extends Controller
{
    public function __construct(
        protected ZkBioTerminalProvisioningService $provisioningService
    ) {}

    /**
     * List all registered ZKTeco biometric devices mapped to offices.
     * Enriched with live telemetry from ZKBio Time terminals.
     */
    public function listDevices(): JsonResponse
    {
        // 1. Fetch live telemetry and areas from ZKBio Time
        $zkTerminalsBySn = [];
        $zkAreasById = [];

        try {
            $zkTerminals = DB::connection('zkbio')
                ->table('iclock_terminal')
                ->get();

            foreach ($zkTerminals as $zt) {
                $zkTerminalsBySn[trim((string) $zt->sn)] = $zt;
            }

            $zkAreas = DB::connection('zkbio')
                ->table('personnel_area')
                ->get();

            foreach ($zkAreas as $za) {
                $code = ! empty($za->area_code) ? $za->area_code : $za->id;
                $zkAreasById[(int) $za->id] = "Area {$code} ({$za->area_name})";
            }
        } catch (Throwable) {
            // ZKBio DB offline fallback
        }

        // 2. Load configured devices in BIO_DB with LMS Department details
        $configuredDevices = BiometricDevice::query()
            ->with(['department:id,name,acronym,code'])
            ->orderBy('id')
            ->get();

        $devices = [];
        $configuredSns = [];

        foreach ($configuredDevices as $dev) {
            $sn = trim((string) $dev->serial_number);
            $configuredSns[] = $sn;

            $zkLive = $zkTerminalsBySn[$sn] ?? null;
            $areaId = $dev->zkbio_area_id ?: ($zkLive?->area_id ? (int) $zkLive->area_id : null);
            $areaName = $areaId ? ($zkAreasById[$areaId] ?? "Area {$areaId}") : null;

            // Determine live online status
            $isOnline = false;
            $lastActivity = $zkLive?->last_activity ?? $dev->last_heartbeat_at;

            $isRecent = false;
            if ($lastActivity) {
                try {
                    $lastActivityCarbon = \Carbon\Carbon::parse($lastActivity);
                    // Device sends heartbeats every 60s. If no heartbeat within 3 minutes (180s), it is OFFLINE.
                    $isRecent = $lastActivityCarbon->diffInSeconds(now()) <= 180;
                } catch (\Throwable) {
                    $isRecent = false;
                }
            }

            if ($zkLive) {
                // Must be both reported active by ZKBio (state == 1) AND have a fresh heartbeat within 3 minutes
                $isOnline = ((int) $zkLive->state === 1) && $isRecent;

                $updates = [];
                $liveIp = $this->provisioningService->normalizeIp($zkLive->ip_address ?? null);
                if ($liveIp !== null && $dev->ip_address !== $liveIp) {
                    $updates['ip_address'] = $liveIp;
                }
                $liveStatus = $isOnline ? BiometricDevice::STATUS_ONLINE : BiometricDevice::STATUS_OFFLINE;
                if ($dev->status !== $liveStatus) {
                    $updates['status'] = $liveStatus;
                }
                if ($zkLive->last_activity && $dev->last_heartbeat_at !== $zkLive->last_activity) {
                    $updates['last_heartbeat_at'] = $zkLive->last_activity;
                }
                if (! empty($zkLive->terminal_name) && $dev->model_name !== $zkLive->terminal_name) {
                    $updates['model_name'] = $zkLive->terminal_name;
                }
                if (! empty($zkLive->fw_ver) && $dev->firmware_version !== $zkLive->fw_ver) {
                    $updates['firmware_version'] = $zkLive->fw_ver;
                }
                if (isset($zkLive->user_count) && (int) $dev->device_user_count !== (int) $zkLive->user_count) {
                    $updates['device_user_count'] = (int) $zkLive->user_count;
                }
                if (isset($zkLive->fp_count) && (int) $dev->device_finger_count !== (int) $zkLive->fp_count) {
                    $updates['device_finger_count'] = (int) $zkLive->fp_count;
                }
                if (isset($zkLive->face_count) && (int) $dev->device_face_count !== (int) $zkLive->face_count) {
                    $updates['device_face_count'] = (int) $zkLive->face_count;
                }
                if (isset($zkLive->transaction_count) && (int) $dev->device_log_count !== (int) $zkLive->transaction_count) {
                    $updates['device_log_count'] = (int) $zkLive->transaction_count;
                }
                $lastSync = $zkLive->upload_time ?? $zkLive->push_time;
                if ($lastSync && $dev->last_sync_at !== $lastSync) {
                    $updates['last_sync_at'] = $lastSync;
                }
                if (! empty($updates)) {
                    $dev->update($updates);
                }
            } elseif ($dev->last_heartbeat_at || $dev->status) {
                $isOnline = $isRecent && ($dev->status === BiometricDevice::STATUS_ONLINE);
                if ($dev->status === BiometricDevice::STATUS_ONLINE && ! $isRecent) {
                    $dev->update(['status' => BiometricDevice::STATUS_OFFLINE]);
                }
            }

            $devices[] = [
                'id' => $dev->id,
                'device_name' => $dev->device_name,
                'serial_number' => $dev->serial_number,
                'ip_address' => $this->provisioningService->normalizeIp($dev->ip_address)
                    ?: $this->provisioningService->normalizeIp($zkLive?->ip_address ?? null),
                'port' => $dev->port ?: 4370,
                'communication_mode' => $dev->communication_mode ?: 'ADMS',
                'model' => $dev->model_name ?: ($zkLive?->terminal_name ?? 'MB360'),
                'model_name' => $dev->model_name ?: ($zkLive?->terminal_name ?? 'MB360'),
                'firmware_version' => $dev->firmware_version ?: ($zkLive?->fw_ver ?? null),
                'comm_key' => $dev->comm_key,
                'department_id' => $dev->department_id,
                'department_name' => $dev->department?->name ?? $dev->department_name,
                'office_acronym' => $dev->department?->acronym,
                'location' => $dev->department?->acronym ? "{$dev->department->acronym} - {$dev->department->name}" : ($dev->department_name ?: 'Tagum City Hall'),
                'zkbio_area_id' => $areaId,
                'zkbio_area_name' => $areaName,
                'is_primary' => (bool) $dev->is_primary,
                'is_active' => (bool) $dev->is_active,
                'is_online' => $isOnline,
                'status' => $dev->is_active ? ($isOnline ? 'ONLINE' : 'OFFLINE') : 'BLOCKED',
                'last_activity_at' => $lastActivity,
                'last_heartbeat_at' => $lastActivity,
                'last_sync_at' => $dev->last_sync_at ?: ($zkLive?->upload_time ?? $zkLive?->push_time),
                'user_count' => $zkLive?->user_count ?? $dev->device_user_count,
                'device_user_count' => $zkLive?->user_count ?? $dev->device_user_count,
                'fingerprint_count' => $zkLive?->fp_count ?? $dev->device_finger_count,
                'device_finger_count' => $zkLive?->fp_count ?? $dev->device_finger_count,
                'face_count' => $zkLive?->face_count ?? $dev->device_face_count,
                'device_face_count' => $zkLive?->face_count ?? $dev->device_face_count,
                'log_count' => $zkLive?->transaction_count ?? $dev->device_log_count,
                'device_log_count' => $zkLive?->transaction_count ?? $dev->device_log_count,
            ];
        }

        // 3. Detect unconfigured terminals in ZKBio Time awaiting office mapping
        $pendingCount = 0;
        foreach ($zkTerminalsBySn as $sn => $zt) {
            if (! in_array($sn, $configuredSns, true)) {
                $pendingCount++;
                $areaId = $zt->area_id ? (int) $zt->area_id : null;
                $areaName = $areaId ? ($zkAreasById[$areaId] ?? "Area {$areaId}") : null;

                $ztRecent = false;
                if ($zt->last_activity) {
                    try {
                        $ztRecent = \Carbon\Carbon::parse($zt->last_activity)->diffInSeconds(now()) <= 180;
                    } catch (\Throwable) {
                        $ztRecent = false;
                    }
                }

                $devices[] = [
                    'id' => null,
                    'device_name' => $zt->alias ?: $sn,
                    'serial_number' => $sn,
                    'ip_address' => $this->provisioningService->normalizeIp($zt->ip_address ?? null),
                    'port' => 4370,
                    'communication_mode' => 'ADMS',
                    'model' => $zt->terminal_name ?: 'MB360',
                    'model_name' => $zt->terminal_name ?: 'MB360',
                    'firmware_version' => $zt->fw_ver ?? null,
                    'comm_key' => '0',
                    'department_id' => null,
                    'department_name' => null,
                    'office_acronym' => null,
                    'location' => 'Unassigned',
                    'zkbio_area_id' => $areaId,
                    'zkbio_area_name' => $areaName,
                    'is_primary' => false,
                    'is_active' => false,
                    'is_online' => ((int) $zt->state === 1) && $ztRecent,
                    'status' => 'PENDING_APPROVAL',
                    'last_activity_at' => $zt->last_activity,
                    'last_heartbeat_at' => $zt->last_activity,
                    'last_sync_at' => $zt->upload_time ?? $zt->push_time,
                    'user_count' => $zt->user_count,
                    'device_user_count' => $zt->user_count,
                    'fingerprint_count' => $zt->fp_count,
                    'device_finger_count' => $zt->fp_count,
                    'face_count' => $zt->face_count ?? 0,
                    'device_face_count' => $zt->face_count ?? 0,
                    'log_count' => $zt->transaction_count ?? 0,
                    'device_log_count' => $zt->transaction_count ?? 0,
                ];
            }
        }

        return response()->json([
            'devices' => $devices,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * List active ZKBio Time Areas for device assignment.
     * Prohibits Area 1 (default non-synchronizing area).
     */
    public function listZkBioAreas(): JsonResponse
    {
        try {
            $areas = DB::connection('zkbio')
                ->table('personnel_area')
                ->orderBy('id')
                ->get();

            $result = $areas->map(function ($area): array {
                $isProhibited = ((int) $area->id === 1 || (int) ($area->is_default ?? 0) === 1);
                $code = ! empty($area->area_code) ? $area->area_code : $area->id;

                return [
                    'id' => (int) $area->id,
                    'area_code' => (string) $area->area_code,
                    'area_name' => (string) $area->area_name,
                    'is_default' => (bool) ($area->is_default ?? false),
                    'is_prohibited' => $isProhibited,
                    'label' => $isProhibited
                        ? "Area {$code} ({$area->area_name}) - Prohibited (Default non-sync area)"
                        : "Area {$code} ({$area->area_name})",
                ];
            });

            return response()->json([
                'areas' => $result,
            ]);
        } catch (Throwable) {
            return response()->json([
                'areas' => [
                    ['id' => 2, 'area_code' => '2', 'area_name' => 'CICTMO', 'is_prohibited' => false, 'label' => 'Area 2 (CICTMO)'],
                    ['id' => 4, 'area_code' => '3', 'area_name' => 'CHRMO', 'is_prohibited' => false, 'label' => 'Area 3 (CHRMO)'],
                ],
            ]);
        }
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
            'zkbio_area_id' => ['nullable', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
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

        $rawAreaId = $validated['zkbio_area_id'] ?? null;
        if ($rawAreaId !== null && (int) $rawAreaId === 1) {
            return response()->json([
                'message' => 'Area 1 (Default) cannot be used for office biometric synchronization.',
            ], 422);
        }

        $requestedAreaId = ($rawAreaId !== null && (int) $rawAreaId > 1) ? (int) $rawAreaId : null;
        $zkAreaId = $requestedAreaId;
        $areaCreatedMessage = null;

        if ($mode === 'ADMS') {
            $areaRes = $this->provisioningService->resolveOrCreateAreaForDepartment($department, $requestedAreaId);
            if (! $areaRes['success'] && $requestedAreaId !== null) {
                return response()->json([
                    'message' => $areaRes['message'],
                ], 422);
            }

            if ($areaRes['area_id'] !== null && $areaRes['area_id'] > 1) {
                $zkAreaId = $areaRes['area_id'];
                if ($areaRes['created']) {
                    $areaCreatedMessage = $areaRes['message'];
                }
            }
        }

        $isPrimary = $request->boolean('is_primary', true);
        if ($isPrimary) {
            BiometricDevice::query()
                ->where('department_id', $deptId)
                ->update(['is_primary' => false]);
        }

        $zkLive = null;
        $isOnline = false;
        $lastHeartbeat = null;
        try {
            $zkLive = DB::connection('zkbio')
                ->table('iclock_terminal')
                ->where('sn', $serialNumber)
                ->first();

            if ($zkLive && $zkLive->last_activity) {
                $diff = \Carbon\Carbon::parse($zkLive->last_activity)->diffInSeconds(now());
                $isOnline = ((int) $zkLive->state === 1) && ($diff <= 180);
                $lastHeartbeat = $zkLive->last_activity;
            }
        } catch (Throwable) {
            // ZKBio offline fallback
        }

        $inputIp = $this->provisioningService->normalizeIp($validated['ip_address'] ?? null);
        $liveIp = $this->provisioningService->normalizeIp($zkLive?->ip_address ?? null);

        $existing = BiometricDevice::query()->where('serial_number', $serialNumber)->first();
        if ($existing) {
            $existing->update([
                'device_name' => trim($validated['device_name']),
                'model_name' => trim($validated['model_name'] ?? '') ?: ($existing->model_name ?: 'MB360'),
                'department_id' => $deptId,
                'department_name' => $department->name,
                'communication_mode' => $mode,
                'zkbio_area_id' => $zkAreaId,
                'is_primary' => $isPrimary,
                'comm_key' => trim($validated['comm_key'] ?? '') ?: ($existing->comm_key ?: '0'),
                'ip_address' => $inputIp ?: ($liveIp ?: $this->provisioningService->normalizeIp($existing->ip_address)),
                'port' => $validated['port'] ?? ($existing->port ?: 4370),
                'is_active' => true,
                'status' => $isOnline ? 'ONLINE' : 'OFFLINE',
                'last_heartbeat_at' => $lastHeartbeat ?: $existing->last_heartbeat_at,
            ]);

            $device = $existing;
            $httpStatus = 200;
            $message = "Biometric terminal '{$device->device_name}' successfully authorized and activated for {$department->name}.";
        } else {
            $device = BiometricDevice::query()->create([
                'serial_number' => $serialNumber,
                'device_name' => trim($validated['device_name']),
                'model_name' => trim($validated['model_name'] ?? '') ?: ($zkLive?->terminal_name ?? 'MB360'),
                'department_id' => $deptId,
                'department_name' => $department->name,
                'communication_mode' => $mode,
                'zkbio_area_id' => $zkAreaId,
                'is_primary' => $isPrimary,
                'comm_key' => trim($validated['comm_key'] ?? '') ?: '0',
                'ip_address' => $inputIp ?: $liveIp,
                'port' => $validated['port'] ?? 4370,
                'is_active' => true,
                'status' => $isOnline ? 'ONLINE' : 'OFFLINE',
                'last_heartbeat_at' => $lastHeartbeat,
            ]);

            $httpStatus = 201;
            $message = "Biometric device '{$device->device_name}' successfully authorized for {$department->name}.";
        }

        $zkbioSynced = false;
        if ($mode === 'ADMS' && $zkAreaId !== null && $zkAreaId > 1) {
            $syncRes = $this->provisioningService->syncTerminalToZkBio($device, $zkAreaId);
            $zkbioSynced = $syncRes['success'];
            if ($zkbioSynced) {
                $message .= $areaCreatedMessage
                    ? " {$areaCreatedMessage} Auto-synced to ZKBio Time."
                    : ' Auto-synced to ZKBio Time.';
            }
        }

        return response()->json([
            'message' => $message,
            'device' => $device->fresh(),
            'zkbio_synced' => $zkbioSynced,
        ], $httpStatus);
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
            'zkbio_area_id' => ['nullable', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
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

        if ($request->exists('zkbio_area_id')) {
            $rawAreaId = $validated['zkbio_area_id'] ?? null;
            if ($rawAreaId !== null && (int) $rawAreaId === 1) {
                return response()->json([
                    'message' => 'Area 1 (Default) cannot be used for office biometric synchronization.',
                ], 422);
            }
            $requestedAreaId = ($rawAreaId !== null && (int) $rawAreaId > 1) ? (int) $rawAreaId : null;
        } else {
            $departmentChanged = (int) $device->department_id !== $deptId;
            $existingArea = ($device->zkbio_area_id && (int) $device->zkbio_area_id > 1) ? (int) $device->zkbio_area_id : null;
            $requestedAreaId = $departmentChanged ? null : $existingArea;
        }

        $zkAreaId = $requestedAreaId;
        $areaCreatedMessage = null;

        if ($mode === 'ADMS') {
            $areaRes = $this->provisioningService->resolveOrCreateAreaForDepartment($department, $requestedAreaId);
            if (! $areaRes['success'] && $requestedAreaId !== null) {
                return response()->json([
                    'message' => $areaRes['message'],
                ], 422);
            }

            if ($areaRes['area_id'] !== null && $areaRes['area_id'] > 1) {
                $zkAreaId = $areaRes['area_id'];
                if ($areaRes['created']) {
                    $areaCreatedMessage = $areaRes['message'];
                }
            }
        }

        $isPrimary = $request->boolean('is_primary', true);
        if ($isPrimary) {
            BiometricDevice::query()
                ->where('department_id', $deptId)
                ->where('id', '!=', $device->id)
                ->update(['is_primary' => false]);
        }

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: ($device->model_name ?: 'MB360'),
            'department_id' => $deptId,
            'department_name' => $department->name,
            'communication_mode' => $mode,
            'zkbio_area_id' => $zkAreaId,
            'is_primary' => $isPrimary,
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => $this->provisioningService->normalizeIp($validated['ip_address'] ?? null),
            'port' => $validated['port'] ?? ($device->port ?: 4370),
        ]);

        $message = "Biometric device '{$device->device_name}' updated successfully.";
        $zkbioSynced = false;

        if ($mode === 'ADMS' && $zkAreaId !== null && $zkAreaId > 1) {
            $syncRes = $this->provisioningService->syncTerminalToZkBio($device, $zkAreaId);
            $zkbioSynced = $syncRes['success'];
            if ($zkbioSynced) {
                $message .= $areaCreatedMessage
                    ? " {$areaCreatedMessage} Synced to ZKBio Time."
                    : ' Synced to ZKBio Time.';
            }
        }

        return response()->json([
            'message' => $message,
            'device' => $device->fresh(),
            'zkbio_synced' => $zkbioSynced,
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
            $zkDelete = $this->provisioningService->deleteTerminalFromZkBio($serialNumber);

            return response()->json([
                'message' => "Biometric terminal '{$serialNumber}' has been removed from ZKBio Time.",
                'zkbio_deleted' => $zkDelete['deleted'],
            ]);
        }

        $device = BiometricDevice::query()->findOrFail($id);
        $name = $device->device_name;
        $serialNumber = (string) $device->serial_number;
        $device->delete();

        $zkDelete = $this->provisioningService->deleteTerminalFromZkBio($serialNumber);
        $message = $zkDelete['deleted']
            ? "Biometric terminal '{$name}' has been deleted from ALMS and ZKBio Time."
            : "Biometric terminal '{$name}' has been deleted.";

        return response()->json([
            'message' => $message,
            'zkbio_deleted' => $zkDelete['deleted'],
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

        if (! $device->ip_address) {
            return response()->json([
                'success' => false,
                'message' => "Device '{$device->device_name}' does not have an IP address configured.",
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
     * Supports Hybrid Fallback: Pulls direct hardware logs via LAN socket (port 4370)
     * AND force-syncs Cloud/ADMS logs, safeguarding against Cloud outages.
     * Accessible to Department Admin (for their own office) and HR.
     */
    public function syncOfficeDevices(
        Request $request,
        LanBioPunchSyncService $lanSyncService,
        ZkBioPunchSyncService $zkBioSyncService
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
        $officeLabel = $department?->acronym ?: ($department?->name ?: "Office #{$departmentId}");

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

        // 1. Attempt Cloud/ADMS force sync first (in case punches were uploaded to cloud)
        $cloudImported = 0;
        try {
            $cloudImported = $zkBioSyncService->syncPunches(limit: 2000, force: true);
        } catch (Throwable $e) {
            // Cloud/ADMS down or unreachable: log warning and gracefully proceed to direct hardware pull
            Log::warning("syncOfficeDevices: Cloud/ADMS sync failed or offline for {$officeLabel}", [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Filter physical devices with a configured IP address (LAN or ADMS with IP)
        $directPollDevices = $devices->filter(fn ($dev) => ! empty(trim((string) $dev->ip_address)));

        // If no devices have an IP address configured:
        if ($directPollDevices->isEmpty()) {
            if ($cloudImported > 0) {
                return response()->json([
                    'success' => true,
                    'message' => "Synced {$cloudImported} new punch(es) from Cloud/ADMS for {$officeLabel}.",
                    'imported' => $cloudImported,
                    'duplicates' => 0,
                    'total_read' => $cloudImported,
                    'devices_synced' => $devices->count(),
                    'device_names' => $devices->pluck('device_name')->all(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Cloud/ADMS synchronized for {$officeLabel} (records are up-to-date). Note: To enable direct hardware pull if Cloud fails, configure the terminal's IP address.",
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
                'devices_synced' => $devices->count(),
                'device_names' => $devices->pluck('device_name')->all(),
            ]);
        }

        // 3. Connect directly to each physical terminal over TCP 4370 (Hardware pull)
        $totalImported = $cloudImported;
        $totalDuplicates = 0;
        $totalRead = $cloudImported;
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

        // If direct hardware socket pull failed on all devices:
        if (empty($successfulDevices) && ! empty($failedDevices)) {
            if ($cloudImported > 0) {
                return response()->json([
                    'success' => true,
                    'message' => "Synced {$cloudImported} punch(es) from Cloud/ADMS. (Direct terminal pull failed: {$failedDevices[0]})",
                    'imported' => $cloudImported,
                    'duplicates' => 0,
                    'total_read' => $cloudImported,
                    'devices_synced' => 0,
                    'device_names' => [],
                ]);
            }

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
