<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\DtrRemark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DtrRemarkController extends Controller
{
    /**
     * List all DTR remarks.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DtrRemark::query();

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $remarks = $query->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return response()->json([
            'remarks' => $remarks,
        ]);
    }

    /**
     * Create a new DTR remark.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'abbrv' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:150'],
            'order_no' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $remark = DtrRemark::create([
            'abbrv' => ! empty($validated['abbrv']) ? strtoupper(trim($validated['abbrv'])) : null,
            'description' => strtoupper(trim($validated['description'])),
            'order_no' => $validated['order_no'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Remark created successfully.',
            'remark' => $remark,
        ], 201);
    }

    /**
     * Update an existing DTR remark.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $remark = DtrRemark::find($id);
        if (! $remark) {
            return response()->json(['message' => 'Remark not found.'], 404);
        }

        $validated = $request->validate([
            'abbrv' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:150'],
            'order_no' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $remark->update([
            'abbrv' => ! empty($validated['abbrv']) ? strtoupper(trim($validated['abbrv'])) : null,
            'description' => strtoupper(trim($validated['description'])),
            'order_no' => $validated['order_no'] ?? $remark->order_no,
            'is_active' => $validated['is_active'] ?? $remark->is_active,
        ]);

        return response()->json([
            'message' => 'Remark updated successfully.',
            'remark' => $remark,
        ]);
    }

    /**
     * Delete an existing DTR remark.
     */
    public function destroy(int $id): JsonResponse
    {
        $remark = DtrRemark::find($id);
        if (! $remark) {
            return response()->json(['message' => 'Remark not found.'], 404);
        }

        $remark->delete();

        return response()->json([
            'message' => 'Remark deleted successfully.',
        ]);
    }
}
