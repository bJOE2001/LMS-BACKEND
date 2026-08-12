<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\LeaveApplicationPrintLog;
use Illuminate\Http\Request;

class LeaveApplicationPrintLogController extends Controller
{
    /**
     * Retrieve paginated application print logs.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function adminIndex(Request $request)
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $deptName = $admin->department?->name;
        $departmentEmployeeControlNos = \App\Models\HrisEmployee::controlNosByOffice($deptName);

        $query = LeaveApplicationPrintLog::with(['leaveApplication']);

        // Restrict to applications from this admin's department
        if ($departmentEmployeeControlNos !== []) {
            $query->whereHas('leaveApplication', function ($q) use ($departmentEmployeeControlNos) {
                $q->whereIn('employee_control_no', $departmentEmployeeControlNos);
            });
        } elseif ($admin->department_id !== null) {
            // If they have a department_id but no employees matched, they shouldn't see anything.
            $query->where('id', '<', 0); // Force empty result
        }

        // Search filter
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('leaveApplication', function ($qApp) use ($search) {
                    $qApp->where('id', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%")
                        ->orWhere('employee_control_no', 'like', "%{$search}%");
                })
                // Or search by printed by name
                    ->orWhere('printed_by_name', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sortBy', 'created_at');
        $descending = filter_var($request->input('descending', 'true'), FILTER_VALIDATE_BOOLEAN);
        $direction = $descending ? 'desc' : 'asc';

        $validSortColumns = ['id', 'created_at', 'printed_by_name', 'printed_by_type'];
        if (in_array($sortBy, $validSortColumns)) {
            $query->orderBy($sortBy, $direction);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $rowsPerPage = (int) $request->input('rowsPerPage', 15);
        if ($rowsPerPage <= 0) {
            $rowsPerPage = 15;
        }

        $logs = $query->paginate($rowsPerPage);

        return response()->json($logs);
    }

    /**
     * Retrieve paginated application print logs for HR (all offices).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function hrIndex(Request $request)
    {
        $query = LeaveApplicationPrintLog::with(['leaveApplication']);

        // Search filter
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('leaveApplication', function ($qApp) use ($search) {
                    $qApp->where('id', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%")
                        ->orWhere('employee_control_no', 'like', "%{$search}%");
                })
                // Or search by printed by name
                    ->orWhere('printed_by_name', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sortBy', 'created_at');
        $descending = filter_var($request->input('descending', 'true'), FILTER_VALIDATE_BOOLEAN);
        $direction = $descending ? 'desc' : 'asc';

        $validSortColumns = ['id', 'created_at', 'printed_by_name', 'printed_by_type'];
        if (in_array($sortBy, $validSortColumns)) {
            $query->orderBy($sortBy, $direction);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $rowsPerPage = (int) $request->input('rowsPerPage', 15);
        if ($rowsPerPage <= 0) {
            $rowsPerPage = 15;
        }

        $logs = $query->paginate($rowsPerPage);

        return response()->json($logs);
    }
}
