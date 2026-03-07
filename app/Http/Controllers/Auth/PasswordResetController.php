<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Send a reset link to the given email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            $request->throttle();

            return response()->json([
                'message' => __('If an account exists for that email, we have sent a password reset link.'),
            ]);
        }

        // Always return the same message to prevent email enumeration
        return response()->json([
            'message' => __('If an account exists for that email, we have sent a password reset link.'),
        ]);
    }

    /**
     * Reset the user's password.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password): void {
                $user->forceFill([
                    'password' => $password,
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __('Your password has been reset successfully.'),
            ]);
        }

        return response()->json([
            'message' => __('This password reset token is invalid or has expired.'),
        ], 400);
    }
}
