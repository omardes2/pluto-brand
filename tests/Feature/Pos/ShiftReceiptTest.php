<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftReceiptTest extends TestCase
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
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
        $this->actingAs(User::where('email', 'admin@pluto-brand.com')->firstOrFail());
    }

    private function openShift(): PosShift
    {
        return app(PosShiftService::class)->open(User::where('email', 'admin@pluto-brand.com')->first(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->first()->id,
            'opening_float' => 100,
        ]);
    }

    public function test_receipt_renders_for_closed_shift_with_required_fields(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
        $closed = app(PosShiftService::class)->close($shift->fresh(), 140, 'إغلاق تجريبي');

        $res = $this->get(route('admin.pos.shifts.receipt', $closed))->assertOk();
        $res->assertSee('تقرير إغلاق وردية');
        $res->assertSee($closed->number);
        $res->assertSee('مبلغ الافتتاح');
        $res->assertSee('الرصيد المتوقّع');
        $res->assertSee('النقد المعدود فعليًا');
        $res->assertSee('إغلاق تجريبي'); // الملاحظات
    }

    public function test_receipt_auto_prints_when_requested(): void
    {
        $shift = $this->openShift();
        $closed = app(PosShiftService::class)->close($shift->fresh(), 100, null);

        $this->get(route('admin.pos.shifts.receipt', ['shift' => $closed, 'print' => 1]))
            ->assertOk()->assertSee('window.print()', false);
    }

    public function test_closing_with_print_option_redirects_to_receipt(): void
    {
        $shift = $this->openShift();

        $response = $this->post(route('admin.pos.shift.close'), [
            'counted_cash' => 100,
            'print_report' => 1,
        ]);

        $closed = $shift->fresh();
        $this->assertSame('closed', $closed->status);
        $response->assertRedirectContains(route('admin.pos.shifts.receipt', $closed, absolute: false));
    }

    public function test_closing_without_print_option_redirects_to_open_form(): void
    {
        $this->openShift();

        $this->post(route('admin.pos.shift.close'), [
            'counted_cash' => 100,
            'print_report' => 0,
        ])->assertRedirect(route('admin.pos.shift.open_form'));
    }
}
