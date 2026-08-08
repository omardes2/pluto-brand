<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات نقطة البيع (POS) + دور «كاشير».
 *
 * - pos.view            : فتح شاشة نقطة البيع.
 * - pos.sell            : إتمام عملية بيع.
 * - pos.discount        : تطبيق خصم على الفاتورة.
 * - pos.refund          : إرجاع/تبديل بالفاتورة الأصلية (بلا موافقة).
 * - pos.refund.no_invoice : إرجاع دون فاتورة أصلية (مسار المشرف الاستثنائي).
 * - pos.shift.manage    : فتح/إغلاق الوردية وحركات الدرج.
 */
class PosPermissionSeeder extends Seeder
{
    private array $permissions = [
        'pos.view', 'pos.sell', 'pos.discount', 'pos.refund', 'pos.refund.no_invoice', 'pos.shift.manage',
    ];

    /** قدرات الكاشير الأساسية (بلا الإرجاع دون فاتورة — للمشرف فقط). */
    private array $cashierAbilities = [
        'pos.view', 'pos.sell', 'pos.discount', 'pos.refund', 'pos.shift.manage',
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // مدير النظام يملك كل شيء.
        if ($admin = Role::where('name', 'admin')->first()) {
            $admin->givePermissionTo($this->permissions);
        }

        // دور «كاشير» مخصّص لنقطة البيع.
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->givePermissionTo($this->cashierAbilities);

        // المدير والمشرف: كل صلاحيات POS (بما فيها الإرجاع دون فاتورة).
        foreach (['manager', 'sales_supervisor'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($this->permissions);
            }
        }

        // موظف المبيعات يمكنه العمل ككاشير (القدرات الأساسية دون الإرجاع بلا فاتورة).
        if ($sales = Role::where('name', 'sales')->first()) {
            $sales->givePermissionTo($this->cashierAbilities);
        }
    }
}
