<?php

namespace App\Services;

use App\Models\HRAccount;
use App\Models\HRModulePermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class HrAccessControlService
{
    public const MODULE_DASHBOARD = 'dashboard';

    public const MODULE_APPLICATIONS = 'applications';

    public const MODULE_RECEIVING = 'receiving';

    public const MODULE_COC_APPLICATIONS = 'coc_applications';

    public const MODULE_EMPLOYEE_MANAGEMENT = 'employee_management';

    public const MODULE_USER_MANAGEMENT = 'user_management';

    public const MODULE_REPORTS_MONITORING = 'reports_monitoring';

    public const MODULE_LEAVE_TYPES = 'leave_types';

    public const MODULE_OFFICE_LIBRARY = 'office_library';

    public const MODULE_ILLNESS_LIBRARY = 'illness_library';

    public const MODULE_WORK_SCHEDULES = 'work_schedules';

    public const MODULE_SIGNATORIES = 'signatories';

    public const MODULE_ACCESS_CONTROL = 'access_control';

    /**
     * @return array<int, array{key:string,label:string,path:string}>
     */
    public function moduleDefinitions(): array
    {
        return [
            [
                'key' => self::MODULE_DASHBOARD,
                'label' => 'Dashboard',
                'path' => '/hr/dashboard',
            ],
            [
                'key' => self::MODULE_APPLICATIONS,
                'label' => 'Applications',
                'path' => '/hr/applications',
            ],
            [
                'key' => self::MODULE_RECEIVING,
                'label' => 'Receiving Application',
                'path' => '/hr/receiving',
            ],
            [
                'key' => self::MODULE_COC_APPLICATIONS,
                'label' => 'COC Applications',
                'path' => '/hr/coc-applications',
            ],
            [
                'key' => self::MODULE_EMPLOYEE_MANAGEMENT,
                'label' => 'Employee Management',
                'path' => '/hr/employees',
            ],
            [
                'key' => self::MODULE_USER_MANAGEMENT,
                'label' => 'User Management',
                'path' => '/hr/user-management',
            ],
            [
                'key' => self::MODULE_REPORTS_MONITORING,
                'label' => 'Reports & Monitoring',
                'path' => '/hr/reports',
            ],
            [
                'key' => self::MODULE_LEAVE_TYPES,
                'label' => 'Leave Types',
                'path' => '/hr/leave-types',
            ],
            [
                'key' => self::MODULE_OFFICE_LIBRARY,
                'label' => 'Office Library',
                'path' => '/hr/departments-library',
            ],
            [
                'key' => self::MODULE_ILLNESS_LIBRARY,
                'label' => 'Illness Library',
                'path' => '/hr/illness-library',
            ],
            [
                'key' => self::MODULE_WORK_SCHEDULES,
                'label' => 'Work Schedules',
                'path' => '/hr/work-schedules',
            ],
            [
                'key' => self::MODULE_SIGNATORIES,
                'label' => 'Signatories',
                'path' => '/hr/signatories',
            ],
            [
                'key' => self::MODULE_ACCESS_CONTROL,
                'label' => 'Access Control',
                'path' => '/hr/access-control',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return array_values(array_map(
            static fn (array $module): string => $module['key'],
            $this->moduleDefinitions()
        ));
    }

    /**
     * @return array<int, string>
     */
    public function accessControlOwnerUsernames(): array
    {
        return ['hr', 'hr2', 'hr3'];
    }

    public function isAccessControlOwnerByUsername(?string $username): bool
    {
        $normalizedUsername = mb_strtolower(trim((string) $username));
        if ($normalizedUsername === '') {
            return false;
        }

        foreach ($this->accessControlOwnerUsernames() as $ownerUsername) {
            if ($normalizedUsername === mb_strtolower(trim($ownerUsername))) {
                return true;
            }
        }

        return false;
    }

    public function isAccessControlOwner(?HRAccount $account): bool
    {
        if (! $account) {
            return false;
        }

        return $this->isAccessControlOwnerByUsername((string) ($account->username ?? ''));
    }

    public function isValidModuleKey(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->moduleKeys(), true);
    }

    /**
     * @return array<int, string>
     */
    public function allowedModuleKeysForAccount(HRAccount $account): array
    {
        if ($this->isAccessControlOwner($account)) {
            return $this->moduleKeys();
        }

        if (! Schema::hasTable('tblHRModulePermissions')) {
            return [];
        }

        $grantedModuleKeys = HRModulePermission::query()
            ->where('hr_account_id', $account->id)
            ->pluck('module_key')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        return array_values(array_filter(
            $this->moduleKeys(),
            static fn (string $moduleKey): bool => in_array($moduleKey, $grantedModuleKeys, true)
        ));
    }

    public function hasModuleAccess(HRAccount $account, string $moduleKey): bool
    {
        if (! $this->isValidModuleKey($moduleKey)) {
            return false;
        }

        if ($this->isAccessControlOwner($account)) {
            return true;
        }

        if (! Schema::hasTable('tblHRModulePermissions')) {
            return false;
        }

        return HRModulePermission::query()
            ->where('hr_account_id', $account->id)
            ->where('module_key', $moduleKey)
            ->exists();
    }

    public function resolveDashboardRouteForAccount(HRAccount $account): string
    {
        $moduleKeys = $this->allowedModuleKeysForAccount($account);
        $priority = [
            self::MODULE_DASHBOARD,
            self::MODULE_APPLICATIONS,
            self::MODULE_RECEIVING,
            self::MODULE_COC_APPLICATIONS,
            self::MODULE_EMPLOYEE_MANAGEMENT,
            self::MODULE_USER_MANAGEMENT,
            self::MODULE_REPORTS_MONITORING,
            self::MODULE_ACCESS_CONTROL,
            self::MODULE_LEAVE_TYPES,
            self::MODULE_OFFICE_LIBRARY,
            self::MODULE_ILLNESS_LIBRARY,
            self::MODULE_WORK_SCHEDULES,
            self::MODULE_SIGNATORIES,
        ];

        foreach ($priority as $moduleKey) {
            if (! in_array($moduleKey, $moduleKeys, true)) {
                continue;
            }

            foreach ($this->moduleDefinitions() as $moduleDefinition) {
                if ($moduleDefinition['key'] === $moduleKey) {
                    return $moduleDefinition['path'];
                }
            }
        }

        return '/settings';
    }

    /**
     * @param  array<int, string>  $moduleKeys
     * @return array<int, string>
     */
    public function sanitizeModuleKeys(array $moduleKeys): array
    {
        $normalizedModuleKeys = array_values(array_unique(array_map(
            static fn (string $value): string => trim($value),
            $moduleKeys
        )));

        return array_values(array_filter(
            $this->moduleKeys(),
            static fn (string $moduleKey): bool => in_array($moduleKey, $normalizedModuleKeys, true)
        ));
    }

    /**
     * @return Collection<int, array{
     *     id:int,
     *     full_name:string,
     *     username:string,
     *     is_access_control_owner:bool,
     *     can_edit_permissions:bool,
     *     module_keys:array<int, string>
     * }>
     */
    public function hrAccountsWithAccess(): Collection
    {
        return HRAccount::query()
            ->orderBy('full_name')
            ->orderBy('username')
            ->get()
            ->map(function (HRAccount $account): array {
                $isAccessControlOwner = $this->isAccessControlOwner($account);

                return [
                    'id' => (int) $account->id,
                    'full_name' => trim((string) ($account->full_name ?? '')),
                    'username' => trim((string) ($account->username ?? '')),
                    'is_access_control_owner' => $isAccessControlOwner,
                    'can_edit_permissions' => ! $isAccessControlOwner,
                    'module_keys' => $this->allowedModuleKeysForAccount($account),
                ];
            })
            ->values();
    }
}
