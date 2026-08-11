<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportReturnsTest extends TestCase
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
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 10, 60);

        $admin = User::where('email', 'admin@pluto-brand.com')->firstOrFail();
        $this->actingAs($admin);

        // بيع 2 × 150 ثم إرجاع بدون فاتورة 1 × 150.
        $shift = app(PosShiftService::class)->open($admin, ['warehouse_id' => $this->warehouse->id, 'opening_float' => 100]);
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150],
        ]);
    }

    public function test_sales_by_product_deducts_returns(): void
    {
        $res = $this->get(route('admin.reports.sales.by_product'))->assertOk();
        $res->assertViewHas('totalReturns', fn ($v) => abs($v - 150) < 0.01);
        $res->assertViewHas('totalSales', fn ($v) => abs($v - 150) < 0.01);   // 300 − 150 (صافي)
        $res->assertViewHas('totalQty', fn ($v) => abs($v - 1) < 0.01);       // 2 مباع − 1 مرتجع (صافي)
        $res->assertViewHas('totalProfit', fn ($v) => abs($v - 90) < 0.01);   // (300−150) − (120−60)
    }

    public function test_sales_by_product_profit_uses_product_cost_price_fallback(): void
    {
        // تكلفة الصنف في products.cost_price فقط (WAC وتكلفة المتغيّر صفر) — كحالة الإنتاج
        // التي أنتجت ربحًا خاطئًا موجبًا (60) لأن تكلفة البيع لم تتراجع لتكلفة المنتج.
        $v = Product::factory()->create(['name' => 'صنف تكلفة المنتج'])->defaultVariant;
        $v->update(['wholesale_price' => 0, 'cost_price' => 0]);
        Product::whereKey($v->product_id)->update(['is_active' => true, 'status' => 'active', 'cost_price' => 60]);
        app(InventoryService::class)->receive($v, $this->warehouse, 5, 0); // WAC = 0

        $shift = PosShift::where('status', 'open')->latest('id')->firstOrFail();
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $v->id, 'qty' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $v->id, 'qty' => 1, 'unit_price' => 150],
        ]);

        $res = $this->get(route('admin.reports.sales.by_product'))->assertOk();
        $row = collect($res->viewData('rows'))->firstWhere('product', 'صنف تكلفة المنتج');
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0, $row['qty'], 0.01);        // 1 مباع − 1 مرتجع (صافي)
        $this->assertEqualsWithDelta(150, $row['returns'], 0.01);  // مرتجع 150
        $this->assertEqualsWithDelta(0, $row['sale_total'], 0.01); // صافي 0
        $this->assertEqualsWithDelta(0, $row['profit'], 0.01);     // كان 60 خطأً
    }

    public function test_sales_by_customer_deducts_returns_from_cash(): void
    {
        $res = $this->get(route('admin.reports.sales.by_customer'))->assertOk();
        $res->assertViewHas('totalReturns', fn ($v) => abs($v - 150) < 0.01);
        $res->assertViewHas('totalSales', fn ($v) => abs($v - 150) < 0.01);   // 300 − 150
    }
}
