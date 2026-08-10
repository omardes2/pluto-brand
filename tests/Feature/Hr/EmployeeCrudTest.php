<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    public function test_index_requires_permission(): void
    {
        $cashier = User::factory()->create();
        $this->actingAs($cashier)->get(route('admin.employees.index'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.employees.index'))->assertOk()->assertSee('الموظفون');
    }

    public function test_store_creates_employee_with_salary_and_linked_customer(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.employees.store'), [
                'name' => 'أحمد الموظف',
                'phone' => '0599123456',
                'job_title' => 'كاشير',
                'monthly_salary' => 2500,
                'is_active' => 1,
            ])->assertRedirect(route('admin.employees.index'));

        $employee = Employee::where('name', 'أحمد الموظف')->firstOrFail();
        $this->assertEquals(2500, (float) $employee->monthly_salary);
        // سجل عميل مرتبط تلقائيًا لاستخدام الآجل لاحقًا.
        $this->assertNotNull($employee->customer_id);
        $this->assertSame('أحمد الموظف', $employee->customer->name);
        $this->assertSame('staff', $employee->customer->category);
    }

    public function test_update_syncs_linked_customer_name(): void
    {
        $employee = app(EmployeeService::class)->create([
            'name' => 'قديم', 'monthly_salary' => 1000, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.employees.update', $employee), [
                'name' => 'جديد', 'monthly_salary' => 1800, 'is_active' => 1,
            ])->assertRedirect(route('admin.employees.index'));

        $employee->refresh();
        $this->assertSame('جديد', $employee->name);
        $this->assertEquals(1800, (float) $employee->monthly_salary);
        $this->assertSame('جديد', $employee->customer->fresh()->name);
    }

    public function test_toggle_and_delete(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.employees.toggle', $employee))->assertRedirect();
        $this->assertFalse($employee->fresh()->is_active);

        $this->actingAs($this->admin())->delete(route('admin.employees.destroy', $employee))->assertRedirect(route('admin.employees.index'));
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }
}
