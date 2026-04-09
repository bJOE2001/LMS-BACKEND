<?php

namespace App\Http\Controllers;

use App\Models\COCApplication;
use App\Models\LeaveApplication;
use App\Models\Notification;
use App\Models\HrisEmployee;
use App\Services\RecycleBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NotificationController — CRUD for user notifications.
 * LOCAL LMS_DB only.
 */
class NotificationController extends Controller
{
    /**
     * GET /notifications/unread-count - lightweight badge count for the header.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::query()
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * GET /notifications — list notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::with([
            'leaveApplication.leaveType',
            'leaveApplication.applicantAdmin.department',
            'cocApplication.rows',
            'cocApplication.reviewedByAdmin.department',
            'cocApplication.reviewedByHr',
            'cocApplication.ctoLeaveType',
        ])
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'leave_application_id' => $n->leave_application_id,
                'coc_application_id' => $n->coc_application_id,
                'application_type' => $this->resolveNotificationApplicationType($n),
                'application' => $this->formatNotificationApplication($n),
                'leave_application' => $this->formatLeaveApplication($n->leaveApplication),
                'coc_application' => $this->formatCocApplication($n->cocApplication),
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        return response()->json($notifications);
    }

    /**
     * PUT /notifications/{id}/read — mark a single notification as read.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    /**
     * PUT /notifications/read-all — mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * GET /notifications/{id}/application — fetch linked leave application details for one notification.
     */
    public function applicationDetails(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$notification->leave_application_id && !$notification->coc_application_id) {
            return response()->json([
                'application' => null,
                'message' => 'Notification is not linked to an application.',
            ]);
        }

        if ($notification->coc_application_id) {
            $application = COCApplication::with(['rows', 'reviewedByAdmin.department', 'reviewedByHr', 'ctoLeaveType'])
                ->find($notification->coc_application_id);

            return response()->json([
                'application_type' => 'COC',
                'application' => $this->formatCocApplication($application),
            ]);
        }

        $application = LeaveApplication::with(['leaveType', 'applicantAdmin.department'])
            ->find($notification->leave_application_id);

