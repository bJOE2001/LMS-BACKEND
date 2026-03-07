<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Actions for employee accounts: change password (required on first login).
 */
class EmployeeAuthController extends Controller
{
    /**
     * Change password for the authenticated employee. Required when must_change_password is true.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can use this endpoint.'], 403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($validated['current_password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.password')],
            ]);
        }

        $user->update([
            'password'             => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Password changed successfully. You may now use the application.',
        ]);
    }
}
