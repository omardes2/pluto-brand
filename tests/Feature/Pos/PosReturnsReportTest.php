<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosReturnLine;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosReportService;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReturnsReportTest extends TestCase
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
        // WAC = 60
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 10, 60);
        $this->actingAs(User::where('email', 'admin@pluto-brand.com')->firstOrFail());
    }

    private function openShift(): PosShift
    {
        return app(PosShiftService::class)->open(
            User::where('email', 'admin@pluto-brand.com')->firstOrFail(),
            ['warehouse_id' => $this->warehouse->id, 'opening_float' => 100],
        );
    }

    public function test_no_invoice_return_deducts_sales_and_cost_so_profit_is_zero(): void
    {
        $shift = $this->openShift();

        // بيع بسعر 150 (تكلفة 60 ⇒ ربح 90).
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);

        // إرجاع بدون فاتورة بنفس السعر 150.
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150],
        ]);

        $detail = app(PosReportService::class)->shiftDetail($shift->fresh());
        $t = $detail['totals'];

        $this->assertSame(150.0, $t['revenue']);       // إجمالي المبيعات
        $this->assertSame(150.0, $t['returns']);       // مبيعات المرتجعات
        $this->assertSame(60.0, $t['returns_cost']);   // تكلفة المرتجعات تُعكَس
        $this->assertSame(0.0, $t['net_sales']);       // صافي المبيعات
        $this->assertSame(0.0, $t['net_cost']);        // صافي التكلفة
        $this->assertSame(0.0, $t['profit']);          // ربح الوردية = 0 (كان -60)
    }

    public function test_backfill_command_recreates_missing_return_lines_with_wac_cost(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150],
        ]);

        // محاكاة بيانات قديمة قبل الجدول: حذف بنود المرتجع (تبقى حركة المخزون).
        PosReturnLine::query()->delete();
        // بلا بنود مرتجع يظهر الربح كاملًا (خطأ) 90.
        $this->assertSame(90.0, app(PosReportService::class)->shiftDetail($shift->fresh())['totals']['profit']);

        $this->artisan('pos:backfill-noinvoice-returns', ['--force' => true])->assertOk();

        $line = PosReturnLine::firstOrFail();
        $this->assertEquals(150, (float) $line->unit_price);
        $this->assertEquals(60, (float) $line->unit_cost);   // WAC (حركة المخزون بلا تكلفة)
        $this->assertSame(0.0, app(PosReportService::class)->shiftDetail($shift->fresh())['totals']['profit']);
    }

    public function test_no_invoice_return_uses_cost_price_when_wac_is_zero(): void
    {
        // صنف تكلفته في cost_price لا في WAC (average_cost=0) — كحالة الإنتاج.
        $variant = Product::factory()->create()->defaultVariant;
        $variant->update(['wholesale_price' => 0]);
        Product::whereKey($variant->product_id)->update(['is_active' => true, 'status' => 'active']);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 0); // WAC = 0
        $variant->update(['cost_price' => 60]);

        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 150],
        ]);

        $line = PosReturnLine::where('variant_id', $variant->id)->firstOrFail();
        $this->assertEquals(60, (float) $line->unit_cost); // تراجُع لـ cost_price

        $t = app(PosReportService::class)->shiftDetail($shift->fresh())['totals'];
        $this->assertSame(60.0, $t['returns_cost']);
        $this->assertSame(0.0, $t['profit']); // كان -60
    }

    public function test_items_report_nets_no_invoice_returns(): void
    {
        $shift = $this->openShift();
        app(PosSaleService::class)->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 150]],
            'payment_method' => 'cash',
        ]);
        app(PosReturnService::class)->refundWithoutInvoice($shift, [
            ['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 150],
        ]);

        $today = now()->toDateString();
        $report = app(PosReportService::class)->itemsSold($today, $today);

        $this->assertSame(1.0, $report['totals']['qty']);       // 2 مباع − 1 مرتجع
        $this->assertSame(150.0, $report['totals']['revenue']); // 300 − 150
        $this->assertSame(150.0, $report['totals']['returns']);
        $this->assertSame(60.0, $report['totals']['cost']);     // 120 − 60
        $this->assertSame(90.0, $report['totals']['profit']);
    }
}
