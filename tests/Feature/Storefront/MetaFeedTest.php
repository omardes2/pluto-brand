<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaFeedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    public function test_meta_feed_emits_one_row_per_variant_with_group_and_attributes(): void
    {
        $color = ProductAttribute::factory()->create(['name' => 'اللون', 'type' => 'color', 'sort_order' => 0]);
        $green = ProductAttributeValue::factory()->create(['attribute_id' => $color->id, 'value' => 'green', 'label' => 'أخضر', 'color_hex' => '#00aa00']);
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'size', 'sort_order' => 1]);
        $s40 = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => '40', 'label' => '40']);

        $product = Product::factory()->active()->create(['name' => 'حذاء رياضي', 'visibility' => 'visible', 'retail_price' => 70]);
        ProductImage::factory()->primary()->create(['product_id' => $product->id, 'path' => 'products/shoe.jpg']);

        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 70, 'name' => 'أخضر / 40', 'sku' => 'SHOE-G-40']);
        $variant->attributeValues()->attach($green->id, ['attribute_id' => $color->id]);
        $variant->attributeValues()->attach($s40->id, ['attribute_id' => $size->id]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 5, 40);

        $res = $this->get('/feed/meta.csv')->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($body))));

        // ترويسة الأعمدة المطلوبة لميتا.
        $this->assertStringContainsString('id,title,description,availability,condition,price,sale_price,link,image_link,additional_image_link,brand,item_group_id,color,size', $lines[0]);

        // صفّ المتغيّر يحمل المعرّف والتجميع والسعر بالعملة واللون/المقاس والتوفّر.
        $this->assertStringContainsString('SHOE-G-40', $body);
        $this->assertStringContainsString($product->uuid, $body);          // item_group_id
        $this->assertStringContainsString('70.00 ILS', $body);
        $this->assertStringContainsString('in stock', $body);
        $this->assertStringContainsString('أخضر', $body);                  // color
        $this->assertStringContainsString(route('storefront.product', $product->slug), $body); // رابط مطلق
        $this->assertStringContainsString('products/shoe.jpg', $body);     // image_link
    }

    public function test_meta_feed_uses_sale_price_when_on_promo(): void
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        ProductImage::factory()->primary()->create(['product_id' => $product->id, 'path' => 'products/p.jpg']);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100, 'promo_price' => 80, 'sku' => 'PROMO-1']);
        app(InventoryService::class)->receive($variant, $this->warehouse, 3, 50);

        $body = $this->get('/feed/meta.csv')->assertOk()->streamedContent();

        // price = الأساسي 100، sale_price = 80.
        $this->assertStringContainsString('100.00 ILS', $body);
        $this->assertStringContainsString('80.00 ILS', $body);
    }

    public function test_meta_feed_skips_products_without_image(): void
    {
        $product = Product::factory()->active()->create(['name' => 'بلا صورة', 'visibility' => 'visible', 'retail_price' => 30]);
        $variant = $product->defaultVariant;
        $variant->update(['sku' => 'NOIMG-1']);
        app(InventoryService::class)->receive($variant, $this->warehouse, 2, 10);

        $body = $this->get('/feed/meta.csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('NOIMG-1', $body); // مُتخطّى (ميتا يتطلّب صورة)
    }
}
