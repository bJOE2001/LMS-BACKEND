<?php

namespace App\Http\Controllers;

use App\Models\EmployeeWorkScheduleOverride;
use App\Models\HRAccount;
use App\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class HRWorkScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this resource.'], 403);
        }

        $service = app(WorkScheduleService::class);

        return response()->json([
            'default_schedule' => $service->getResolvedDefaultSchedule(),
            'employee_overrides' => $service->listEmployeeOverrides(),
        ]);
    }

    public function updateDefault(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can update work schedules.'], 403);
        }

        $validated = $this->validateSchedulePayload($request);

        try {
            $setting = app(WorkScheduleService::class)->saveDefaultSetting($validated, $hr);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'schedule' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Default work schedule updated successfully.',
            'default_schedule' => app(WorkScheduleService::class)->getResolvedDefaultSchedule(),
            'setting' => $setting,
        ]);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can update work schedules.'], 403);
        }

        $validated = $this->validateSchedulePayload($request, true);
        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));

        if (EmployeeWorkScheduleOverride::query()->where('employee_control_no', $employeeControlNo)->exists()) {
            return response()->json([
                'message' => 'A work schedule override already exists for this employee.',
                'errors' => [
                    'employee_control_no' => ['A work schedule override already exists for this employee.'],
                ],
            ], 422);
        }

        try {
            $override = app(WorkScheduleService::class)->createEmployeeOverride($employeeControlNo, $validated, $hr);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'employee_control_no' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Employee work schedule override created successfully.',
            'employee_override' => app(WorkScheduleService::class)->formatOverride($override),
        ], 201);
    }

    public function updateOverride(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can update work schedules.'], 403);
        }

        $override = EmployeeWorkScheduleOverride::query()->find($id);
        if (!$override) {
            return response()->json(['message' => 'Employee work schedule override not found.'], 404);
        }

        $validated = $this->validateSchedulePayload($request);

        try {
            $override = app(WorkScheduleService::class)->updateEmployeeOverride($override, $validated, $hr);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'schedule' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Employee work schedule override updated successfully.',
            'employee_override' => app(WorkScheduleService::class)->formatOverride($override),
        ]);
    }

    public function destroyOverride(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can update work schedules.'], 403);
        }

        $override = EmployeeWorkScheduleOverride::query()->find($id);
        if (!$override) {
            return response()->json(['message' => 'Employee work schedule override not found.'], 404);
        }

        $override->delete();

        return response()->json([
            'message' => 'Employee work schedule override removed successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSchedulePayload(Request $request, bool $requireEmployee = false): array
    {
        $rules = [
            'work_start_time' => ['required', 'date_format:H:i'],
            'work_end_time' => ['required', 'date_format:H:i'],
            'break_start_time' => ['nullable', 'date_format:H:i'],
            'break_end_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($requireEmployee) {
            $rules['employee_control_no'] = ['required', 'string', 'regex:/^\d+$/'];
        }

        return $request->validate($rules);
    }
}
