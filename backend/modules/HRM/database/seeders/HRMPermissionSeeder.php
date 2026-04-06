<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class HRMPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Employees
            'hrm.employees.view',
            'hrm.employees.create',
            'hrm.employees.update',
            'hrm.employees.delete',
            // Contracts
            'hrm.contracts.view',
            'hrm.contracts.create',
            'hrm.contracts.update',
            'hrm.contracts.delete',
            // Timeshifts
            'hrm.timeshifts.view',
            'hrm.timeshifts.create',
            'hrm.timeshifts.update',
            'hrm.timeshifts.delete',
            // Attendance
            'hrm.attendance.view',
            'hrm.attendance.create',
            'hrm.attendance.update',
            'hrm.attendance.delete',
            // Leave Requests
            'hrm.leave.view',
            'hrm.leave.create',
            'hrm.leave.update',
            'hrm.leave.delete',
            'hrm.leave.approve',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Grant all HRM permissions to the admin role if it exists
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        if (isset($this->command)) {
            $this->command->info('HRM permissions seeded: ' . count($permissions) . ' permissions.');
        }
    }
}
