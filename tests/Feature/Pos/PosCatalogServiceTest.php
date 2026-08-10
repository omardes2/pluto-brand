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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_search_excludes_deactivated_variants(): void
    {
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        $product = Product::factory()->active()->create(['name' => 'خشب', 'retail_price' => 100]);
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $s = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => 'S', 'label' => 'S', 'sort_order' => 0]);
        $m = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => 'M', 'label' => 'M', 'sort_order' => 1]);

        $svc = app(ProductVariantService::class);
        // توليد S و M ثم إعادة المزامنة بـ S فقط → يُعطَّل M (لا يُحذف).
        $svc->syncMatrix($product, [$size->id => [$s->id, $m->id]], [
            ['value_ids' => [$s->id], 'retail_price' => 100, 'quantity' => 5],
            ['value_ids' => [$m->id], 'retail_price' => 100, 'quantity' => 5],
        ]);
        $mVariantId = $product->variants()->where('name', 'M')->value('id');
        $svc->syncMatrix($product, [$size->id => [$s->id]], [
            ['value_ids' => [$s->id], 'retail_price' => 100, 'quantity' => 5],
        ]);

        // المتغيّر M أصبح مُعطّلًا لكنه لا يزال موجودًا.
        $this->assertDatabaseHas('product_variants', ['id' => $mVariantId, 'is_active' => false]);

        $results = app(PosCatalogService::class)->search($warehouse->id);
        $ids = collect($results)->pluck('variant_id')->all();

        $sVariantId = $product->variants()->where('name', 'S')->value('id');
        $this->assertContains($sVariantId, $ids);        // النشط يظهر
        $this->assertNotContains($mVariantId, $ids);     // المُعطّل لا يظهر
    }

    public function test_search_excludes_zero_stock_variants(): void
    {
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();

        $inStock = Product::factory()->active()->create(['name' => 'متوفّر']);
        app(InventoryService::class)->receive($inStock->defaultVariant, $warehouse, 3, 10);

        $outOfStock = Product::factory()->active()->create(['name' => 'نافد']); // بلا مخزون

        $ids = collect(app(PosCatalogService::class)->search($warehouse->id))->pluck('variant_id')->all();

        $this->assertContains($inStock->defaultVariant->id, $ids);       // متوفّر → يظهر
        $this->assertNotContains($outOfStock->defaultVariant->id, $ids); // نافد → لا يظهر
    }
}
