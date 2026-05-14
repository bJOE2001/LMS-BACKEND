<?php

namespace App\Http\Controllers;

use App\Models\Illness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HRIllnessLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $searchTerm = trim((string) ($validated['search'] ?? ''));

        $illnesses = Illness::query()
            ->active()
            ->when($searchTerm !== '', function ($query) use ($searchTerm): void {
                $query->where('name', 'like', "%{$searchTerm}%");
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Illness $illness): array => $this->serializeIllness($illness))
            ->values();

        return response()->json([
            'illnesses' => $illnesses,
            'summary' => [
                'total_illnesses' => $illnesses->count(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        $illnesses = Illness::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Illness $illness): array => [
                'id' => $illness->id,
                'name' => $illness->name,
                'label' => $illness->name,
                'value' => $illness->name,
            ])
            ->values();

        return response()->json([
            'illnesses' => $illnesses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $illness = Illness::create([
            'name' => trim((string) $validated['name']),
            'is_inactive' => false,
        ]);

        return response()->json([
            'message' => 'Illness created successfully.',
            'illness' => $this->serializeIllness($illness),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $illness = Illness::query()->find($id);
        if (!$illness) {
            return response()->json([
                'message' => 'Illness not found.',
            ], 404);
        }

        $validated = $this->validatePayload($request, $illness->id);

        $illness->name = trim((string) $validated['name']);
        $illness->save();

        return response()->json([
            'message' => 'Illness updated successfully.',
            'illness' => $this->serializeIllness($illness->refresh()),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $illness = Illness::query()->active()->find($id);
        if (!$illness) {
            return response()->json([
                'message' => 'Illness not found.',
            ], 404);
        }

        $illness->is_inactive = true;
        $illness->save();

        return response()->json([
            'message' => 'Illness marked inactive successfully.',
        ]);
    }

    private function validatePayload(Request $request, ?int $illnessId = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $nameRule = Rule::unique('tblIllnesses', 'name');
        if ($illnessId !== null) {
            $nameRule = $nameRule->ignore($illnessId);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $nameRule],
        ]);
    }

    private function serializeIllness(Illness $illness): array
    {
        return [
            'id' => $illness->id,
            'name' => trim((string) $illness->name),
            'is_inactive' => (bool) $illness->is_inactive,
            'created_at' => $illness->created_at?->toIso8601String(),
            'updated_at' => $illness->updated_at?->toIso8601String(),
        ];
    }
}
