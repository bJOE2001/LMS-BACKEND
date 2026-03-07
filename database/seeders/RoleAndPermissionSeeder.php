<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Seed the roles and permissions for the LMS.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        /*
        |----------------------------------------------------------------------
        | Permissions
        |----------------------------------------------------------------------
        */
        $permissions = [
            // Leave management
            'leave.apply',
            'leave.view-own',
            'leave.cancel-own',

            // HR / Manager permissions
            'leave.view-all',
            'leave.approve',
            'leave.reject',
            'leave.reports',

            // Admin permissions
            'user.create',
            'user.update',
            'user.delete',
            'user.view-all',
            'role.assign',
            'settings.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        /*
        |----------------------------------------------------------------------
        | Roles & assign permissions
        |----------------------------------------------------------------------
        */

        // Employee — basic leave operations
        $employee = Role::firstOrCreate(['name' => 'employee']);
        $employee->syncPermissions([
            'leave.apply',
            'leave.view-own',
            'leave.cancel-own',
        ]);

        // HR — everything employee can do + team oversight
        $hr = Role::firstOrCreate(['name' => 'hr']);
        $hr->syncPermissions([
            'leave.apply',
            'leave.view-own',
            'leave.cancel-own',
            'leave.view-all',
            'leave.approve',
            'leave.reject',
            'leave.reports',
        ]);

        // Admin — full system access
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(Permission::all());
    }
}
