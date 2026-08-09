<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->first();
    }

    /** @return array{0: ProductAttribute, 1: ProductAttributeValue, 2: ProductAttributeValue} */
    private function sizeAttr(): array
    {
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $s = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'S', 'label' => 'S', 'sort_order' => 0]);
        $m = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'M', 'label' => 'M', 'sort_order' => 1]);

        return [$attr, $s, $m];
    }

    private function fields(Product $p, array $o = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'قميص',
            'sku' => $p->sku,
            'status' => 'active',
        ], $o);
    }

    public function test_saving_matrix_generates_variants_and_stock(): void
    {
        $product = Product::factory()->create(['retail_price' => 100]);
        [$size, $s, $m] = $this->sizeAttr();

        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields($product, [
            'axes' => [$size->id => [$s->id, $m->id]],
            'variants' => [
                ['value_ids' => [$s->id], 'retail_price' => '120', 'quantity' => '5', 'is_active' => '1'],
                ['value_ids' => [$m->id], 'retail_price' => '130', 'quantity' => '3', 'is_active' => '1'],
            ],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $active = $product->variants()->where('is_active', true)->get();
        $this->assertCount(2, $active);
        $this->assertEqualsCanonicalizing(['S', 'M'], $active->pluck('name')->all());
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());

        $sVariant = $product->variants()->where('name', 'S')->first();
        $this->assertEqualsWithDelta(120, (float) $sVariant->retail_price, 0.01);

        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        $this->assertEqualsWithDelta(5, (float) InventoryStock::where('variant_id', $sVariant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand'), 0.01);
        // المخزون مرّ عبر InventoryService (حركة مخزنية مسجّلة).
        $this->assertTrue(InventoryMovement::where('variant_id', $sVariant->id)->exists());
    }

    public function test_editing_variant_price_persists_and_deactivation_hides(): void
    {
        $product = Product::factory()->create(['retail_price' => 100]);
        [$size, $s, $m] = $this->sizeAttr();

        // توليد أولي
        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields($product, [
            'axes' => [$size->id => [$s->id, $m->id]],
            'variants' => [
                ['value_ids' => [$s->id], 'retail_price' => '120', 'quantity' => '5', 'is_active' => '1'],
                ['value_ids' => [$m->id], 'retail_price' => '120', 'quantity' => '5', 'is_active' => '1'],
            ],
        ]))->assertSessionHasNoErrors();

        // تعديل سعر S وتعطيل M
        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields($product, [
            'axes' => [$size->id => [$s->id, $m->id]],
            'variants' => [
                ['value_ids' => [$s->id], 'retail_price' => '99', 'quantity' => '5', 'is_active' => '1'],
                ['value_ids' => [$m->id], 'retail_price' => '120', 'quantity' => '5', 'is_active' => '0'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(99, (float) $product->variants()->where('name', 'S')->value('retail_price'), 0.01);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id, 'name' => 'M', 'is_active' => false, 'deleted_at' => null,
        ]);
    }

    public function test_requires_update_permission(): void
    {
        $product = Product::factory()->create();
        [$size, $s, $m] = $this->sizeAttr();
        $user = User::factory()->create(); // بلا صلاحيات

        $this->actingAs($user)->put('/admin/products/'.$product->uuid, $this->fields($product, [
            'axes' => [$size->id => [$s->id]],
            'variants' => [['value_ids' => [$s->id], 'retail_price' => '50']],
        ]))->assertForbidden();
    }
}
