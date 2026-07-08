<?php

namespace App\Http\Controllers;

use App\Models\SpecialPrivilegeReason;
use Illuminate\Http\JsonResponse;

class SpecialPrivilegeReasonController extends Controller
{
    /**
     * Get active special privilege leave reasons formatted for options.
     */
    public function options(): JsonResponse
    {
        $reasons = SpecialPrivilegeReason::query()
            ->active()
            ->orderBy('description')
            ->get(['id', 'description'])
            ->map(fn (SpecialPrivilegeReason $reason): array => [
                'id' => $reason->id,
                'description' => $reason->description,
                'label' => $reason->description,
                'value' => $reason->description,
            ])
            ->values();

        return response()->json([
            'reasons' => $reasons,
        ]);
    }
}
