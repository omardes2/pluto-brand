<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Services\PosReportService;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReportServiceTest extends TestCase
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
        $this->actingAs(User::where('email', 'admin@pluto-brand.com')->firstOrFail());
    }

    public function test_daily_summary_aggregates_cash_card_and_expenses(): void
    {
        $shifts = app(PosShiftService::class);
        $sales = app(PosSaleService::class);

        $shift = $shifts->open(User::where('email', 'admin@pluto-brand.com')->first(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->first()->id,
            'opening_float' => 0,
        ]);

        $sales->sell($shift->fresh(), [ // نقدي 40
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
        $sales->sell($shift->fresh(), [ // بطاقة 20
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'card',
        ]);
        $shifts->addExpense($shift->fresh(), 'غداء', 10);

        $today = now()->toDateString();
        $summary = app(PosReportService::class)->dailySummary($today, $today);

        $t = $summary['totals'];
        $this->assertEquals(40.0, $t['cash']);
        $this->assertEquals(20.0, $t['card']);
        $this->assertEquals(60.0, $t['total_sales']);
        $this->assertEquals(10.0, $t['expenses']);
        $this->assertSame(2, $t['orders']);
        $this->assertEquals(30.0, $t['net']); // 40 نقدي − 10 مصروف

        $this->assertCount(1, $summary['days']);
        $this->assertSame($today, $summary['days'][0]['date']);
    }

    public function test_shift_detail_computes_items_sold_and_profit(): void
    {
        $shift = app(PosShiftService::class)->open(User::where('email', 'admin@pluto-brand.com')->first(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->first()->id,
            'opening_float' => 0,
        ]);
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);

        $detail = app(PosReportService::class)->shiftDetail($shift->fresh());

        $this->assertCount(1, $detail['items']);
        $this->assertEquals(2.0, $detail['items'][0]['qty']);
        $this->assertEquals(40.0, $detail['items'][0]['revenue']);
        $this->assertEquals(20.0, $detail['items'][0]['cost']);   // 2 × 10 (WAC)
        $this->assertEquals(20.0, $detail['items'][0]['profit']);
        $this->assertEquals(20.0, $detail['totals']['profit']);
        $this->assertEquals(40.0, $detail['totals']['net_cash']); // متوقّع 40 − افتتاحي 0
    }
}
