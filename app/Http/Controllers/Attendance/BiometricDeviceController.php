<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\HRAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricDeviceController extends Controller
{
    /**
     * List all registered ZKTeco MB360 biometric devices.
     */
    public function listDevices(): JsonResponse
    {
        $zkDevices = \Illuminate\Support\Facades\DB::connection('zkbio')
            ->table('iclock_terminal')
            ->orderBy('alias')
            ->get();

        $devices = [];
        foreach ($zkDevices as $zkDevice) {
            $devices[] = [
                'id' => $zkDevice->id,
                'device_name' => $zkDevice->alias ?: $zkDevice->sn,
                'serial_number' => $zkDevice->sn,
                'ip_address' => $zkDevice->ip_address,
                'is_active' => true,
                'last_activity_at' => $zkDevice->last_activity,
                'status' => ((int) $zkDevice->state === 1) ? 'ONLINE' : 'OFFLINE',
                'user_count' => $zkDevice->user_count,
                'fingerprint_count' => $zkDevice->fp_count,
            ];
        }

        return response()->json([
            'devices' => $devices,
            'pending_count' => 0,
        ]);
    }

    /**
     * Authorize / Whitelist a new ZKTeco Biometric Terminal.
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
            'department_id' => ['nullable', 'integer'],
            'department_name' => ['nullable', 'string', 'max:150'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $serialNumber = trim($validated['serial_number']);

        $existing = BiometricDevice::query()->where('serial_number', $serialNumber)->first();
        if ($existing) {
            if ($existing->status === 'PENDING_APPROVAL' || ! $existing->is_active) {
                $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
                $deptName = trim((string) ($validated['department_name'] ?? ''));
                if ($deptId && empty($deptName)) {
                    $dept = Department::query()->find($deptId);
                    $deptName = $dept?->name ?? '';
                } elseif (! $deptId && $deptName !== '') {
                    $dept = Department::query()->where('name', $deptName)->first();
                    $deptId = $dept?->id;
                }

                $existing->update([
                    'device_name' => trim($validated['device_name']),
                    'model_name' => trim($validated['model_name'] ?? '') ?: ($existing->model_name ?: 'MB360'),
                    'department_id' => $deptId ?: $existing->department_id,
                    'department_name' => $deptName ?: ($existing->department_name ?: 'Tagum City Hall'),
                    'comm_key' => trim($validated['comm_key'] ?? '') ?: ($existing->comm_key ?: '0'),
                    'ip_address' => trim($validated['ip_address'] ?? '') ?: $existing->ip_address,
                    'is_active' => true,
                    'status' => 'ONLINE',
                    'last_heartbeat_at' => now(),
                ]);

                return response()->json([
                    'message' => "Detected biometric terminal '{$existing->device_name}' has been successfully authorized and activated.",
                    'device' => $existing,
                ], 200);
            }

            return response()->json([
                'message' => "Device with Serial Number '{$serialNumber}' is already registered and active.",
            ], 422);
        }

        $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
        $deptName = trim((string) ($validated['department_name'] ?? ''));
        if ($deptId && empty($deptName)) {
            $dept = Department::query()->find($deptId);
            $deptName = $dept?->name ?? '';
        } elseif (! $deptId && $deptName !== '') {
            $dept = Department::query()->where('name', $deptName)->first();
            $deptId = $dept?->id;
        }

        $device = BiometricDevice::query()->create([
            'serial_number' => $serialNumber,
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: 'MB360',
            'department_id' => $deptId,
            'department_name' => $deptName ?: 'Tagum City Hall',
            'comm_key' => trim($validated['comm_key'] ?? '') ?: '0',
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
            'communication_mode' => 'ADMS',
            'is_active' => true,
            'status' => 'ONLINE',
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' successfully authorized.",
            'device' => $device,
        ], 201);
    }

    /**
     * Authorize an auto-detected biometric device awaiting HR approval.
     * Accessible only to HR accounts.
     */
    public function authorizeDevice(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can authorize biometric devices.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:150'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'department_name' => ['nullable', 'string', 'max:150'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
        $deptName = trim((string) ($validated['department_name'] ?? ''));
        if ($deptId && empty($deptName)) {
            $dept = Department::query()->find($deptId);
            $deptName = $dept?->name ?? '';
        } elseif (! $deptId && $deptName !== '') {
            $dept = Department::query()->where('name', $deptName)->first();
            $deptId = $dept?->id;
        }

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: ($device->model_name ?: 'MB360'),
            'department_id' => $deptId ?: $device->department_id,
            'department_name' => $deptName ?: ($device->department_name ?: 'Tagum City Hall'),
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => trim($validated['ip_address'] ?? '') ?: $device->ip_address,
            'is_active' => true,
            'status' => 'ONLINE',
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'message' => "Biometric terminal '{$device->device_name}' has been successfully authorized and activated.",
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
            'department_id' => ['nullable', 'integer'],
            'department_name' => ['nullable', 'string', 'max:150'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
        $deptName = trim((string) ($validated['department_name'] ?? ''));
        if ($deptId && empty($deptName)) {
            $dept = Department::query()->find($deptId);
            $deptName = $dept?->name ?? '';
        } elseif (! $deptId && $deptName !== '') {
            $dept = Department::query()->where('name', $deptName)->first();
            $deptId = $dept?->id;
        }

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: ($device->model_name ?: 'MB360'),
            'department_id' => $deptId ?: $device->department_id,
            'department_name' => $deptName ?: ($device->department_name ?: 'Tagum City Hall'),
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' updated successfully.",
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

    //
}
