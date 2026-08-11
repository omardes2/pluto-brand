<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
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
        $res->assertViewHas('totalSales', fn ($v) => abs($v - 150) < 0.01);   // 300 − 150
        $res->assertViewHas('totalQty', fn ($v) => abs($v - 1) < 0.01);       // 2 − 1
    }

    public function test_sales_by_customer_deducts_returns_from_cash(): void
    {
        $res = $this->get(route('admin.reports.sales.by_customer'))->assertOk();
        $res->assertViewHas('totalReturns', fn ($v) => abs($v - 150) < 0.01);
        $res->assertViewHas('totalSales', fn ($v) => abs($v - 150) < 0.01);   // 300 − 150
    }
}
