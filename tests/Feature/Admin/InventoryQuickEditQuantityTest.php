<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
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
        $res->assertSee('48');                                   // الإجمالي
        $res->assertSee(route('admin.products.edit', $product), false); // رابط كرت الصنف
        $res->assertSee(__('عدّل الكميات من كرت الصنف'));
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
