<?php

namespace App\Console\Commands;

use App\Models\BiometricEnrollment;
use App\Services\Attendance\ZkBioTimeReconciliationService;
use Illuminate\Console\Command;

class ReconcileZkBioEnrollmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zkbio:reconcile-enrollments {--control_no= : Specific employee control number to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile pending biometric enrollments against ZKBio Time and target device synchronization logs.';

    /**
     * Execute the console command.
     */
    public function handle(ZkBioTimeReconciliationService $service): int
    {
        $controlNo = $this->option('control_no');

        if ($controlNo) {
            $enrollment = BiometricEnrollment::query()->where('employee_control_no', $controlNo)->first();
            if (! $enrollment) {
                $this->error("No biometric enrollment record found for control number [{$controlNo}].");

                return self::FAILURE;
            }

            $beforeStatus = $enrollment->status;
            $service->reconcileEnrollment($enrollment);
            $this->info("Reconciled employee [{$controlNo}]: {$beforeStatus} -> {$enrollment->status}");
            if ($enrollment->synced_devices) {
                $this->line('Synced devices: '.implode(', ', $enrollment->synced_devices));
            }

            return self::SUCCESS;
        }

        $pendingCount = BiometricEnrollment::query()
            ->whereIn('status', [
                BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
                BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED,
            ])
            ->count();

        $this->info("Checking {$pendingCount} pending enrollment(s)...");

        $updated = $service->reconcilePendingEnrollments();

        $this->info("Reconciliation complete. {$updated} enrollment(s) updated.");

        return self::SUCCESS;
    }
}
