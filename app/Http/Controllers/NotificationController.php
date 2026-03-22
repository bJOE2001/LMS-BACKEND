<?php

namespace App\Http\Controllers;

use App\Models\LeaveApplication;
use App\Models\Notification;
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
     * GET /notifications — list notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::with([
            'leaveApplication.leaveType',
            'leaveApplication.employee',
            'leaveApplication.applicantAdmin.department',
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
                'leave_application' => $this->formatLeaveApplication($n->leaveApplication),
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

        if (!$notification->leave_application_id) {
            return response()->json([
                'application' => null,
                'message' => 'Notification is not linked to a leave application.',
            ]);
        }

        $application = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin.department'])
            ->find($notification->leave_application_id);

        return response()->json([
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
            'leaveApplication.employee',
            'leaveApplication.applicantAdmin.department',
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
                        'leave_application' => $this->formatLeaveApplication($notification->leaveApplication),
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

        $employeeName = trim(($application->employee?->firstname ?? '') . ' ' . ($application->employee?->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = $application->applicantAdmin?->full_name ?: null;
        }

        return [
            'id' => $application->id,
            'employee_control_no' => $application->employee_control_no,
            'applicant_admin_id' => $application->applicant_admin_id,
            'applicant_name' => $employeeName,
            'office' => $application->employee?->office ?? $application->applicantAdmin?->department?->name,
            'leave_type_id' => $application->leave_type_id,
            'leave_type_name' => $application->leaveType?->name,
            'start_date' => $application->start_date?->toDateString(),
            'end_date' => $application->end_date?->toDateString(),
            'total_days' => (float) $application->total_days,
            'reason' => $application->reason,
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
}
