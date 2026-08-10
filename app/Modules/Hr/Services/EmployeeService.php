<?php

namespace App\Modules\Hr\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * خدمة الموظفين — إنشاء/تحديث موظف مع ربطه تلقائيًا بسجل عميل
 * لاستخدام البيع الآجل (ذمم) في نقطة البيع لاحقًا (المرحلة 3).
 */
class EmployeeService
{
    /** إنشاء موظف + سجل عميل مرتبط داخل معاملة واحدة. */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::create($data + ['created_by' => Auth::id()]);
            $employee->customer()->associate($this->makeCustomer($employee));
            $employee->save();

            return $employee;
        });
    }

    /** تحديث بيانات الموظف ومزامنة سجل العميل المرتبط. */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee->update($data);

            $customer = $employee->customer ?: $this->makeCustomer($employee);
            $customer->fill([
                'name' => $employee->name,
                'primary_phone' => $employee->phone ?: $customer->primary_phone,
                'branch_id' => $employee->branch_id,
            ])->save();

            if (! $employee->customer_id) {
                $employee->customer()->associate($customer)->save();
            }

            return $employee;
        });
    }

    /** إنشاء سجل عميل داخلي مخصّص للموظف (فئة staff، مصدر pos). */
    private function makeCustomer(Employee $employee): Customer
    {
        return Customer::create([
            'name' => $employee->name,
            'primary_phone' => $employee->phone ?: 'EMP-'.strtoupper(Str::random(8)),
            'source' => 'pos',
            'category' => 'staff',
            'branch_id' => $employee->branch_id,
            'notes' => __('حساب موظف — للذمم/السلف عبر نقطة البيع.'),
            'created_by' => Auth::id(),
        ]);
    }
}
