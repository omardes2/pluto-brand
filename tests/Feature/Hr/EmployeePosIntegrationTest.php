<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeLedgerService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePosIntegrationTest extends TestCase
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

    private function openShift(): void
    {
        $this->post(route('admin.pos.shift.open'), ['warehouse_id' => $this->warehouse->id, 'opening_float' => 100])
            ->assertRedirect(route('admin.pos.screen'));
    }

    private function makeEmployee(): Employee
    {
        return app(EmployeeService::class)->create(['name' => 'الموظف عبر البيع', 'monthly_salary' => 2000, 'is_active' => true]);
    }

    public function test_expense_as_employee_advance_records_ledger_entry(): void
    {
        $this->actingAs($this->admin());
        $this->openShift();
        $employee = $this->makeEmployee();

        $this->postJson(route('admin.pos.shift.expense'), [
            'category' => 'أخرى', 'amount' => 150, 'note' => 'سلفة نقدية', 'employee_id' => $employee->id,
        ])->assertOk();

        // قيد سلفة (سالب) في دفتر حساب الموظف.
        $this->assertDatabaseHas('employee_ledger_entries', [
            'employee_id' => $employee->id, 'type' => 'advance', 'amount' => -150, 'source_type' => 'pos_expense',
        ]);
        $this->assertSame(-150.0, app(EmployeeLedgerService::class)->summary($employee)['balance']);
    }

    public function test_credit_sale_to_employee_records_purchase_entry(): void
    {
        $this->actingAs($this->admin());
        $this->openShift();
        $employee = $this->makeEmployee();

        $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'credit',
            'customer_id' => $employee->customer_id,
        ])->assertOk();

        // قيد مشتريات (سالب) بقيمة الفاتورة على دفتر الموظف.
        $this->assertDatabaseHas('employee_ledger_entries', [
            'employee_id' => $employee->id, 'type' => 'purchase', 'amount' => -40, 'source_type' => 'pos_order',
        ]);
    }

    public function test_regular_expense_does_not_touch_ledger(): void
    {
        $this->actingAs($this->admin());
        $this->openShift();

        $this->postJson(route('admin.pos.shift.expense'), ['category' => 'كهرباء', 'amount' => 50])->assertOk();

        $this->assertDatabaseCount('employee_ledger_entries', 0);
    }
}
