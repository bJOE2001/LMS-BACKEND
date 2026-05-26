<?php

namespace App\Http\Controllers;

use App\Models\HRAccount;
use App\Services\HrAccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HRAccessControlController extends Controller
{
    public function modules(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        $accessControl = app(HrAccessControlService::class);

        return response()->json([
            'modules' => $accessControl->moduleDefinitions(),
        ]);
    }

    public function hrAdmins(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        $accessControl = app(HrAccessControlService::class);

        return response()->json([
            'accounts' => $accessControl->hrAccountsWithAccess(),
        ]);
    }

    public function updateHrAdminModules(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        $accessControl = app(HrAccessControlService::class);

        $validated = $request->validate([
            'module_keys' => ['required', 'array'],
            'module_keys.*' => [
                'string',
                Rule::in($accessControl->moduleKeys()),
            ],
        ]);

        $target = HRAccount::query()->find($id);
        if (! $target) {
            return response()->json([
                'message' => 'HR admin account not found.',
            ], 404);
        }

        if ($accessControl->isAccessControlOwner($target)) {
            throw ValidationException::withMessages([
                'hr_account_id' => ['Seeded access-control owner permissions cannot be modified.'],
            ]);
        }

        $sanitizedModuleKeys = $accessControl->sanitizeModuleKeys(
            array_values($validated['module_keys'] ?? [])
        );

        DB::transaction(function () use ($target, $actor, $sanitizedModuleKeys): void {
            $target->modulePermissions()->delete();

            if ($sanitizedModuleKeys === []) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($sanitizedModuleKeys as $moduleKey) {
                $rows[] = [
                    'hr_account_id' => (int) $target->id,
                    'module_key' => $moduleKey,
                    'granted_by_hr_account_id' => (int) $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $target->modulePermissions()->createMany($rows);
        });

        return response()->json([
            'message' => 'HR module access updated successfully.',
            'account' => $accessControl->hrAccountsWithAccess()
                ->firstWhere('id', (int) $target->id),
        ]);
    }
}
