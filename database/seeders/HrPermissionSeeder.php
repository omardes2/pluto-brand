<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات الموارد البشرية (الموظفون/الرواتب/الذمم) — Production.
 * مخطط ADR-021: `{module}.{resource}.{action}`.
 */
class HrPermissionSeeder extends Seeder
{
    private array $permissions = [
        'hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.employees.delete',
        'hr.employees.ledger', // تسجيل قيود دفتر الحساب (سلف/رواتب/تسويات)
    ];

    private array $grants = [
        'manager' => ['hr.employees.view', 'hr.employees.ledger'],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($admin = Role::where('name', 'admin')->first()) {
            $admin->givePermissionTo($this->permissions);
        }

        foreach ($this->grants as $roleName => $abilities) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($abilities);
            }
        }
    }
}
