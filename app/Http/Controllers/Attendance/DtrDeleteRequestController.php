<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\DailyTimeRecord;
use App\Models\Attendance\DtrDeleteRequest;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Services\Attendance\DtrCalculationService;
use App\Services\HrAccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller for managing Department Admin DTR slot deletion requests and HR approval.
 */
class DtrDeleteRequestController extends Controller
{
    private const VALID_SLOTS = [
        'am_arrival',
        'am_departure',
        'pm_arrival',
        'pm_departure',
    ];

    public function __construct(
        private readonly DtrCalculationService $dtrService
    ) {}

    /**
     * List DTR delete requests for Department Admin (own office) or HR (all offices).
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $isAdmin = $account instanceof DepartmentAdmin;
        $isHr = $account instanceof HRAccount;

        if (! $isAdmin && ! $isHr) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        if ($isHr && ! app(HrAccessControlService::class)->hasModuleAccess($account, HrAccessControlService::MODULE_EMPLOYEE_MANAGEMENT)) {
            return response()->json(['message' => 'You do not have access to this module.'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['all', 'pending', 'approved', 'rejected', 'cancelled'])],
            'department_id' => ['nullable', 'integer'],
            'employee_control_no' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $baseQuery = DtrDeleteRequest::query();

        if ($isAdmin) {
            $deptId = (int) ($account->department_id ?? 0);
            $baseQuery->where(function ($q) use ($account, $deptId): void {
                if ($deptId > 0) {
                    $q->where('department_id', $deptId)
                        ->orWhere('requested_by_admin_id', $account->id);
                } else {
                    $q->where('requested_by_admin_id', $account->id);
                }
            });
        } elseif (! empty($validated['department_id'])) {
            $baseQuery->where('department_id', (int) $validated['department_id']);
        }

        $pendingCount = (clone $baseQuery)
            ->where('status', DtrDeleteRequest::STATUS_PENDING)
            ->count();

        $query = clone $baseQuery;

        $statusFilter = strtolower(trim((string) ($validated['status'] ?? '')));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if (! empty($validated['employee_control_no'])) {
            $query->where('employee_control_no', trim((string) $validated['employee_control_no']));
        }

        if (! empty($validated['search'])) {
            $term = '%'.trim((string) $validated['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('employee_name', 'like', $term)
                    ->orWhere('employee_control_no', 'like', $term)
                    ->orWhere('office_name', 'like', $term)
                    ->orWhere('requested_by_name', 'like', $term)
                    ->orWhere('reason', 'like', $term);
            });
        }

        $requests = $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $requests->transform(function (DtrDeleteRequest $item): DtrDeleteRequest {
            $acronym = trim((string) (HrisEmployee::officeAcronymByName($item->office_name) ?? ''));
            if ($acronym === '' && $item->department_id) {
                $deptName = Department::query()->where('id', $item->department_id)->value('name');
                if ($deptName) {
                    $acronym = trim((string) (HrisEmployee::officeAcronymByName($deptName) ?? ''));
                }
            }
            $item->setAttribute('office_acronym', $acronym !== '' ? $acronym : null);

            return $item;
        });

        return response()->json([
            'requests' => $requests,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * Submit a new DTR slot deletion request (Department Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! ($account instanceof DepartmentAdmin)) {
            return response()->json(['message' => 'Only Department Admins can request DTR deletion.'], 403);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date_format:Y-m-d'],
            'period' => ['required', 'string', Rule::in(['single', 'am', 'pm', 'whole'])],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*' => ['required', 'string', Rule::in(self::VALID_SLOTS)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $controlNo = trim((string) $validated['employee_control_no']);
        $emp = HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        if (! $emp) {
            return response()->json(['message' => 'Employee not found in active HRIS records.'], 404);
        }

        $canonicalControlNo = (string) ($emp->control_no ?? $controlNo);
        $dateStr = $validated['date'];
        $requestedSlots = array_values(array_unique($validated['slots']));

        $dtr = $this->dtrService->calculateForEmployeeDate($canonicalControlNo, $dateStr, null, $emp);
        $adjustedMap = $this->extractAdjustedSlotMap($dtr);

        $snapshot = [];
        $deletableSlots = [];

        foreach ($requestedSlots as $slotKey) {
            $rawTime = trim((string) ($dtr->{$slotKey} ?? ''));
            $adjustedVal = trim((string) ($adjustedMap[$slotKey] ?? ''));
            $hasOverrideEntry = array_key_exists($slotKey, $adjustedMap) || $this->isIndexedAdjustedSlot($dtr, $slotKey);

            if ($rawTime === '' && ($adjustedVal === '' || strtoupper($adjustedVal) === 'TIME')) {
                continue;
            }

            $source = ($hasOverrideEntry || ($adjustedVal !== '')) ? 'override' : 'biometric';
            $remark = ($adjustedVal !== '' && strtoupper($adjustedVal) !== 'TIME') ? $adjustedVal : null;

            $deletableSlots[] = $slotKey;
            $snapshot[$slotKey] = [
                'time' => $rawTime !== '' ? $rawTime : null,
                'remark' => $remark,
                'source' => $source,
            ];
        }

        if ($deletableSlots === []) {
            return response()->json([
                'message' => 'Selected slot(s) have no punch time or remark to delete.',
            ], 422);
        }

        $existingPending = DtrDeleteRequest::query()
            ->where('employee_control_no', $canonicalControlNo)
            ->where('record_date', $dateStr)
            ->where('status', DtrDeleteRequest::STATUS_PENDING)
            ->get();

        foreach ($existingPending as $pendingReq) {
            $existingSlots = is_array($pendingReq->slots) ? $pendingReq->slots : [];
            if (array_intersect($deletableSlots, $existingSlots) !== []) {
                return response()->json([
                    'message' => 'A pending delete request already exists for this date and slot.',
                ], 422);
            }
        }

        $departmentId = $account->department_id
            ?? $dtr->department_id
            ?? EmployeeDepartmentAssignment::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->value('department_id');

        $officeName = $emp->office ?? null;
        if (! $officeName && $departmentId) {
            $officeName = Department::query()->where('id', $departmentId)->value('name');
        }

        $employeeName = trim(
            ($emp->firstname ?? '').' '
            .($emp->middlename ? $emp->middlename[0].'. ' : '')
            .($emp->surname ?? '')
        );
        if ($employeeName === '' && ! empty($emp->full_name)) {
            $employeeName = trim((string) $emp->full_name);
        }

        $deleteRequest = DtrDeleteRequest::query()->create([
            'daily_time_record_id' => $dtr->id,
            'employee_control_no' => $canonicalControlNo,
            'employee_name' => $employeeName !== '' ? $employeeName : $canonicalControlNo,
            'department_id' => $departmentId ? (int) $departmentId : null,
            'office_name' => $officeName,
            'record_date' => $dateStr,
            'period' => $validated['period'],
            'slots' => $deletableSlots,
            'previous_snapshot' => $snapshot,
            'reason' => trim((string) $validated['reason']),
            'status' => DtrDeleteRequest::STATUS_PENDING,
            'requested_by_admin_id' => $account->id,
            'requested_by_name' => trim((string) ($account->full_name ?: $account->name ?: $account->username ?: 'Department Admin')),
        ]);

        return response()->json([
            'message' => 'Delete request submitted for HR approval.',
            'delete_request' => $deleteRequest,
        ], 201);
    }

    /**
     * Cancel an existing pending DTR delete request (requesting Department Admin or same office admin).
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $request->user();
        if (! ($account instanceof DepartmentAdmin)) {
            return response()->json(['message' => 'Only Department Admins can cancel delete requests.'], 403);
        }

        $deleteRequest = DtrDeleteRequest::query()->findOrFail($id);

        $sameAdmin = (int) ($deleteRequest->requested_by_admin_id ?? 0) === (int) $account->id;
        $sameDept = $account->department_id
            && $deleteRequest->department_id
            && (int) $deleteRequest->department_id === (int) $account->department_id;

        if (! $sameAdmin && ! $sameDept) {
            return response()->json(['message' => 'You are not authorized to cancel this request.'], 403);
        }

        if ($deleteRequest->status !== DtrDeleteRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending delete requests can be cancelled.'], 422);
        }

        $deleteRequest->status = DtrDeleteRequest::STATUS_CANCELLED;
        $deleteRequest->save();

        return response()->json([
            'message' => 'Delete request cancelled.',
            'delete_request' => $deleteRequest,
        ]);
    }

    /**
     * Approve a pending DTR delete request and clear the requested slot(s) (HR only).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $account = $request->user();
        if (! ($account instanceof HRAccount)) {
            return response()->json(['message' => 'Only HR accounts can approve DTR delete requests.'], 403);
        }

        if (! app(HrAccessControlService::class)->hasModuleAccess($account, HrAccessControlService::MODULE_EMPLOYEE_MANAGEMENT)) {
            return response()->json(['message' => 'You do not have access to this module.'], 403);
        }

        $validated = $request->validate([
            'hr_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $deleteRequest = DtrDeleteRequest::query()->findOrFail($id);
        if ($deleteRequest->status !== DtrDeleteRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending delete requests can be approved.'], 422);
        }

        $dateStr = $deleteRequest->record_date->format('Y-m-d');
        $dtr = $this->dtrService->calculateForEmployeeDate($deleteRequest->employee_control_no, $dateStr);

        $slotsToClear = is_array($deleteRequest->slots) ? $deleteRequest->slots : [];
        $currentAdjusted = is_array($dtr->adjusted_slots) ? $dtr->adjusted_slots : [];
        $isAssoc = $currentAdjusted !== [] && (array_keys($currentAdjusted) !== range(0, count($currentAdjusted) - 1));

        foreach ($slotsToClear as $slotKey) {
            if (in_array($slotKey, self::VALID_SLOTS, true)) {
                $dtr->{$slotKey} = null;
                if ($isAssoc) {
                    unset($currentAdjusted[$slotKey]);
                } else {
                    $currentAdjusted = array_values(array_filter(
                        $currentAdjusted,
                        static fn ($entry): bool => $entry !== $slotKey
                    ));
                }
            }
        }

        $dtr->adjusted_slots = $currentAdjusted !== [] ? $currentAdjusted : null;
        $dtr->is_adjusted = true;
        $dtr->adjustment_reason = 'MANUAL_OVERRIDE';

        $schedule = $this->dtrService->resolveSchedule($dtr->employee_control_no);
        $rendered = $this->dtrService->calculateRenderedHours(
            $dtr->am_arrival,
            $dtr->am_departure,
            $dtr->pm_arrival,
            $dtr->pm_departure,
            null,
            $schedule
        );

        $lateAndUndertime = $this->dtrService->calculateLateAndUndertime(
            $dtr->am_arrival,
            $dtr->am_departure,
            $dtr->pm_arrival,
            $dtr->pm_departure,
            $schedule
        );

        $dtr->late_minutes = $lateAndUndertime['late_minutes'];
        $dtr->undertime_minutes = $lateAndUndertime['undertime_minutes'];

        $slotMap = $isAssoc ? $currentAdjusted : [];
        $amIsSpecial = $this->hasSpecialSlotRemark($slotMap, ['am_arrival', 'am_departure']);
        $pmIsSpecial = $this->hasSpecialSlotRemark($slotMap, ['pm_arrival', 'pm_departure']);

        if ($amIsSpecial) {
            $dtr->late_minutes = 0;
            $rendered += 4.00;
        }
        if ($pmIsSpecial) {
            $dtr->undertime_minutes = 0;
            $rendered += 4.00;
        }

        $hasAnyPunchOrSpecial = $dtr->am_arrival !== null
            || $dtr->am_departure !== null
            || $dtr->pm_arrival !== null
            || $dtr->pm_departure !== null
            || $amIsSpecial
            || $pmIsSpecial;

        if ($hasAnyPunchOrSpecial) {
            $dtr->status = DailyTimeRecord::STATUS_PRESENT;
            $dtr->rendered_hours = min(8.00, (float) ($rendered > 0 ? $rendered : 8.00));
            $dtr->adjustment_remarks = 'DTR deletion approved by HR';
        } else {
            $dtr->status = DailyTimeRecord::STATUS_ABSENT;
            $dtr->rendered_hours = 0.00;
            $dtr->adjustment_remarks = null;
        }

        $dtr->save();

        $hrRemarks = trim((string) ($validated['hr_remarks'] ?? ''));
        $deleteRequest->status = DtrDeleteRequest::STATUS_APPROVED;
        $deleteRequest->reviewed_by_hr_id = $account->id;
        $deleteRequest->reviewed_by_name = trim((string) ($account->full_name ?: $account->name ?: $account->username ?: 'HR Officer'));
        $deleteRequest->reviewed_at = now();
        $deleteRequest->hr_remarks = $hrRemarks !== '' ? $hrRemarks : null;
        $deleteRequest->save();

        return response()->json([
            'message' => 'DTR delete request approved and selected slot(s) cleared.',
            'delete_request' => $deleteRequest,
            'dtr' => $dtr,
        ]);
    }

    /**
     * Reject a pending DTR delete request (HR only).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $account = $request->user();
        if (! ($account instanceof HRAccount)) {
            return response()->json(['message' => 'Only HR accounts can reject DTR delete requests.'], 403);
        }

        if (! app(HrAccessControlService::class)->hasModuleAccess($account, HrAccessControlService::MODULE_EMPLOYEE_MANAGEMENT)) {
            return response()->json(['message' => 'You do not have access to this module.'], 403);
        }

        $validated = $request->validate([
            'hr_remarks' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $deleteRequest = DtrDeleteRequest::query()->findOrFail($id);
        if ($deleteRequest->status !== DtrDeleteRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending delete requests can be rejected.'], 422);
        }

        $deleteRequest->status = DtrDeleteRequest::STATUS_REJECTED;
        $deleteRequest->reviewed_by_hr_id = $account->id;
        $deleteRequest->reviewed_by_name = trim((string) ($account->full_name ?: $account->name ?: $account->username ?: 'HR Officer'));
        $deleteRequest->reviewed_at = now();
        $deleteRequest->hr_remarks = trim((string) $validated['hr_remarks']);
        $deleteRequest->save();

        return response()->json([
            'message' => 'DTR delete request rejected.',
            'delete_request' => $deleteRequest,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function extractAdjustedSlotMap(DailyTimeRecord $dtr): array
    {
        $adjusted = $dtr->adjusted_slots;
        if (! is_array($adjusted) || $adjusted === []) {
            return [];
        }

        $isAssoc = array_keys($adjusted) !== range(0, count($adjusted) - 1);
        if (! $isAssoc) {
            return [];
        }

        $map = [];
        foreach ($adjusted as $k => $v) {
            $map[(string) $k] = (string) ($v ?? '');
        }

        return $map;
    }

    private function isIndexedAdjustedSlot(DailyTimeRecord $dtr, string $slotKey): bool
    {
        $adjusted = $dtr->adjusted_slots;
        if (! is_array($adjusted) || $adjusted === []) {
            return false;
        }

        $isAssoc = array_keys($adjusted) !== range(0, count($adjusted) - 1);
        if ($isAssoc) {
            return false;
        }

        return in_array($slotKey, $adjusted, true);
    }

    /**
     * @param  array<string, mixed>  $slotMap
     * @param  array<int, string>  $keys
     */
    private function hasSpecialSlotRemark(array $slotMap, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = strtoupper(trim((string) ($slotMap[$key] ?? '')));
            if ($value !== '' && $value !== 'TIME' && $value !== 'ABSENT') {
                return true;
            }
        }

        return false;
    }
}
