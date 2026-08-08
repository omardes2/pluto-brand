<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosShiftServiceTest extends TestCase
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
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
        $this->actingAs($this->admin());
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    private function shifts(): PosShiftService
    {
        return app(PosShiftService::class);
    }

    private function openShift(float $opening = 100): PosShift
    {
        return $this->shifts()->open($this->admin(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'opening_float' => $opening,
        ]);
    }

    private function sellCash(PosShift $shift, float $qty, float $price): void
    {
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_price' => $price]],
            'payment_method' => 'cash',
        ]);
    }

    public function test_open_creates_shift_with_number_and_opening_movement(): void
    {
        $shift = $this->openShift(150);

        $this->assertTrue($shift->isOpen());
        $this->assertStringStartsWith('SHIFT-', $shift->number);
        $this->assertEquals(150.0, (float) $shift->opening_float);
        $this->assertEquals(150.0, (float) $shift->expected_cash);

        $opening = $shift->movements()->where('type', PosShiftMovement::TYPE_OPENING)->first();
        $this->assertNotNull($opening);
        $this->assertEquals(150.0, (float) $opening->amount);
    }

    public function test_cannot_open_two_shifts_for_same_cashier(): void
    {
        $this->openShift(100);

        $this->expectException(ValidationException::class);
        $this->openShift(100);
    }

    public function test_pay_in_and_pay_out_update_expected_cash(): void
    {
        $shift = $this->openShift(100);

        $this->shifts()->addMovement($shift, PosShiftMovement::TYPE_PAY_IN, 50, 'إيداع');
        $this->assertEquals(150.0, (float) $shift->fresh()->expected_cash);

        $this->shifts()->addMovement($shift->fresh(), PosShiftMovement::TYPE_PAY_OUT, 30, 'مصروف');
        $this->assertEquals(120.0, (float) $shift->fresh()->expected_cash);
    }

    public function test_cannot_pay_out_more_than_drawer(): void
    {
        $shift = $this->openShift(100);

        $this->expectException(ValidationException::class);
        $this->shifts()->addMovement($shift, PosShiftMovement::TYPE_PAY_OUT, 200);
    }

    public function test_close_computes_variance_against_counted_cash(): void
    {
        $shift = $this->openShift(100);
        $this->sellCash($shift, 5, 20); // +100 نقدي ⇒ المتوقّع 200

        $closed = $this->shifts()->close($shift->fresh(), 190, 'عجز بسيط');

        $this->assertSame(PosShift::STATUS_CLOSED, $closed->status);
        $this->assertEquals(200.0, (float) $closed->expected_cash);
        $this->assertEquals(190.0, (float) $closed->counted_cash);
        $this->assertEquals(-10.0, (float) $closed->variance);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_full_cycle_open_sell_payout_close_balances(): void
    {
        $shift = $this->openShift(100);           // درج 100
        $this->sellCash($shift, 5, 20);           // +100 ⇒ 200
        $this->shifts()->addMovement($shift->fresh(), PosShiftMovement::TYPE_PAY_OUT, 50); // −50 ⇒ 150

        $closed = $this->shifts()->close($shift->fresh(), 150);

        $this->assertEquals(150.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
        // البطاقة لم تُستخدم، والمبيعات النقدية مسجّلة.
        $this->assertEquals(100.0, (float) $closed->cash_sales);
        $this->assertSame(1, $closed->orders_count);
    }

    public function test_expense_reduces_expected_cash_and_records_category(): void
    {
        $shift = $this->openShift(100);
        $this->sellCash($shift, 5, 20); // درج 200

        $movement = $this->shifts()->addExpense($shift->fresh(), 'كهرباء', 30, 'فاتورة الشهر');

        $this->assertSame(PosShiftMovement::TYPE_PAY_OUT, $movement->type);
        $this->assertSame('كهرباء', $movement->category);
        $this->assertEquals(170.0, (float) $shift->fresh()->expected_cash); // 200 − 30
    }

    public function test_expense_posts_voucher_that_credits_the_treasury(): void
    {
        $shift = $this->openShift(0);
        $this->sellCash($shift, 5, 20); // بيع نقدي 100 ⇒ سند قبض يزيد الصندوق (+100)

        $this->shifts()->addExpense($shift->fresh(), 'كهرباء', 30, 'فاتورة');

        $treasury = $shift->fresh()->treasury;

        // سند مصروف مُرحّل على نفس صندوق الوردية بمقدار المصروف.
        $voucher = FinancialVoucher::where('kind', 'expense')
            ->where('reference', $shift->number)->latest('id')->firstOrFail();
        $this->assertSame('posted', $voucher->status);
        $this->assertEquals(30.0, (float) $voucher->amount);
        $this->assertSame($treasury->id, $voucher->treasury_id);
        $this->assertNotNull($voucher->journal_entry_id);

        // القيد: دائن حساب الصندوق 30 (خصم فعلي) + مدين حساب مصروفات نقطة البيع (5010) 30.
        $lines = $voucher->journalEntry->lines;
        $treasuryLine = $lines->firstWhere('account_id', $treasury->gl_account_id);
        $this->assertNotNull($treasuryLine);
        $this->assertEquals(30.0, (float) $treasuryLine->credit);

        $expenseAccountId = Account::where('code', '5010')->value('id');
        $expenseLine = $lines->firstWhere('account_id', $expenseAccountId);
        $this->assertNotNull($expenseLine);
        $this->assertEquals(30.0, (float) $expenseLine->debit);
    }
}
