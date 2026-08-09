<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة «تعديل صنف» السريعة من المخزن: الكمية المعروضة يجب أن تكون إجمالي كل المتغيّرات،
 * ولأصناف المقاسات/الألوان لا تُعدَّل من هنا (تُدار من كرت الصنف).
 */
class InventoryQuickEditQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->first();
    }

    public function test_shows_aggregate_quantity_for_multi_variant_product(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 60]);
        $default = $product->defaultVariant; // متغيّر افتراضي
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 60]);

        app(InventoryService::class)->receive($default, $this->warehouse, 2, 25);
        app(InventoryService::class)->receive($second, $this->warehouse, 46, 25);
        // الإجمالي = 48 (وليس 2 للمتغيّر الافتراضي وحده)

        $res = $this->actingAs($this->admin())->get("/admin/inventory/products/{$product->uuid}/edit")->assertOk();
        $res->assertSee('48');                                          // الإجمالي
        $res->assertSee(route('admin.products.edit', $product), false); // رابط كرت الصنف
        $res->assertSee(__('كميّات المقاسات والألوان'));                 // جدول الكميات للتعديل
        $res->assertSee('variant_qty[', false);                         // حقول كمية لكل متغيّر
    }

    public function test_quantity_edit_ignored_for_multi_variant_product(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 60]);
        $default = $product->defaultVariant;
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 60]);
        app(InventoryService::class)->receive($default, $this->warehouse, 2, 25);
        app(InventoryService::class)->receive($second, $this->warehouse, 46, 25);

        // محاولة تمرير كمية يدوية — يجب تجاهلها كي لا تُفسد توزيع المتغيّرات.
        $this->actingAs($this->admin())->put("/admin/inventory/products/{$product->uuid}", [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'quantity' => 999,
        ])->assertRedirect();

        $this->assertSame(48.0, (float) $product->stocks()->sum('on_hand'));
    }

    public function test_can_edit_per_variant_quantities_for_multi_variant_product(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 60]);
        $default = $product->defaultVariant;
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 60]);
        app(InventoryService::class)->receive($default, $this->warehouse, 2, 25);
        app(InventoryService::class)->receive($second, $this->warehouse, 46, 25);

        // تعديل كمية كل متغيّر مباشرة من الصفحة.
        $this->actingAs($this->admin())->put("/admin/inventory/products/{$product->uuid}", [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'variant_qty' => [
                $default->id => 10,
                $second->id => 5,
            ],
        ])->assertRedirect();

        $onHand = fn ($vid) => (float) InventoryStock::where('variant_id', $vid)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');

        $this->assertSame(10.0, $onHand($default->id));
        $this->assertSame(5.0, $onHand($second->id));
        $this->assertSame(15.0, (float) $product->stocks()->sum('on_hand'));
    }

    public function test_variant_qty_rejects_foreign_variant_ids(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 60]);
        $default = $product->defaultVariant;
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 60]);
        app(InventoryService::class)->receive($default, $this->warehouse, 5, 25);

        // متغيّر تابع لمنتج آخر — يجب تجاهله.
        $other = Product::factory()->active()->create();
        $otherVariant = $other->defaultVariant;
        app(InventoryService::class)->receive($otherVariant, $this->warehouse, 3, 25);

        $this->actingAs($this->admin())->put("/admin/inventory/products/{$product->uuid}", [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'variant_qty' => [$otherVariant->id => 999],
        ])->assertRedirect();

        // لم يتغيّر رصيد متغيّر المنتج الآخر.
        $this->assertSame(3.0, (float) InventoryStock::where('variant_id', $otherVariant->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand'));
    }

    public function test_quantity_editable_for_simple_product(): void
    {
        $product = Product::factory()->active()->create(['retail_price' => 60]);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 10, 25);

        $this->actingAs($this->admin())->put("/admin/inventory/products/{$product->uuid}", [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'quantity' => 25,
        ])->assertRedirect();

        $this->assertSame(25.0, (float) $product->stocks()->sum('on_hand'));
    }
}
