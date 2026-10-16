<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    /**
     * List all holidays, optionally filtered by year or active state.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->filled('year')) {
            $year = (int) $request->input('year');
            $query->where(function ($q) use ($year): void {
                $q->whereYear('holiday_date', $year)
                    ->orWhere('is_recurring', true);
            });
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $holidays = $query->orderBy('holiday_date')->get();

        return response()->json([
            'holidays' => $holidays,
        ]);
    }

    /**
     * Create a new holiday.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::in(['REGULAR', 'SPECIAL_NON_WORKING', 'SPECIAL_WORKING'])],
            'is_recurring' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $holiday = Holiday::create([
            'holiday_date' => $validated['holiday_date'],
            'name' => trim($validated['name']),
            'type' => $validated['type'],
            'is_recurring' => $validated['is_recurring'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Holiday created successfully.',
            'holiday' => $holiday,
        ], 201);
    }

    /**
     * Update an existing holiday.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::find($id);
        if (! $holiday) {
            return response()->json(['message' => 'Holiday not found.'], 404);
        }

        $validated = $request->validate([
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::in(['REGULAR', 'SPECIAL_NON_WORKING', 'SPECIAL_WORKING'])],
            'is_recurring' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $holiday->update([
            'holiday_date' => $validated['holiday_date'],
            'name' => trim($validated['name']),
            'type' => $validated['type'],
            'is_recurring' => $validated['is_recurring'] ?? $holiday->is_recurring,
            'is_active' => $validated['is_active'] ?? $holiday->is_active,
            'description' => $validated['description'] ?? $holiday->description,
        ]);

        return response()->json([
            'message' => 'Holiday updated successfully.',
            'holiday' => $holiday,
        ]);
    }

    /**
     * Delete an existing holiday.
     */
    public function destroy(int $id): JsonResponse
    {
        $holiday = Holiday::find($id);
        if (! $holiday) {
            return response()->json(['message' => 'Holiday not found.'], 404);
        }

        $holiday->delete();

        return response()->json([
            'message' => 'Holiday deleted successfully.',
        ]);
    }
}
