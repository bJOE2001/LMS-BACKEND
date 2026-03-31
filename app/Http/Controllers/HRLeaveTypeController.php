<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Services\RecycleBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HRLeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in([
                LeaveType::CATEGORY_ACCRUED,
                LeaveType::CATEGORY_RESETTABLE,
                LeaveType::CATEGORY_EVENT,
            ])],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $category = $validated['category'] ?? null;

        $leaveTypes = LeaveType::query()
            ->withoutLegacySpecialPrivilegeAliases()
            ->withCount(['leaveApplications', 'leaveBalances'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($category, function ($query) use ($category): void {
                $query->where('category', $category);
            })
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => $this->formatLeaveType($type))
            ->values();

        return response()->json([
            'leave_types' => $leaveTypes,
            'employment_status_options' => LeaveType::employmentStatusOptions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $payload = $this->normalizePayload($validated);

        $leaveType = LeaveType::create($payload);
        $leaveType->loadCount(['leaveApplications', 'leaveBalances']);

        return response()->json([
            'message' => 'Leave type created successfully.',
            'leave_type' => $this->formatLeaveType($leaveType),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $leaveType = LeaveType::find($id);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $validated = $this->validatePayload($request, $leaveType->id);
        $payload = $this->normalizePayload($validated);

        $leaveType->update($payload);
        $leaveType->refresh()->loadCount(['leaveApplications', 'leaveBalances']);

        return response()->json([
            'message' => 'Leave type updated successfully.',
            'leave_type' => $this->formatLeaveType($leaveType),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $leaveType = LeaveType::query()
            ->withCount(['leaveApplications', 'leaveBalances'])
            ->find($id);

        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $usageTotal = (int) $leaveType->leave_applications_count
            + (int) $leaveType->leave_balances_count;

        if ($usageTotal > 0) {
            return response()->json([
                'message' => 'This leave type is already in use and cannot be deleted.',
                'usage' => [
                    'applications' => (int) $leaveType->leave_applications_count,
                    'employee_balances' => (int) $leaveType->leave_balances_count,
                    'admin_balances' => 0,
                    'total' => $usageTotal,
                ],
            ], 422);
        }

        DB::transaction(function () use ($leaveType, $request, $usageTotal): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $leaveType,
                $request->user(),
                [
                    'record_title' => $leaveType->name,
                    'delete_source' => 'hr.leave-types',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => array_merge($leaveType->toArray(), [
                        'usage' => [
                            'applications' => (int) $leaveType->leave_applications_count,
                            'employee_balances' => (int) $leaveType->leave_balances_count,
                            'total' => $usageTotal,
                        ],
                    ]),
                ]
            );

            $leaveType->delete();
        });

        return response()->json([
            'message' => 'Leave type deleted successfully.',
        ]);
    }

    private function validatePayload(Request $request, ?int $leaveTypeId = null): array
    {
        $currentLeaveType = $leaveTypeId !== null ? LeaveType::find($leaveTypeId) : null;
        $nameRule = Rule::unique('tblLeaveTypes', 'name');
        if ($leaveTypeId !== null) {
            $nameRule = $nameRule->ignore($leaveTypeId);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $nameRule],
            'category' => ['required', 'string', Rule::in([
                LeaveType::CATEGORY_ACCRUED,
                LeaveType::CATEGORY_RESETTABLE,
                LeaveType::CATEGORY_EVENT,
            ])],
            'accrual_rate' => ['nullable', 'numeric', 'min:0.01'],
            'accrual_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'max_days' => ['nullable', 'integer', 'min:0'],
            'is_credit_based' => ['nullable', 'boolean'],
            'resets_yearly' => ['nullable', 'boolean'],
            'requires_documents' => ['nullable', 'boolean'],
            'allowed_status' => ['nullable', 'array'],
            'allowed_status.*' => ['string', Rule::in(array_keys(LeaveType::EMPLOYMENT_STATUS_LABELS))],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $canonicalName = LeaveType::canonicalizeLeaveTypeName($validated['name'] ?? null);
        if ($canonicalName !== null) {
            $canonicalConflictQuery = LeaveType::query();

            if (LeaveType::isSpecialPrivilegeAliasName($canonicalName)) {
                $isEditingCanonicalSpecialPrivilege = $currentLeaveType instanceof LeaveType
                    && LeaveType::normalizeLeaveTypeName($currentLeaveType->name) === LeaveType::normalizeLeaveTypeName(LeaveType::SPECIAL_PRIVILEGE_LEAVE_NAME);

                if ($isEditingCanonicalSpecialPrivilege) {
                    $canonicalConflictQuery->whereRaw(
                        'UPPER(LTRIM(RTRIM(name))) = ?',
                        [LeaveType::normalizeLeaveTypeName(LeaveType::SPECIAL_PRIVILEGE_LEAVE_NAME)]
                    );
                } else {
                    $canonicalConflictQuery->where(function ($query): void {
                        foreach (LeaveType::specialPrivilegeAliasNames() as $index => $aliasName) {
                            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                            $query->{$method}(
                                'UPPER(LTRIM(RTRIM(name))) = ?',
                                [LeaveType::normalizeLeaveTypeName($aliasName)]
                            );
                        }
                    });
                }
            } else {
                $canonicalConflictQuery->whereRaw(
                    'UPPER(LTRIM(RTRIM(name))) = ?',
                    [LeaveType::normalizeLeaveTypeName($canonicalName)]
                );
            }

            if ($leaveTypeId !== null) {
                $canonicalConflictQuery->whereKeyNot($leaveTypeId);
            }

            if ($canonicalConflictQuery->exists()) {
                throw ValidationException::withMessages([
                    'name' => ['A leave type with this name already exists.'],
                ]);
            }

            $validated['name'] = $canonicalName;
        }

        if (($validated['category'] ?? null) === LeaveType::CATEGORY_ACCRUED) {
            if (!array_key_exists('accrual_rate', $validated) || $validated['accrual_rate'] === null) {
                throw ValidationException::withMessages([
                    'accrual_rate' => ['Accrual rate is required for ACCRUED leave types.'],
                ]);
            }

            if (!array_key_exists('accrual_day_of_month', $validated) || $validated['accrual_day_of_month'] === null) {
                throw ValidationException::withMessages([
                    'accrual_day_of_month' => ['Accrual day is required for ACCRUED leave types.'],
                ]);
            }
        }

        return $validated;
    }

    private function normalizePayload(array $validated): array
    {
        $category = $validated['category'];
        $isAccrued = $category === LeaveType::CATEGORY_ACCRUED;

        $isCreditBased = array_key_exists('is_credit_based', $validated)
            ? (bool) $validated['is_credit_based']
            : $category !== LeaveType::CATEGORY_EVENT;

        $resetsYearly = array_key_exists('resets_yearly', $validated)
            ? (bool) $validated['resets_yearly']
            : $category === LeaveType::CATEGORY_RESETTABLE;

        return [
            'name' => trim((string) $validated['name']),
            'category' => $category,
            'accrual_rate' => $isAccrued ? (float) $validated['accrual_rate'] : null,
            'accrual_day_of_month' => $isAccrued ? (int) $validated['accrual_day_of_month'] : null,
            'max_days' => array_key_exists('max_days', $validated) && $validated['max_days'] !== null
                ? (int) $validated['max_days']
                : null,
            'is_credit_based' => $isCreditBased,
            'resets_yearly' => $resetsYearly,
            'requires_documents' => (bool) ($validated['requires_documents'] ?? false),
            'allowed_status' => LeaveType::normalizeAllowedStatusesArray($validated['allowed_status'] ?? null),
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
        ];
    }

    private function formatLeaveType(LeaveType $type): array
    {
        $applications = (int) ($type->leave_applications_count ?? 0);
        $employeeBalances = (int) ($type->leave_balances_count ?? 0);
        $adminBalances = 0;

        return [
            'id' => $type->id,
            'name' => $type->name,
            'display_name' => $this->formatDisplayLeaveTypeName($type->name),
            'category' => $type->category,
            'accrual_rate' => $type->accrual_rate !== null ? (float) $type->accrual_rate : null,
            'accrual_day_of_month' => $type->accrual_day_of_month,
            'max_days' => $type->max_days,
            'is_credit_based' => (bool) $type->is_credit_based,
            'resets_yearly' => (bool) $type->resets_yearly,
            'requires_documents' => (bool) $type->requires_documents,
            'allowed_status' => $type->normalizedAllowedStatuses(),
            'allowed_status_labels' => $type->allowedStatusLabels(),
            'description' => $type->description,
            'usage' => [
                'applications' => $applications,
                'employee_balances' => $employeeBalances,
                'admin_balances' => $adminBalances,
                'total' => $applications + $employeeBalances + $adminBalances,
            ],
            'created_at' => $type->created_at?->toIso8601String(),
            'updated_at' => $type->updated_at?->toIso8601String(),
        ];
    }

    private function formatDisplayLeaveTypeName(?string $name): string
    {
        $canonicalName = LeaveType::canonicalizeLeaveTypeName($name);
        if ($canonicalName === LeaveType::SPECIAL_PRIVILEGE_LEAVE_NAME) {
            return 'Special Privilege Leave(MC06)';
        }

        return trim((string) ($canonicalName ?? $name ?? ''));
    }
}
