<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\HRAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class BiometricDeviceController extends Controller
{
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
                $zkAreasById[(int) $za->id] = (string) $za->area_name;
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
            $lastActivity = $dev->last_heartbeat_at;

            if ($zkLive) {
                $isOnline = ((int) $zkLive->state === 1);
                $lastActivity = $zkLive->last_activity ?? $dev->last_heartbeat_at;
            } elseif ($dev->last_heartbeat_at) {
                $isOnline = $dev->is_online;
            }

            $devices[] = [
                'id' => $dev->id,
                'device_name' => $dev->device_name,
                'serial_number' => $dev->serial_number,
                'ip_address' => $dev->ip_address ?: ($zkLive?->ip_address ?? null),
                'model' => $dev->model_name ?: 'MB360',
                'model_name' => $dev->model_name ?: 'MB360',
                'comm_key' => $dev->comm_key,
                'department_id' => $dev->department_id,
                'department_name' => $dev->department?->name ?? $dev->department_name,
                'office_acronym' => $dev->department?->acronym,
                'location' => $dev->department?->acronym ? "{$dev->department->acronym} - {$dev->department->name}" : ($dev->department_name ?: 'Tagum City Hall'),
                'zkbio_area_id' => $areaId,
                'zkbio_area_name' => $areaName ? "Area {$areaId} ({$areaName})" : ($areaId ? "Area {$areaId}" : null),
                'is_primary' => (bool) $dev->is_primary,
                'is_active' => (bool) $dev->is_active,
                'is_online' => $isOnline,
                'status' => $dev->is_active ? ($isOnline ? 'ONLINE' : 'OFFLINE') : 'BLOCKED',
                'last_activity_at' => $lastActivity,
                'last_heartbeat_at' => $lastActivity,
                'user_count' => $zkLive?->user_count ?? $dev->device_user_count,
                'fingerprint_count' => $zkLive?->fp_count ?? $dev->device_finger_count,
            ];
        }

        // 3. Detect unconfigured terminals in ZKBio Time awaiting office mapping
        $pendingCount = 0;
        foreach ($zkTerminalsBySn as $sn => $zt) {
            if (! in_array($sn, $configuredSns, true)) {
                $pendingCount++;
                $areaId = $zt->area_id ? (int) $zt->area_id : null;
                $areaName = $areaId ? ($zkAreasById[$areaId] ?? "Area {$areaId}") : null;

                $devices[] = [
                    'id' => null,
                    'device_name' => $zt->alias ?: $sn,
                    'serial_number' => $sn,
                    'ip_address' => $zt->ip_address,
                    'model' => 'MB360',
                    'model_name' => 'MB360',
                    'comm_key' => '0',
                    'department_id' => null,
                    'department_name' => null,
                    'office_acronym' => null,
                    'location' => 'Unassigned',
                    'zkbio_area_id' => $areaId,
                    'zkbio_area_name' => $areaName ? "Area {$areaId} ({$areaName})" : ($areaId ? "Area {$areaId}" : null),
                    'is_primary' => false,
                    'is_active' => false,
                    'is_online' => ((int) $zt->state === 1),
                    'status' => 'PENDING_APPROVAL',
                    'last_activity_at' => $zt->last_activity,
                    'last_heartbeat_at' => $zt->last_activity,
                    'user_count' => $zt->user_count,
                    'fingerprint_count' => $zt->fp_count,
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

                return [
                    'id' => (int) $area->id,
                    'area_code' => (string) $area->area_code,
                    'area_name' => (string) $area->area_name,
                    'is_default' => (bool) ($area->is_default ?? false),
                    'is_prohibited' => $isProhibited,
                    'label' => $isProhibited
                        ? "Area {$area->id} ({$area->area_name}) - Prohibited (Default non-sync area)"
                        : "Area {$area->id} ({$area->area_name})",
                ];
            });

            return response()->json([
                'areas' => $result,
            ]);
        } catch (Throwable) {
            return response()->json([
                'areas' => [
                    ['id' => 2, 'area_code' => '2', 'area_name' => 'CICTMO', 'is_prohibited' => false, 'label' => 'Area 2 (CICTMO)'],
                    ['id' => 3, 'area_code' => '3', 'area_name' => 'CHRMO', 'is_prohibited' => false, 'label' => 'Area 3 (CHRMO)'],
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
            'zkbio_area_id' => ['required', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $zkAreaId = (int) $validated['zkbio_area_id'];
        if ($zkAreaId === 1) {
            return response()->json([
                'message' => 'Area 1 is the default non-synchronizing area in ZKBio Time and cannot be assigned to devices. Please select an active area such as Area 2 or Area 3.',
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

        $existing = BiometricDevice::query()->where('serial_number', $serialNumber)->first();
        if ($existing) {
            $existing->update([
                'device_name' => trim($validated['device_name']),
                'model_name' => trim($validated['model_name'] ?? '') ?: ($existing->model_name ?: 'MB360'),
                'department_id' => $deptId,
                'department_name' => $department->name,
                'zkbio_area_id' => $zkAreaId,
                'is_primary' => $isPrimary,
                'comm_key' => trim($validated['comm_key'] ?? '') ?: ($existing->comm_key ?: '0'),
                'ip_address' => trim($validated['ip_address'] ?? '') ?: $existing->ip_address,
                'is_active' => true,
                'status' => 'ONLINE',
                'last_heartbeat_at' => now(),
            ]);

            return response()->json([
                'message' => "Biometric terminal '{$existing->device_name}' successfully authorized and activated for {$department->name}.",
                'device' => $existing,
            ], 200);
        }

        $device = BiometricDevice::query()->create([
            'serial_number' => $serialNumber,
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: 'MB360',
            'department_id' => $deptId,
            'department_name' => $department->name,
            'zkbio_area_id' => $zkAreaId,
            'is_primary' => $isPrimary,
            'comm_key' => trim($validated['comm_key'] ?? '') ?: '0',
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
            'communication_mode' => 'ADMS',
            'is_active' => true,
            'status' => 'ONLINE',
            'last_heartbeat_at' => now(),
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
            'zkbio_area_id' => ['required', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $zkAreaId = (int) $validated['zkbio_area_id'];
        if ($zkAreaId === 1) {
            return response()->json([
                'message' => 'Area 1 is the default non-synchronizing area in ZKBio Time and cannot be assigned to devices. Please select an active area such as Area 2 or Area 3.',
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

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: ($device->model_name ?: 'MB360'),
            'department_id' => $deptId,
            'department_name' => $department->name,
            'zkbio_area_id' => $zkAreaId,
            'is_primary' => $isPrimary,
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' updated successfully.",
            'device' => $device,
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

        $device = BiometricDevice::query()->findOrFail($id);
        $name = $device->device_name;
        $device->delete();

        return response()->json([
            'message' => "Biometric terminal '{$name}' has been deleted.",
        ]);
    }
}
