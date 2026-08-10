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

    private function openShift(): PosShift
    {
        return app(PosShiftService::class)->open(User::where('email', 'admin@pluto-brand.com')->first(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->first()->id,
            'opening_float' => 0,
        ]);
    }

    public function test_items_sold_aggregates_qty_revenue_and_profit(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'card',
        ]);

        $today = now()->toDateString();
        $data = app(PosReportService::class)->itemsSold($today, $today);

        $this->assertCount(1, $data['rows']);
        $this->assertEquals(5.0, $data['rows'][0]['qty']);       // 3 + 2
        $this->assertEquals(100.0, $data['rows'][0]['revenue']);  // 5 × 20
        $this->assertEquals(50.0, $data['rows'][0]['cost']);      // 5 × 10 (WAC)
        $this->assertEquals(50.0, $data['rows'][0]['profit']);
        $this->assertEquals(5.0, $data['totals']['qty']);
        $this->assertEquals(100.0, $data['totals']['revenue']);
        $this->assertEquals(50.0, $data['totals']['profit']);
    }

    public function test_items_sold_falls_back_to_cost_price_when_wac_and_snapshot_zero(): void
    {
        // متغيّر أُدخل مخزونه بلا تكلفة (WAC = 0) لكن له سعر شراء 36 (سيناريو المصفوفة قديمًا).
        $variant = Product::factory()->create(['cost_price' => 36])->defaultVariant;
        $variant->update(['cost_price' => 36, 'wholesale_price' => 0]);
        app(InventoryService::class)->adjustIn($variant, $this->warehouse, 10, null); // WAC يبقى 0

        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 80]],
            'payment_method' => 'cash',
        ]);

        $today = now()->toDateString();
        $data = app(PosReportService::class)->itemsSold($today, $today);
        $row = collect($data['rows'])->firstWhere('sku', $variant->sku);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(36.0, $row['cost'], 0.01);   // تراجُع لسعر الشراء
        $this->assertEqualsWithDelta(44.0, $row['profit'], 0.01); // 80 − 36
    }

    public function test_items_sold_falls_back_to_product_cost_when_variant_cost_is_zero(): void
    {
        // متغيّر قديم: سعر شرائه 0 (العمود غير قابل للـnull)، لكن المنتج له سعر شراء 36.
        $product = Product::factory()->create(['cost_price' => 36]);
        $variant = $product->defaultVariant;
        $variant->update(['cost_price' => 0, 'wholesale_price' => 0]);
        app(InventoryService::class)->adjustIn($variant, $this->warehouse, 10, null); // WAC = 0

        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 80]],
            'payment_method' => 'cash',
        ]);

        $data = app(PosReportService::class)->itemsSold(now()->toDateString(), now()->toDateString());
        $row = collect($data['rows'])->firstWhere('sku', $variant->sku);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(36.0, $row['cost'], 0.01);   // تراجُع لسعر شراء المنتج
        $this->assertEqualsWithDelta(44.0, $row['profit'], 0.01); // 80 − 36
    }

    public function test_shift_detail_subtracts_line_discount_from_revenue(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 45, 'discount' => 20]],
            'payment_method' => 'cash',
        ]);

        $detail = app(PosReportService::class)->shiftDetail($shift->fresh());

        $this->assertEqualsWithDelta(25.0, $detail['items'][0]['revenue'], 0.01); // 45 − 20 خصم
        $this->assertEqualsWithDelta(25.0, $detail['totals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(10.0, $detail['totals']['cost'], 0.01);      // 1 × 10 WAC
        $this->assertEqualsWithDelta(15.0, $detail['totals']['profit'], 0.01);    // 25 − 10
    }

    public function test_shift_detail_subtracts_refunds_from_net_sales_and_profit(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 5, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
        // استرداد نقدي 60 من الدرج (إرجاع)
        app(PosShiftService::class)->recordRefund($shift->fresh(), null, 60, 'إرجاع');

        $detail = app(PosReportService::class)->shiftDetail($shift->fresh());

        $this->assertEqualsWithDelta(100.0, $detail['totals']['revenue'], 0.01);   // 5 × 20
        $this->assertEqualsWithDelta(60.0, $detail['totals']['returns'], 0.01);
        $this->assertEqualsWithDelta(40.0, $detail['totals']['net_sales'], 0.01);  // 100 − 60
        $this->assertEqualsWithDelta(-10.0, $detail['totals']['profit'], 0.01);    // 40 − 50 تكلفة
    }

    public function test_cashier_sales_group_by_cashier_with_payment_split(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift->fresh(), [ // نقدي 40
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
        app(PosSaleService::class)->sell($shift->fresh(), [ // بطاقة 20
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'card',
        ]);

        $today = now()->toDateString();
        $data = app(PosReportService::class)->cashierSales($today, $today);

        $this->assertCount(1, $data['rows']);
        $this->assertSame(2, $data['rows'][0]['orders']);
        $this->assertEquals(40.0, $data['rows'][0]['cash']);
        $this->assertEquals(20.0, $data['rows'][0]['card']);
        $this->assertEquals(60.0, $data['rows'][0]['total']);
        $this->assertEquals(60.0, $data['totals']['total']);
        $this->assertSame(2, $data['totals']['orders']);
    }

    public function test_shift_detail_computes_items_sold_and_profit(): void
    {
        $shift = $this->openShift();
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
