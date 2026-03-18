<?php

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Tasks ─────────────────────────────────────────────────────────
Schedule::command('leave:accrue')->monthlyOn(1, '00:01');
Schedule::command('leave:reset')->yearlyOn(1, 1, '00:05');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('lms:list-accounts', function () {
    $this->info('--- HR Accounts ---');
    $hr = HRAccount::orderBy('id')->get(['id', 'full_name', 'username']);
    if ($hr->isEmpty()) {
        $this->line('  (none)');
    } else {
        foreach ($hr as $a) {
            $this->line("  [{$a->id}] {$a->username} — {$a->full_name}");
        }
    }

    $this->newLine();
    $this->info('--- Department Admins ---');
    $admins = DepartmentAdmin::with('department:id,name')->orderBy('id')->get(['id', 'department_id', 'full_name', 'username']);
    if ($admins->isEmpty()) {
        $this->line('  (none)');
    } else {
        foreach ($admins as $a) {
            $dept = $a->relationLoaded('department') ? $a->department->name : '-';
            $this->line("  [{$a->id}] {$a->username} — {$a->full_name} ({$dept})");
        }
    }
})->purpose('List all HR and department admin accounts');
