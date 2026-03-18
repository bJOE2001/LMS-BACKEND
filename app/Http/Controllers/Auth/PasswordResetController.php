<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    private const UNSUPPORTED_MESSAGE = 'Password reset via tokens is not available for LMS accounts. Contact your system administrator for a manual reset.';

    /**
     * Password reset is not available for LMS accounts because local account
     * tables do not store email addresses for broker-based recovery.
     */
    public function forgotPassword(): JsonResponse
    {
        return response()->json([
            'message' => self::UNSUPPORTED_MESSAGE,
        ], 501);
    }

    /**
     * Password reset tokens are unsupported for LMS accounts.
     */
    public function resetPassword(): JsonResponse
    {
        return response()->json([
            'message' => self::UNSUPPORTED_MESSAGE,
        ], 501);
    }
}
