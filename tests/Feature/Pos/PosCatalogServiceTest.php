<?php

namespace Tests\Feature\Pos;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Services\PosCatalogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    public function test_search_groups_variants_under_product_and_excludes_deactivated(): void
    {
        $product = Product::factory()->active()->create(['name' => 'خشب', 'retail_price' => 100]);
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $s = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => 'S', 'label' => 'S', 'sort_order' => 0]);
        $m = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => 'M', 'label' => 'M', 'sort_order' => 1]);

        $svc = app(ProductVariantService::class);
        $svc->syncMatrix($product, [$size->id => [$s->id, $m->id]], [
            ['value_ids' => [$s->id], 'retail_price' => 100, 'quantity' => 5],
            ['value_ids' => [$m->id], 'retail_price' => 100, 'quantity' => 5],
        ]);
        $mVariantId = $product->variants()->where('name', 'M')->value('id');
        // إعادة المزامنة بـ S فقط → يُعطَّل M.
        $svc->syncMatrix($product, [$size->id => [$s->id]], [
            ['value_ids' => [$s->id], 'retail_price' => 100, 'quantity' => 5],
        ]);
        $sVariantId = $product->variants()->where('name', 'S')->value('id');

        $row = collect(app(PosCatalogService::class)->search($this->warehouse->id))->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);                         // المنتج بطاقة واحدة
        $variantIds = collect($row['variants'])->pluck('variant_id')->all();
        $this->assertContains($sVariantId, $variantIds);    // النشط ضمن المتغيّرات
        $this->assertNotContains($mVariantId, $variantIds); // المُعطّل مُستبعَد
    }

    public function test_search_excludes_products_with_no_stock(): void
    {
        $inStock = Product::factory()->active()->create(['name' => 'متوفّر']);
        app(InventoryService::class)->receive($inStock->defaultVariant, $this->warehouse, 3, 10);

        $outOfStock = Product::factory()->active()->create(['name' => 'نافد']); // بلا مخزون

        $ids = collect(app(PosCatalogService::class)->search($this->warehouse->id))->pluck('product_id')->all();

        $this->assertContains($inStock->id, $ids);       // متوفّر → يظهر
        $this->assertNotContains($outOfStock->id, $ids); // نافد → لا يظهر
    }

    public function test_product_payload_has_color_first_axis_and_variant_values(): void
    {
        $product = Product::factory()->active()->create(['name' => 'حذاء']);
        $color = ProductAttribute::factory()->create(['name' => 'اللون', 'type' => 'color', 'sort_order' => 1]);
        $black = ProductAttributeValue::factory()->create(['attribute_id' => $color->id, 'value' => 'اسود', 'label' => 'اسود', 'color_hex' => '#000000', 'sort_order' => 0]);
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select', 'sort_order' => 0]);
        $s40 = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => '40', 'label' => '40', 'sort_order' => 0]);
        $s41 = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => '41', 'label' => '41', 'sort_order' => 1]);

        app(ProductVariantService::class)->syncMatrix($product, [$color->id => [$black->id], $size->id => [$s40->id, $s41->id]], [
            ['value_ids' => [$black->id, $s40->id], 'retail_price' => 120, 'quantity' => 5],
            ['value_ids' => [$black->id, $s41->id], 'retail_price' => 120, 'quantity' => 3],
        ]);

        $row = collect(app(PosCatalogService::class)->search($this->warehouse->id))->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);
        $this->assertCount(2, $row['variants']);
        // محور اللون أولًا (يُعرض كقائمة علوية).
        $this->assertSame('اللون', $row['axes'][0]['name']);
        $this->assertTrue($row['axes'][0]['is_color']);
        $this->assertSame('المقاس', $row['axes'][1]['name']);
        // كل متغيّر يحمل قيم محاوره (لبناء المحدّد).
        $v = $row['variants'][0];
        $this->assertArrayHasKey($color->id, $v['values']);
        $this->assertArrayHasKey($size->id, $v['values']);
    }
}
