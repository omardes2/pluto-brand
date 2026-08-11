<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLedgerEntry;
use App\Modules\Hr\Services\EmployeeLedgerService;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalaryAndDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $this->variant = Product::factory()->create()->defaultVariant;
        $this->variant->update(['wholesale_price' => 0]);
        Product::whereKey($this->variant->product_id)->update(['is_active' => true, 'status' => 'active']);
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    public function test_monthly_salary_accrual_is_idempotent(): void
    {
        Employee::factory()->create(['monthly_salary' => 2000]);
        Employee::factory()->create(['monthly_salary' => 1500]);
        Employee::factory()->create(['monthly_salary' => 0]); // بلا راتب → يُتخطّى

        $ledger = app(EmployeeLedgerService::class);
        $this->assertSame(2, $ledger->accrueMonthlySalaries());   // مرّة أولى: موظفان
        $this->assertSame(0, $ledger->accrueMonthlySalaries());   // تكرار نفس الشهر: لا شيء

        $this->assertSame(2, EmployeeLedgerEntry::where('type', 'salary_accrual')->count());
    }

    public function test_salary_run_button_requires_permission_and_accrues(): void
    {
        Employee::factory()->create(['monthly_salary' => 1800]);

        // كاشير بلا صلاحية دفتر الحساب.
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::findOrCreate('cashier', 'web'));
        $this->actingAs($cashier)->post(route('admin.employees.salaries.run'))->assertForbidden();

        $this->actingAs($this->admin())->post(route('admin.employees.salaries.run'))->assertRedirect();
        $this->assertDatabaseHas('employee_ledger_entries', ['type' => 'salary_accrual', 'amount' => 1800]);
    }

    public function test_cashier_can_apply_discount(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->admin()->branch_id]);
        $cashier->assignRole(Role::findOrCreate('cashier', 'web'));
        $this->assertTrue($cashier->can('pos.discount'));

        $this->actingAs($cashier);
        $this->post(route('admin.pos.shift.open'), ['warehouse_id' => $this->warehouse->id, 'opening_float' => 100]);

        $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'cash',
            'discount' => 5,
        ])->assertOk()->assertJsonPath('discount_total', 5);
    }

    public function test_manager_can_apply_discount(): void
    {
        $this->assertTrue($this->admin()->can('pos.discount'));

        $this->actingAs($this->admin());
        $this->post(route('admin.pos.shift.open'), ['warehouse_id' => $this->warehouse->id, 'opening_float' => 100]);

        $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'cash',
            'discount' => 5,
        ])->assertOk()->assertJsonPath('discount_total', 5);
    }
}
