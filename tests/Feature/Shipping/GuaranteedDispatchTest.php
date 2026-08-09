<?php

namespace Tests\Feature\Shipping;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Foundation\Models\City;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTrackingDeliveryProvider;
use Tests\TestCase;

/**
 * الترحيل الجذري المضمون: الإرسال المتزامن + المكنسة المجدولة يضمنان وصول كل طلب توصيل
 * لشركة التوصيل دون اعتماد على عامل طابور، مع منع تكرار الشحنات (idempotency + قفل).
 */
class GuaranteedDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->cityId = (int) City::query()->value('id');

        FakeTrackingDeliveryProvider::$createResult = null;
        config([
            'shipping.provider' => 'faketrack',
            'shipping.drivers.faketrack.delivery' => FakeTrackingDeliveryProvider::class,
        ]);
    }

    protected function tearDown(): void
    {
        FakeTrackingDeliveryProvider::$createResult = null;
        FakeTrackingDeliveryProvider::$lastPayload = null;
        parent::tearDown();
    }

    private function deliveryOrder(array $attrs = []): Order
    {
        return Order::factory()->confirmed()->create(array_merge([
            'city_id' => $this->cityId,
            'channel' => 'manual',
            'confirmed_at' => now()->subMinutes(10),
            'tracking_number' => null,
        ], $attrs));
    }

    public function test_shipment_description_includes_variant_option(): void
    {
        $product = Product::factory()->create(['name' => 'قميص']);
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);
        $l = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id, 'value' => 'L', 'label' => 'L']);
        app(ProductVariantService::class)
            ->syncMatrix($product, [$attr->id => [$l->id]], [['value_ids' => [$l->id], 'retail_price' => 100, 'quantity' => 5]]);
        $variant = $product->variants()->where('name', 'L')->first();

        $order = $this->deliveryOrder();
        $order->items()->create([
            'variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100,
            'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 100,
        ]);

        app(OrderDeliveryDispatcher::class)->dispatch($order);

        $this->assertStringContainsString('قميص — L', FakeTrackingDeliveryProvider::$lastPayload['items_description']);
    }

    public function test_dispatch_sets_tracking_and_creates_shipment(): void
    {
        $order = $this->deliveryOrder();

        $result = app(OrderDeliveryDispatcher::class)->dispatch($order);

        $this->assertSame('created', $result['status']);
        $this->assertNotEmpty($order->fresh()->tracking_number);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_dispatch_is_idempotent_no_duplicate_shipment(): void
    {
        $order = $this->deliveryOrder();
        $dispatcher = app(OrderDeliveryDispatcher::class);

        $dispatcher->dispatch($order);
        $second = $dispatcher->dispatch($order->fresh());

        $this->assertSame('skipped', $second['status']);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_dispatch_failure_leaves_order_without_tracking(): void
    {
        FakeTrackingDeliveryProvider::$createResult = ['status' => 'failed', 'message' => 'provider down'];
        $order = $this->deliveryOrder();

        $result = app(OrderDeliveryDispatcher::class)->dispatch($order);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($order->fresh()->tracking_number);
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count());
    }

    public function test_sweeper_dispatches_stuck_orders(): void
    {
        $stuck = $this->deliveryOrder();
        $pos = $this->deliveryOrder(['channel' => 'pos', 'city_id' => null]); // يُتجاهَل.

        $this->artisan('shipping:dispatch-pending')->assertSuccessful();

        $this->assertNotEmpty($stuck->fresh()->tracking_number);
        $this->assertNull($pos->fresh()->tracking_number);
    }

    public function test_sweeper_is_noop_when_provider_null(): void
    {
        config()->set('shipping.provider', 'null');
        $order = $this->deliveryOrder();

        $this->artisan('shipping:dispatch-pending')->assertSuccessful();

        $this->assertNull($order->fresh()->tracking_number);
    }
}
