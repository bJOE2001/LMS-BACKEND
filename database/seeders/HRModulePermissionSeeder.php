<?php

namespace Database\Seeders;

use App\Models\HRAccount;
use App\Models\HRModulePermission;
use App\Services\HrAccessControlService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class HRModulePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('tblHRModulePermissions')) {
            return;
        }

        $accessControl = app(HrAccessControlService::class);
        $ownerUsernames = $accessControl->accessControlOwnerUsernames();
        $moduleKeys = $accessControl->moduleKeys();

        $ownerAccounts = HRAccount::query()
            ->whereIn('username', $ownerUsernames)
            ->get(['id']);

        if ($ownerAccounts->isEmpty() || $moduleKeys === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($ownerAccounts as $ownerAccount) {
            foreach ($moduleKeys as $moduleKey) {
                $rows[] = [
                    'hr_account_id' => (int) $ownerAccount->id,
                    'module_key' => $moduleKey,
                    'granted_by_hr_account_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        HRModulePermission::query()->upsert(
            $rows,
            ['hr_account_id', 'module_key'],
            ['updated_at']
        );
    }
}
