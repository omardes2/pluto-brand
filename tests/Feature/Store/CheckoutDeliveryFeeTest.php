<?php

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رسوم توصيل طلبات الموقع تُشتقّ من جدول أسعار مدن المزوّد (نمط Opost) حسب مدينة الوجهة.
 */
class CheckoutDeliveryFeeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function city(?float $fee = null): City
    {
        $gov = Governorate::firstOrCreate(['name' => 'محافظة الاختبار'], ['country_code' => 'PS', 'is_active' => true]);
        $city = City::firstOrCreate(['governorate_id' => $gov->id, 'name' => 'مدينة الاختبار'], ['is_active' => true]);

        if ($fee !== null) {
            DeliveryCityRate::updateOrCreate(
                ['city_id' => $city->id],
                ['name' => $city->name, 'delivery_fee' => $fee, 'is_active' => true],
            );
        }

        return $city;
    }

    private function placeOrder(City $city): Order
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 20, 60);

        $carts = app(CartService::class);
        $cart = Cart::create(['session_token' => 'sess-'.uniqid(), 'branch_id' => Branch::default()->id, 'status' => 'active']);
        $carts->addItem($cart, $variant->fresh(), 1);

        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart->fresh('items'));
        $session->update([
            'customer_name' => 'زبون', 'customer_phone' => '966500000000',
            'shipping_address' => 'شارع 1', 'city_id' => $city->id, 'payment_method_code' => 'cod',
        ]);

        return $checkout->place($session->fresh());
    }

    public function test_no_delivery_fee_when_city_has_no_rate(): void
    {
        $order = $this->placeOrder($this->city()); // مدينة بلا سعر مُهيّأ
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }

    public function test_applies_city_delivery_fee(): void
    {
        $order = $this->placeOrder($this->city(15)); // سعر مدينة = 15
        $this->assertEqualsWithDelta(15.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(115.0, (float) $order->total, 0.001);
    }

    public function test_zero_rate_keeps_free_shipping(): void
    {
        $order = $this->placeOrder($this->city(0));
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }
}
