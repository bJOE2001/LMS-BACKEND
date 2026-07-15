<?php

namespace App\Http\Controllers;

use App\Models\HRAccount;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveBalanceAccrualController extends Controller
{
    /**
     * Update multiple leave balance accrual histories (HR only).
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $request->validate([
            'updates' => ['required', 'array'],
            'updates.*.id' => ['required', 'integer', 'exists:tblLeaveBalanceCreditHistories,id'],
            'updates.*.credits_added' => ['required', 'numeric', 'min:0'],
        ]);

        $updates = $request->input('updates', []);

        DB::beginTransaction();
        try {
            foreach ($updates as $update) {
                $record = LeaveBalanceAccrualHistory::lockForUpdate()->findOrFail($update['id']);

                $oldCredits = (float) $record->credits_added;
                $newCredits = (float) $update['credits_added'];

                if ($oldCredits === $newCredits) {
                    continue;
                }

                $difference = $newCredits - $oldCredits;

                // Update the accrual history record
                $record->credits_added = $newCredits;
                $source = (string) $record->source;
                if (! str_contains($source, '(HR_EDIT)')) {
                    $record->source = substr(trim($source.' (HR_EDIT)'), 0, 30);
                }
                $record->save();

                // Update the associated leave balance
                $leaveBalance = LeaveBalance::lockForUpdate()->find($record->leave_balance_id);
                if ($leaveBalance) {
                    $leaveBalance->balance = max((float) $leaveBalance->balance + $difference, 0.0);
                    $leaveBalance->save();
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Leave accruals updated successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Accrual bulk update failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'message' => 'An error occurred while updating accruals.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific leave balance accrual histories by their IDs.
     */
    public function getByIds(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $ids = $request->query('ids');
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        if (! is_array($ids) || empty($ids)) {
            return response()->json(['message' => 'Missing or invalid ids parameter.'], 400);
        }

        $records = LeaveBalanceAccrualHistory::whereIn('id', $ids)->get();

        return response()->json([
            'data' => $records,
        ]);
    }
}
