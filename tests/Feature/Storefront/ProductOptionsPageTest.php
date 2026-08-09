<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Store\Services\StorefrontService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductOptionsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array{0: Product, 1: ProductAttributeValue, 2: ProductAttributeValue} */
    private function variableProduct(float $mStock = 0): array
    {
        $product = Product::factory()->active()->create(['retail_price' => 100, 'visibility' => 'visible', 'name' => 'قميص']);
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $s = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'S', 'label' => 'S', 'sort_order' => 0]);
        $m = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'M', 'label' => 'M', 'sort_order' => 1]);

        app(ProductVariantService::class)->syncMatrix($product, [$attr->id => [$s->id, $m->id]], [
            ['value_ids' => [$s->id], 'retail_price' => 120, 'quantity' => 8],
            ['value_ids' => [$m->id], 'retail_price' => 120, 'quantity' => $mStock],
        ]);

        return [$product->fresh(), $s, $m];
    }

    public function test_variable_product_page_renders_picker_without_dash_bug(): void
    {
        [$product, $s, $m] = $this->variableProduct(mStock: 4);

        $res = $this->get("/p/{$product->slug}")->assertOk();
        $res->assertSee('المقاس');                        // اسم المحور
        $res->assertSee('x-data="productPage(', false);   // تهيئة المحدّد
        $res->assertSee('S، M');                          // قيم حقيقية في المواصفات بدل خطأ «—»

        // خريطة المتغيّرات تحمل معرّفات الشراء لكل تركيبة.
        $sUuid = $product->variants()->where('name', 'S')->value('uuid');
        $mUuid = $product->variants()->where('name', 'M')->value('uuid');
        $res->assertSee($sUuid);
        $res->assertSee($mUuid);
    }

    public function test_variant_map_reflects_per_variant_stock(): void
    {
        [$product, $s, $m] = $this->variableProduct(mStock: 0); // M غير متوفّر

        $map = app(StorefrontService::class)->variantMap($product);
        $sKey = (string) $s->id;
        $mKey = (string) $m->id;

        $this->assertTrue($map[$sKey]['in_stock']);
        $this->assertFalse($map[$mKey]['in_stock']);
        $this->assertEqualsWithDelta(120, $map[$sKey]['price'], 0.01);
    }

    public function test_simple_product_page_unchanged(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 50, 'visibility' => 'visible', 'name' => 'كوب']);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 50]);
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $res = $this->get("/p/{$product->slug}")->assertOk();
        $res->assertDontSee('x-data="productPage(', false);        // لا محدّد للبسيط
        $res->assertSee($variant->uuid);                           // زر الإضافة بالمتغيّر الافتراضي
    }
}
