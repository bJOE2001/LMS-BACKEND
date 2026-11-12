<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage Main Office-to-Division organizational mappings and cascading signatory resolution.
 */
class HROfficeDivisionController extends Controller
{
    /**
     * Display a listing of main offices with their attached divisions, candidate divisions, and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        // Query top-level main offices (offices that are not divisions of any office)
        $mainOfficesQuery = Department::query()
            ->active()
            ->whereNull('main_department_id')
            ->with([
                'departmentHead',
                'divisions' => function ($query): void {
                    $query->active()
                        ->with('departmentHead')
                        ->orderBy('name');
                },
            ])
            ->orderBy('name');

        if ($search !== '') {
            $mainOfficesQuery->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('divisions', function ($subQuery) use ($search): void {
                        $subQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $mainOffices = $mainOfficesQuery->get();

        // Candidates that can be chosen as a Main Office in dropdowns (any active top-level office)
        $mainCandidates = Department::query()
            ->active()
            ->whereNull('main_department_id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $dept): array => [
                'id' => (int) $dept->id,
                'name' => trim((string) $dept->name),
            ])
            ->values();

        // Candidates that can be assigned as a Division (active departments that have NO divisions and are not already divisions)
        $availableDivisions = Department::query()
            ->active()
            ->whereNull('main_department_id')
            ->whereDoesntHave('divisions')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $dept): array => [
                'id' => (int) $dept->id,
                'name' => trim((string) $dept->name),
            ])
            ->values();

        // Serialize main offices
        $serializedOffices = $mainOffices->map(function (Department $office): array {
            $head = $office->departmentHead;

            return [
                'id' => (int) $office->id,
                'name' => trim((string) $office->name),
                'has_department_head' => $head !== null,
                'department_head' => $head ? $this->serializeDepartmentHead($head) : null,
                'divisions_count' => $office->divisions->count(),
                'divisions' => $office->divisions->map(function (Department $division) use ($head): array {
                    $localHead = $division->departmentHead;
                    $effectiveHead = $localHead ?? $head;

                    return [
                        'id' => (int) $division->id,
                        'name' => trim((string) $division->name),
                        'main_department_id' => (int) $division->main_department_id,
                        'has_local_head' => $localHead !== null,
                        'is_inherited_head' => $localHead === null && $effectiveHead !== null,
                        'department_head' => $effectiveHead ? $this->serializeDepartmentHead($effectiveHead) : null,
                    ];
                })->values()->all(),
            ];
        })->values();

        // Metrics
        $totalMainWithDivisions = Department::query()
            ->active()
            ->whereNull('main_department_id')
            ->whereHas('divisions')
            ->count();

        $totalDivisions = Department::query()
            ->active()
            ->whereNotNull('main_department_id')
            ->count();

        $totalStandalone = Department::query()
            ->active()
            ->whereNull('main_department_id')
            ->whereDoesntHave('divisions')
            ->count();

        return response()->json([
            'offices' => $serializedOffices,
            'main_candidates' => $mainCandidates,
            'available_divisions' => $availableDivisions,
            'summary' => [
                'total_main_offices' => $totalMainWithDivisions,
                'total_divisions' => $totalDivisions,
                'total_standalone_offices' => $totalStandalone,
            ],
        ]);
    }

    /**
     * Assign one or more divisions to a main office.
     */
    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'main_department_id' => ['required', 'integer', 'exists:tblDepartments,id'],
            'division_ids' => ['required', 'array', 'min:1'],
            'division_ids.*' => ['required', 'integer', 'exists:tblDepartments,id', 'different:main_department_id'],
        ]);

        $mainId = (int) $validated['main_department_id'];
        $divisionIds = array_map('intval', (array) $validated['division_ids']);

        $mainOffice = Department::query()->active()->find($mainId);
        if (! $mainOffice) {
            return response()->json([
                'message' => 'Main office not found or is inactive.',
            ], 404);
        }

        // Integrity check: A division cannot have its own divisions (1-level hierarchy)
        if ($mainOffice->main_department_id !== null) {
            return response()->json([
                'message' => 'The selected office is already a division and cannot have child divisions.',
            ], 422);
        }

        // Integrity check: Check if any of the target divisions already act as main office to other divisions
        $existingParentsAsChildren = Department::query()
            ->whereIn('id', $divisionIds)
            ->whereHas('divisions')
            ->pluck('name')
            ->all();

        if (! empty($existingParentsAsChildren)) {
            return response()->json([
                'message' => 'Cannot assign the following office(s) as divisions because they currently have child divisions: '.implode(', ', $existingParentsAsChildren),
            ], 422);
        }

        Department::query()
            ->whereIn('id', $divisionIds)
            ->update([
                'main_department_id' => $mainId,
            ]);

        $mainOffice->loadMissing(['departmentHead', 'divisions.departmentHead']);

        return response()->json([
            'message' => 'Divisions assigned to main office successfully.',
            'main_department_id' => $mainId,
            'assigned_count' => count($divisionIds),
        ]);
    }

    /**
     * Detach a division from its main office, making it a standalone office.
     */
    public function detach(Request $request, int $id): JsonResponse
    {
        $division = Department::query()->active()->find($id);
        if (! $division) {
            return response()->json([
                'message' => 'Office or division not found.',
            ], 404);
        }

        if ($division->main_department_id === null) {
            return response()->json([
                'message' => 'Office is not assigned to any main office.',
            ], 422);
        }

        $division->main_department_id = null;
        $division->save();

        return response()->json([
            'message' => 'Division detached successfully.',
            'division_id' => (int) $division->id,
        ]);
    }

    /**
     * Bulk detach multiple divisions from their main offices.
     */
    public function bulkDetach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'division_ids' => ['required', 'array', 'min:1'],
            'division_ids.*' => ['required', 'integer', 'exists:tblDepartments,id'],
        ]);

        $divisionIds = array_map('intval', (array) $validated['division_ids']);

        $updatedCount = Department::query()
            ->whereIn('id', $divisionIds)
            ->update([
                'main_department_id' => null,
            ]);

        return response()->json([
            'message' => 'Divisions detached successfully.',
            'detached_count' => $updatedCount,
        ]);
    }

    /**
     * Serialize department head model.
     */
    private function serializeDepartmentHead(DepartmentHead $head): array
    {
        $fullName = trim((string) ($head->full_name ?? ''));
        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([
                $head->firstname,
                $head->middlename,
                $head->surname,
            ])));
        }

        return [
            'id' => (int) $head->id,
            'department_id' => (int) $head->department_id,
            'control_no' => trim((string) ($head->control_no ?? '')),
            'full_name' => $fullName,
            'designation' => trim((string) ($head->designation ?? '')),
            'position' => trim((string) ($head->designation ?? '')),
            'office' => trim((string) ($head->office ?? '')),
        ];
    }
}
