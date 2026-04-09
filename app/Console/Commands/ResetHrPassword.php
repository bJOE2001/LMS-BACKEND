<?php

namespace App\Console\Commands;

use App\Models\HRAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reset an HR account password and force password change on next login.
 *
 * Security behavior:
 * - Marks must_change_password = true
 * - Revokes existing Sanctum tokens for the account
 * - Outputs temporary password once to the terminal
 */
class ResetHrPassword extends Command
{
    protected $signature = 'hr:reset-password
        {username : HR account username}
        {--password= : Temporary password override (auto-generated when omitted)}';

    protected $description = 'Reset an HR account password and require password change on next login.';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        if ($username === '') {
            $this->error('Username is required.');
            return self::FAILURE;
        }

        $account = HRAccount::query()
            ->where('username', $username)
            ->first();

        if (! $account) {
            $this->error("HR account '{$username}' was not found.");
            return self::FAILURE;
        }

        $providedPassword = (string) $this->option('password');
        $temporaryPassword = trim($providedPassword) !== ''
            ? $providedPassword
            : Str::password(16, true, true, true, false);

        try {
            DB::transaction(function () use ($account, $temporaryPassword): void {
                $account->forceFill([
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'active_personal_access_token_id' => null,
                ])->save();

                $account->tokens()->delete();
            });
        } catch (Throwable $e) {
            $this->error('Failed to reset HR password: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('HR password reset successful.');
        $this->line('Username: '.$account->username);
        $this->warn('Temporary password (displayed once): '.$temporaryPassword);
        $this->line('Existing API tokens were revoked for this account.');
        $this->line('User must change password immediately after login.');

        return self::SUCCESS;
    }
}
