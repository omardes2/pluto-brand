<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderVariantLabelTest extends TestCase
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

    /** @return array{0: Order, 1: Product} */
    private function orderWithOptionVariant(): array
    {
        $product = Product::factory()->create(['name' => 'قميص كلاسيك', 'retail_price' => 100]);
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $l = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'L', 'label' => 'L']);
        app(ProductVariantService::class)->syncMatrix($product, [$attr->id => [$l->id]], [
            ['value_ids' => [$l->id], 'retail_price' => 100, 'quantity' => 5],
        ]);
        $variant = $product->variants()->where('name', 'L')->first();

        $order = Order::factory()->confirmed()->create();
        $order->items()->create([
            'variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100,
            'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 100,
        ]);

        return [$order, $product];
    }

    public function test_admin_order_show_appends_option_label(): void
    {
        [$order, $product] = $this->orderWithOptionVariant();

        $this->actingAs($this->admin())->get(route('admin.sales.orders.show', $order))
            ->assertOk()
            ->assertSee($product->name.' — L');
    }

    public function test_invoice_appends_option_label(): void
    {
        [$order, $product] = $this->orderWithOptionVariant();

        $this->actingAs($this->admin())->get(route('admin.sales.orders.invoice', $order))
            ->assertOk()
            ->assertSee($product->name.' — L');
    }

    public function test_simple_product_line_not_duplicated(): void
    {
        $product = Product::factory()->create(['name' => 'كوب', 'retail_price' => 30]);
        $variant = $product->defaultVariant; // اسمه = اسم المنتج
        $order = Order::factory()->confirmed()->create();
        $order->items()->create([
            'variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 30,
            'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 30,
        ]);

        $this->actingAs($this->admin())->get(route('admin.sales.orders.show', $order))
            ->assertOk()
            ->assertDontSee('كوب — كوب'); // لا تكرار للمنتج البسيط
    }
}
