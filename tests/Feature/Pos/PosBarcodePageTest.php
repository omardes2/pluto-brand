<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Services\PosCatalogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosBarcodePageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    public function test_barcode_page_lists_items_with_number_and_barcode_svg(): void
    {
        $product = Product::factory()->active()->create(['name' => 'قميص باركود', 'barcode' => 'BC-98055']);

        $this->actingAs($this->admin())
            ->get(route('admin.pos.barcodes'))
            ->assertOk()
            ->assertSee('طباعة باركود')
            ->assertSee('قميص باركود')
            ->assertSee('BC-98055')
            ->assertSee('<svg', false); // شكل الباركود مرسوم SVG
    }

    public function test_barcode_items_are_per_product_with_effective_code(): void
    {
        // باركود أصلي على المنتج.
        $withBarcode = Product::factory()->active()->create(['name' => 'له باركود', 'barcode' => 'PROD-1']);

        // بلا باركود على المنتج أو المتغيّر → يتراجع للـSKU الافتراضي.
        $noBarcode = Product::factory()->active()->create(['name' => 'بلا باركود', 'barcode' => null]);
        $noBarcode->defaultVariant->update(['barcode' => null]);
        $sku = $noBarcode->defaultVariant->sku;

        // منتج مُعطّل → مُستبعَد.
        $inactive = Product::factory()->active()->create(['name' => 'منتج مُعطّل']);
        $inactive->update(['is_active' => false]);

        $items = collect(app(PosCatalogService::class)->barcodeItems());

        $this->assertTrue($items->pluck('barcode')->contains('PROD-1'));  // باركود المنتج الأصلي
        $this->assertTrue($items->pluck('barcode')->contains($sku));      // تراجُع للـSKU
        $this->assertFalse($items->pluck('product')->contains('منتج مُعطّل')); // المُعطّل مُستبعَد
        // صفّ واحد لكل منتج (لا تكرار حسب اللون/المقاس).
        $this->assertSame(1, $items->where('product', 'له باركود')->count());
    }

    public function test_barcode_items_include_total_available_stock(): void
    {
        $product = Product::factory()->active()->create(['name' => 'صنف بمخزون', 'barcode' => 'STK-1']);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 7, 10);

        $row = collect(app(PosCatalogService::class)->barcodeItems())->firstWhere('barcode', 'STK-1');

        $this->assertNotNull($row);
        $this->assertSame(7.0, $row['stock']);
    }

    public function test_scanning_a_sku_label_finds_the_variant(): void
    {
        $product = Product::factory()->active()->create();
        $variant = $product->defaultVariant;
        $variant->update(['barcode' => null]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 5, 10);

        $found = app(PosCatalogService::class)->findByBarcode($this->warehouse->id, $variant->sku);

        $this->assertNotNull($found);
        $this->assertSame($variant->id, $found['variant_id']);
    }
}
