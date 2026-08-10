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
        $product = Product::factory()->active()->create(['name' => 'قميص باركود']);
        $product->defaultVariant->update(['barcode' => 'BC-98055']);

        $this->actingAs($this->admin())
            ->get(route('admin.pos.barcodes'))
            ->assertOk()
            ->assertSee('طباعة باركود')
            ->assertSee('قميص باركود')
            ->assertSee('BC-98055')
            ->assertSee('<svg', false); // شكل الباركود مرسوم SVG
    }

    public function test_barcode_items_use_effective_code_and_exclude_inactive(): void
    {
        $withBarcode = Product::factory()->active()->create(['name' => 'له باركود']);
        $withBarcode->defaultVariant->update(['barcode' => 'HAS-1']);

        // بلا باركود على المتغيّر أو المنتج → يتراجع للـSKU.
        $noBarcode = Product::factory()->active()->create(['name' => 'بلا باركود']);
        $noBarcode->defaultVariant->update(['barcode' => null]);
        $noBarcode->update(['barcode' => null]);
        $sku = $noBarcode->defaultVariant->sku;

        $inactive = Product::factory()->active()->create(['name' => 'مُعطّل']);
        $inactive->defaultVariant->update(['is_active' => false]);

        $items = collect(app(PosCatalogService::class)->barcodeItems());
        $codes = $items->pluck('barcode');

        $this->assertTrue($codes->contains('HAS-1'));                 // باركود المتغيّر
        $this->assertTrue($codes->contains($sku));                    // تراجُع للـSKU
        $this->assertFalse($items->pluck('product')->contains('مُعطّل')); // المُعطّل مُستبعَد
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
