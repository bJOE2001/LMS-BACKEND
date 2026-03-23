<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\RecycleBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HRDepartmentLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $searchTerm = trim((string) ($validated['search'] ?? ''));

        $departments = Department::query()
            ->with(['admin:id,department_id', 'departmentHead:id,department_id'])
            ->when($searchTerm !== '', function ($query) use ($searchTerm): void {
                $query->where('name', 'like', "%{$searchTerm}%");
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => $this->serializeDepartment($department))
            ->values();

        return response()->json([
            'departments' => $departments,
            'summary' => [
                'total_departments' => $departments->count(),
                'with_assigned_admin' => $departments->where('has_admin', true)->count(),
                'with_department_head' => $departments->where('has_department_head', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $department = Department::create([
            'name' => trim((string) $validated['name']),
        ]);

        $department->loadMissing(['admin:id,department_id', 'departmentHead:id,department_id']);

        return response()->json([
            'message' => 'Department created successfully.',
            'department' => $this->serializeDepartment($department),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::query()->find($id);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $validated = $this->validatePayload($request, $department->id);
        $department->name = trim((string) $validated['name']);
        $department->save();
        $department->refresh()->loadMissing(['admin:id,department_id', 'departmentHead:id,department_id']);

        return response()->json([
            'message' => 'Department updated successfully.',
            'department' => $this->serializeDepartment($department),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $department = Department::query()
            ->with(['admin:id,department_id', 'departmentHead:id,department_id'])
            ->find($id);

        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        if ($department->admin !== null) {
            return response()->json([
                'message' => 'Cannot delete this department while a department admin is assigned. Remove the assignment first.',
            ], 422);
        }

        if ($department->departmentHead !== null) {
            return response()->json([
                'message' => 'Cannot delete this department while a department head is assigned. Remove the department head first.',
            ], 422);
        }

        DB::transaction(function () use ($department, $request): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $department,
                $request->user(),
                [
                    'record_title' => $department->name,
                    'delete_source' => 'hr.departments-library',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => $this->serializeDepartment($department),
                ]
            );

            $department->delete();
        });

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }

    private function validatePayload(Request $request, ?int $departmentId = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $nameUniqueRule = Rule::unique('tblDepartments', 'name');
        if ($departmentId !== null) {
            $nameUniqueRule = $nameUniqueRule->ignore($departmentId);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $nameUniqueRule],
        ]);
    }

    private function serializeDepartment(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => trim((string) $department->name),
            'has_admin' => $department->admin !== null,
            'has_department_head' => $department->departmentHead !== null,
            'created_at' => $department->created_at?->toIso8601String(),
            'updated_at' => $department->updated_at?->toIso8601String(),
        ];
    }
}