        return response()->json([
            'application_type' => 'LEAVE',
            'application' => $this->formatLeaveApplication($application),
        ]);
    }

    /**
     * DELETE /notifications/{id} — dismiss/delete a notification.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $notification->loadMissing([
            'leaveApplication.leaveType',
            'leaveApplication.applicantAdmin.department',
            'cocApplication.rows',
            'cocApplication.reviewedByAdmin.department',
            'cocApplication.reviewedByHr',
            'cocApplication.ctoLeaveType',
        ]);

        DB::transaction(function () use ($notification, $request): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $notification,
                $request->user(),
                [
                    'record_title' => $notification->title,
                    'delete_source' => 'notifications',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => array_merge($notification->toArray(), [
                        'application_type' => $this->resolveNotificationApplicationType($notification),
                        'application' => $this->formatNotificationApplication($notification),
                        'leave_application' => $this->formatLeaveApplication($notification->leaveApplication),
                        'coc_application' => $this->formatCocApplication($notification->cocApplication),
                    ]),
                ]
            );

            $notification->delete();
        });

        return response()->json(['message' => 'Notification deleted.']);
    }

    private function formatLeaveApplication(?LeaveApplication $application): ?array
    {
        if (!$application) {
            return null;
        }

        $employeeName = trim((string) ($application->employee_name ?? ''));
        $office = $application->applicantAdmin?->department?->name;
        $employee = null;

        if ($employeeName === '' || trim((string) ($office ?? '')) === '') {
            $employee = $this->resolveApplicationEmployee($application);
            if ($employeeName === '') {
                $employeeName = trim(($employee?->firstname ?? '') . ' ' . ($employee?->surname ?? ''));
            }
            if (trim((string) ($office ?? '')) === '') {
                $office = $employee?->office;
            }
        }

        if ($employeeName === '') {
            $employeeName = $application->applicantAdmin?->full_name ?: null;
        }

        return [
            'application_type' => 'LEAVE',
            'id' => $application->id,
            'employee_control_no' => $application->employee_control_no,
            'applicant_admin_id' => $application->applicant_admin_id,
            'applicant_name' => $employeeName,
            'office' => $office,
            'leave_type_id' => $application->leave_type_id,
            'leave_type_name' => $application->leaveType?->name,
            'start_date' => $application->start_date?->toDateString(),
            'end_date' => $application->end_date?->toDateString(),
            'total_days' => (float) $application->total_days,
            'reason' => $application->reason,
            'details_of_leave' => $application->details_of_leave,
            'selected_date_half_day_portion' => is_array($application->selected_date_half_day_portion) ? $application->selected_date_half_day_portion : null,
            'status' => $this->toReadableStatus($application->status),
            'raw_status' => $application->status,
            'remarks' => $application->remarks,
            'selected_dates' => $application->resolvedSelectedDates(),
            'commutation' => $application->commutation,
            'is_monetization' => (bool) $application->is_monetization,
            'equivalent_amount' => $application->equivalent_amount !== null ? (float) $application->equivalent_amount : null,
            'date_filed' => $application->created_at?->toDateString(),
            'admin_approved_at' => $application->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $application->hr_approved_at?->toIso8601String(),
        ];
    }

    private function formatCocApplication(?COCApplication $application): ?array
    {
        if (!$application) {
            return null;
        }

        $resolvedEmployee = $this->resolveCocApplicationEmployee($application);
        $employeeName = trim((string) ($application->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = trim(implode(' ', array_filter([
                trim((string) ($resolvedEmployee?->firstname ?? '')),
                trim((string) ($resolvedEmployee?->middlename ?? '')),
                trim((string) ($resolvedEmployee?->surname ?? '')),
            ])));
        }

        $rowDates = $application->relationLoaded('rows')
            ? $application->rows
                ->map(fn ($row) => $row->overtime_date?->toDateString())
                ->filter(fn (?string $date): bool => $date !== null && trim($date) !== '')
                ->unique()
                ->sort()
                ->values()
                ->all()
            : [];

        $durationHours = $this->minutesToHours((int) ($application->total_minutes ?? 0));

        return [
            'application_type' => 'COC',
            'id' => $application->id,
            'employee_control_no' => (string) ($application->employee_control_no ?? ''),
            'applicant_name' => $employeeName !== '' ? $employeeName : null,
            'office' => $resolvedEmployee?->office,
            'leave_type_name' => 'COC Application',
            'status' => $this->toReadableCocStatus($application),
            'raw_status' => $this->deriveRawCocStatus($application),
            'selected_dates' => $rowDates,
            'start_date' => $rowDates[0] ?? null,
            'end_date' => $rowDates !== [] ? $rowDates[count($rowDates) - 1] : null,
            'total_hours' => $durationHours,
            'duration_label' => $this->formatHours($durationHours),
            'remarks' => $application->remarks,
            'date_filed' => $application->created_at?->toDateString(),
            'reviewed_by_admin' => $application->reviewedByAdmin?->full_name,
            'reviewed_by_hr' => $application->reviewedByHr?->full_name,
            'cto_leave_type_name' => $application->ctoLeaveType?->name,
            'cto_credited_days' => $application->cto_credited_days !== null ? (float) $application->cto_credited_days : null,
            'cto_credited_at' => $application->cto_credited_at?->toIso8601String(),
        ];
    }

    private function resolveApplicationEmployee(LeaveApplication $application): ?object
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function resolveCocApplicationEmployee(COCApplication $application): ?object
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function formatNotificationApplication(Notification $notification): ?array
    {
        if ($notification->coc_application_id) {
            return $this->formatCocApplication($notification->cocApplication);
        }

        return $this->formatLeaveApplication($notification->leaveApplication);
    }

    private function resolveNotificationApplicationType(Notification $notification): ?string
    {
        if ($notification->coc_application_id) {
            return 'COC';
        }

        return $notification->leave_application_id ? 'LEAVE' : null;
    }

    private function toReadableStatus(?string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
            default => (string) $status,
        };
    }

    private function deriveRawCocStatus(COCApplication $application): string
    {
        if ($application->status !== COCApplication::STATUS_PENDING) {
            return (string) $application->status;
        }

        return $application->admin_reviewed_at ? 'PENDING_HR' : 'PENDING_ADMIN';
    }

    private function toReadableCocStatus(COCApplication $application): string
    {
        return match ($this->deriveRawCocStatus($application)) {
            'PENDING_ADMIN' => 'Pending Admin',
            'PENDING_HR' => 'Pending HR',
            'APPROVED' => 'Approved',
            'REJECTED' => 'Rejected',
            default => (string) $application->status,
        };
    }

    private function minutesToHours(int $minutes): float
    {
        return $minutes > 0 ? round($minutes / 60, 2) : 0.0;
    }

    private function formatHours(float $hours): string
    {
        $display = $hours === (float) ((int) $hours) ? (string) ((int) $hours) : (string) $hours;
        return "{$display} h";
    }
}
