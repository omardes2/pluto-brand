<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLedgerEntry;
use App\Modules\Hr\Services\EmployeeLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeLedgerTest extends TestCase
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

    public function test_cumulative_balance_and_debt(): void
    {
        $employee = Employee::factory()->create(['monthly_salary' => 2000]);
        $ledger = app(EmployeeLedgerService::class);

        $ledger->record($employee, EmployeeLedgerEntry::TYPE_SALARY_ACCRUAL, 2000);
        $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 500);
        $ledger->record($employee, EmployeeLedgerEntry::TYPE_PURCHASE, 300);

        $s = $ledger->summary($employee);
        $this->assertSame(2000.0, $s['accrued']);
        $this->assertSame(500.0, $s['advances']);
        $this->assertSame(300.0, $s['purchases']);
        $this->assertSame(1200.0, $s['balance']);          // 2000 − 500 − 300
        $this->assertSame(1200.0, $s['due_to_employee']);
        $this->assertSame(0.0, $s['debt']);

        // سحب يتجاوز الرصيد → دين.
        $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 1500);
        $s = $ledger->summary($employee);
        $this->assertSame(-300.0, $s['balance']);
        $this->assertSame(300.0, $s['debt']);
        $this->assertSame(0.0, $s['due_to_employee']);
    }

    public function test_signs_are_applied_by_type(): void
    {
        $employee = Employee::factory()->create();
        $ledger = app(EmployeeLedgerService::class);

        $accrual = $ledger->record($employee, EmployeeLedgerEntry::TYPE_SALARY_ACCRUAL, 1000);
        $advance = $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 400);
        $adjustUp = $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADJUSTMENT, 50, direction: 'credit');

        $this->assertSame('1000.00', $accrual->amount);
        $this->assertSame('-400.00', $advance->amount);
        $this->assertSame('50.00', $adjustUp->amount);
    }

    public function test_show_page_and_manual_entry(): void
    {
        $employee = Employee::factory()->create(['monthly_salary' => 1500]);

        $this->actingAs($this->admin())
            ->get(route('admin.employees.show', $employee))
            ->assertOk()->assertSee('كشف حساب الموظف');

        $this->actingAs($this->admin())
            ->post(route('admin.employees.entries.store', $employee), [
                'type' => 'advance', 'amount' => 200, 'note' => 'سلفة نقدية',
            ])->assertRedirect();

        $this->assertDatabaseHas('employee_ledger_entries', [
            'employee_id' => $employee->id, 'type' => 'advance', 'amount' => -200,
        ]);
    }

    public function test_ledger_requires_permission(): void
    {
        $employee = Employee::factory()->create();
        $this->actingAs(User::factory()->create())
            ->post(route('admin.employees.entries.store', $employee), ['type' => 'advance', 'amount' => 100])
            ->assertForbidden();
    }

    public function test_update_entry_recomputes_signed_amount(): void
    {
        $employee = Employee::factory()->create();
        $ledger = app(EmployeeLedgerService::class);
        $entry = $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 500); // -500

        $this->actingAs($this->admin())
            ->put(route('admin.employees.entries.update', [$employee, $entry]), [
                'type' => 'advance', 'amount' => 300, 'entry_date' => '2026-08-15', 'note' => 'تصحيح',
            ])->assertRedirect();

        $this->assertDatabaseHas('employee_ledger_entries', [
            'id' => $entry->id, 'type' => 'advance', 'amount' => -300, 'note' => 'تصحيح',
        ]);
        $this->assertSame(-300.0, $ledger->summary($employee->fresh())['balance']);
    }

    public function test_delete_entry_updates_balance(): void
    {
        $employee = Employee::factory()->create();
        $ledger = app(EmployeeLedgerService::class);
        $ledger->record($employee, EmployeeLedgerEntry::TYPE_SALARY_ACCRUAL, 1000);
        $advance = $ledger->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 400);

        $this->actingAs($this->admin())
            ->delete(route('admin.employees.entries.destroy', [$employee, $advance]))
            ->assertRedirect();

        $this->assertDatabaseMissing('employee_ledger_entries', ['id' => $advance->id]);
        $this->assertSame(1000.0, $ledger->summary($employee->fresh())['balance']); // بقي الاستحقاق فقط
    }

    public function test_cannot_edit_entry_of_another_employee(): void
    {
        $ledger = app(EmployeeLedgerService::class);
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $entry = $ledger->record($b, EmployeeLedgerEntry::TYPE_ADVANCE, 100); // يخصّ b

        // مسار a مع قيد b → 404.
        $this->actingAs($this->admin())
            ->put(route('admin.employees.entries.update', [$a, $entry]), ['type' => 'advance', 'amount' => 50])
            ->assertNotFound();
    }

    public function test_update_entry_requires_permission(): void
    {
        $employee = Employee::factory()->create();
        $entry = app(EmployeeLedgerService::class)->record($employee, EmployeeLedgerEntry::TYPE_ADVANCE, 100);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.employees.entries.update', [$employee, $entry]), ['type' => 'advance', 'amount' => 50])
            ->assertForbidden();
    }
}
