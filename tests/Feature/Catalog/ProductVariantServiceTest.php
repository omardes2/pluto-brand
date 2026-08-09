<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProductVariantService
    {
        return app(ProductVariantService::class);
    }

    /** @return array{0: ProductAttribute, 1: array<int, ProductAttributeValue>} */
    private function attr(string $name, array $values, int $sort = 0): array
    {
        $attribute = ProductAttribute::factory()->create(['name' => $name, 'sort_order' => $sort]);
        $vals = [];
        foreach (array_values($values) as $i => $v) {
            $vals[] = ProductAttributeValue::factory()->create([
                'attribute_id' => $attribute->id, 'value' => $v, 'label' => $v, 'sort_order' => $i,
            ]);
        }

        return [$attribute, $vals];
    }

    public function test_cartesian_generates_all_combinations(): void
    {
        $product = Product::factory()->create(['retail_price' => 100]);
        [$size, $sizes] = $this->attr('المقاس', ['S', 'M'], 1);
        [$color, $colors] = $this->attr('اللون', ['أسود', 'أبيض'], 2);

        $this->service()->syncMatrix($product, [
            $size->id => collect($sizes)->pluck('id')->all(),
            $color->id => collect($colors)->pluck('id')->all(),
        ]);

        $active = $product->variants()->where('is_active', true)->get();
        $this->assertCount(4, $active);                       // 2 × 2
        $this->assertEqualsCanonicalizing(
            ['S / أسود', 'S / أبيض', 'M / أسود', 'M / أبيض'],
            $active->pluck('name')->all()
        );
        // كل متغيّر يرث سعر المنتج، وله متغيّر افتراضي واحد فقط.
        $this->assertTrue($active->every(fn ($v) => (float) $v->retail_price === 100.0));
        $this->assertSame(1, $product->variants()->where('is_default', true)->where('is_active', true)->count());
    }

    public function test_resync_is_idempotent(): void
    {
        $product = Product::factory()->create(['retail_price' => 50]);
        [$size, $sizes] = $this->attr('المقاس', ['S', 'M']);
        $axes = [$size->id => collect($sizes)->pluck('id')->all()];

        $this->service()->syncMatrix($product, $axes);
        $firstIds = $product->variants()->where('is_active', true)->pluck('id')->sort()->values()->all();

        $this->service()->syncMatrix($product, $axes);
        $secondIds = $product->variants()->where('is_active', true)->pluck('id')->sort()->values()->all();

        $this->assertSame($firstIds, $secondIds);            // لا تكرار ولا استبدال
        $this->assertCount(2, $secondIds);
    }

    public function test_adding_value_adds_variant_without_touching_existing(): void
    {
        $product = Product::factory()->create();
        [$size, $sizes] = $this->attr('المقاس', ['S', 'M']);
        $this->service()->syncMatrix($product, [$size->id => [$sizes[0]->id, $sizes[1]->id]]);
        $sVariantId = $product->variants()->where('name', 'S')->value('id');

        $l = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => 'L', 'label' => 'L', 'sort_order' => 2]);
        $this->service()->syncMatrix($product, [$size->id => [$sizes[0]->id, $sizes[1]->id, $l->id]]);

        $this->assertSame(3, $product->variants()->where('is_active', true)->count());
        $this->assertSame($sVariantId, $product->fresh()->variants()->where('name', 'S')->value('id')); // نفس المتغيّر
    }

    public function test_removing_value_deactivates_not_deletes(): void
    {
        $product = Product::factory()->create();
        [$size, $sizes] = $this->attr('المقاس', ['S', 'M', 'L']);
        $ids = collect($sizes)->pluck('id')->all();
        $this->service()->syncMatrix($product, [$size->id => $ids]);
        $lId = $product->variants()->where('name', 'L')->value('id');

        // إزالة L
        $this->service()->syncMatrix($product, [$size->id => [$ids[0], $ids[1]]]);

        $this->assertDatabaseHas('product_variants', ['id' => $lId, 'is_active' => false, 'deleted_at' => null]);
        $this->assertSame(2, $product->variants()->where('is_active', true)->count());
    }

    public function test_legacy_placeholder_default_is_retired(): void
    {
        $product = Product::factory()->create();
        // المتغيّر الافتراضي (placeholder) موجود من المصنع، بلا خيارات ولا مخزون.
        $this->assertSame(1, $product->variants()->count());

        [$size, $sizes] = $this->attr('المقاس', ['S', 'M']);
        $this->service()->syncMatrix($product, [$size->id => collect($sizes)->pluck('id')->all()]);

        // لا يبقى متغيّر بلا قيم خيارات (تمّ حذف الـ placeholder غير المرجعي).
        $withoutOptions = $product->fresh()->variants()->with('attributeValues')->get()
            ->filter(fn ($v) => $v->attributeValues->isEmpty());
        $this->assertCount(0, $withoutOptions);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }

    public function test_set_variant_stock_goes_through_inventory_service(): void
    {
        $product = Product::factory()->create();
        [$size, $sizes] = $this->attr('المقاس', ['S']);
        $this->service()->syncMatrix($product, [$size->id => [$sizes[0]->id]]);
        $variant = $product->variants()->where('is_active', true)->first();
        $warehouse = Warehouse::factory()->create();

        $this->service()->setVariantStock($variant, $warehouse, 12, 5.0);

        $this->assertEquals(12, (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand'));
        $this->assertSame(1, InventoryMovement::where('variant_id', $variant->id)->count());
    }
}
