<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SettingsController extends Controller
{
    /**
     * Get the current user's setting profile.
     */
    public function getProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $profile = [
            'username' => $user->username,
            'role' => $this->getRole($user),
        ];

        if ($user instanceof HRAccount || $user instanceof DepartmentAdmin) {
            $profile['full_name'] = $user->full_name;
        }

        if ($user instanceof DepartmentAdmin) {
            $profile['must_change_password'] = (bool) $user->must_change_password;
        }

        return response()->json($profile);
    }

    /**
     * Update profile information.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $rules = [
            'username' => 'required|string|max:255|unique:' . $user->getTable() . ',username,' . $user->id,
        ];

        if ($user instanceof HRAccount || $user instanceof DepartmentAdmin) {
            $rules['full_name'] = 'required|string|max:255';
        }

        $validated = $request->validate($rules);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The provided password does not match your current password.',
                'errors' => [
                    'current_password' => ['The provided password does not match your current password.']
                ]
            ], 422);
        }

        $data = [
            'password' => Hash::make($validated['password']),
        ];

        if ($user instanceof DepartmentAdmin) {
            $data['must_change_password'] = false;
        }

        $user->update($data);

        return response()->json([
            'message' => 'Password updated successfully.'
        ]);
    }

    /**
     * Helper to get the role name.
     */
    private function getRole($user): string
    {
        if ($user instanceof HRAccount) return 'hr';
        if ($user instanceof DepartmentAdmin) return 'admin';
        return 'unknown';
    }
}
