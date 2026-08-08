<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use App\Modules\Store\Events\CheckoutCompleted;
use App\Modules\Store\Events\CheckoutStarted;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeTrackingDeliveryProvider;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->first();
    }

    private function sellableVariant(float $retail = 40, ?float $promo = null, float $stock = 10): ProductVariant
    {
        $product = Product::factory()->create(['is_active' => true, 'visibility' => 'visible']);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $retail, 'promo_price' => $promo]);
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, 10);

        return $variant;
    }

    /** @param array<string, string> $headers */
    private function addToCart(ProductVariant $v, float $qty = 2, array $headers = []): void
    {
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => $qty], $headers)->assertOk();
    }

    /** مدينة اختبار مستقلّة (بلا سعر توصيل مُهيّأ ⇒ لا رسوم على الإجماليات المتوقّعة). */
    private function cityId(): int
    {
        $gov = Governorate::firstOrCreate(
            ['name' => 'محافظة الاختبار'], ['country_code' => 'PS', 'is_active' => true],
        );

        return City::firstOrCreate(
            ['governorate_id' => $gov->id, 'name' => 'مدينة الاختبار'], ['is_active' => true],
        )->id;
    }

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '0500000000',
            'shipping_address' => 'الرياض - حي النخيل - شارع 1',
            'city_id' => $this->cityId(),
            'payment_method_code' => 'cod',
        ], $overrides);
    }

    // ---- المصادقة والهوية ----

    public function test_guest_without_token_cannot_start_checkout(): void
    {
        $this->postJson('/api/v1/store/checkout')->assertUnauthorized();
    }

    public function test_cannot_start_checkout_with_empty_cart(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/store/checkout')->assertUnprocessable();
    }

    // ---- التدفّق متعدّد الخطوات (مستخدم مُصادَق) ----

    public function test_successful_multistep_checkout(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 3);

        $sid = $this->postJson('/api/v1/store/checkout')->assertSuccessful()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.ready', false)
            ->json('data.id');

        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details())->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.payment_method', 'cod');

        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk()
            ->assertJsonPath('data.status', 'stock_reserved')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.total', '120.00')
            ->assertJsonPath('data.payment.status', 'pending');

        $this->assertDatabaseHas('orders', ['channel' => 'web', 'status' => 'stock_reserved']);
        $this->assertDatabaseHas('checkout_sessions', ['uuid' => $sid, 'status' => 'placed']);
    }

    public function test_checkout_reserves_inventory(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 4);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();

        $stock = InventoryStock::where('variant_id', $v->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(4, (float) $stock->reserved);
        $this->assertEquals(10, (float) $stock->on_hand);
    }

    public function test_checkout_converts_cart_and_starts_fresh(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();

        $this->getJson('/api/v1/store/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_place_rejected_when_details_incomplete(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        // لم تُضبط بيانات الشحن/الدفع.
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_place_rejected_without_city(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        // كل البيانات ما عدا المدينة (وجهة التوصيل) ⇒ الجلسة غير جاهزة ويُرفض الإتمام.
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(['city_id' => null]))
            ->assertOk()->assertJsonPath('data.ready', false);
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_web_checkout_dispatches_order_to_delivery_company(): void
    {
        // مزوّد توصيل وهمي يُرجع رقم تتبّع ⇒ يُثبت ربط طلب الموقع بشركة التوصيل فور الإتمام.
        config()->set('shipping.provider', 'faketrack');
        config()->set('shipping.drivers.faketrack.delivery', FakeTrackingDeliveryProvider::class);
        FakeTrackingDeliveryProvider::$createResult = null;
        DeliveryProvider::firstOrCreate(['code' => 'faketrack'], ['name' => 'Fake', 'driver' => 'faketrack']);

        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details())->assertOk();
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();

        $order = Order::where('channel', 'web')->latest('id')->firstOrFail();
        $this->assertNotEmpty($order->city_id);            // وجهة التوصيل محفوظة على الطلب
        $this->assertNotEmpty($order->tracking_number);    // رُبط بشركة التوصيل (رقم تتبّع)
        $this->assertSame('shipped', $order->status);      // خرج للتوصيل ⇒ مُشحَن

        // المخزون خُصم فعليًا لحظة الإرسال (10 − 2)، واستُهلك الحجز.
        $stock = InventoryStock::where('variant_id', $v->id)
            ->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertEquals(8.0, (float) $stock->on_hand);
        $this->assertEquals(0.0, (float) $stock->reserved);
    }

    public function test_dispatch_does_not_double_deduct_already_shipped_order(): void
    {
        config()->set('shipping.provider', 'faketrack');
        config()->set('shipping.drivers.faketrack.delivery', FakeTrackingDeliveryProvider::class);
        FakeTrackingDeliveryProvider::$createResult = null;
        DeliveryProvider::firstOrCreate(['code' => 'faketrack'], ['name' => 'Fake', 'driver' => 'faketrack']);

        $v = $this->sellableVariant(40, null, 10);

        // طلب أدمن مشحون مسبقًا (fulfillToShipped) ⇒ on_hand = 8 وحالته «مُشحَن».
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون توصيل', 'customer_phone' => '0599000000',
            'city_id' => $this->cityId(), 'channel' => 'manual',
        ], [['variant_id' => $v->id, 'qty' => 2, 'unit_price' => 40]], 2026);
        app(OrderService::class)->fulfillToShipped($order);
        $this->assertSame('shipped', $order->fresh()->status);

        // الإرسال لشركة التوصيل لا يُعيد خصم المخزون (الطلب ليس محجوزًا).
        app(OrderDeliveryDispatcher::class)->dispatch($order->fresh());

        $stock = InventoryStock::where('variant_id', $v->id)
            ->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertEquals(8.0, (float) $stock->on_hand); // بقي 8 (لا خصم مزدوج)
    }

    public function test_inactive_payment_method_rejected_at_place(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        // hyperpay مسجّل (يمرّ exists) لكنه غير مُفعّل → يُرفض عند الإتمام.
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(['payment_method_code' => 'hyperpay']))->assertOk();
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_unknown_payment_method_rejected_at_update(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(['payment_method_code' => 'nope']))
            ->assertUnprocessable();
    }

    public function test_place_rejected_when_stock_dropped_below_cart_qty(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 5);
        $this->addToCart($v, 5);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());

        // إخراج مخزون بعد بدء الجلسة يخفّض المتاح دون كمية السلة.
        app(InventoryService::class)->issue($v, $this->warehouse, 3);

        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_checkout_dispatches_domain_events(): void
    {
        Event::fake([CheckoutStarted::class, CheckoutCompleted::class]);
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        Event::assertDispatched(CheckoutStarted::class);

        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();
        Event::assertDispatched(CheckoutCompleted::class);
    }

    public function test_cannot_access_another_users_session(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);
        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/store/checkout/{$sid}")->assertForbidden();
    }

    // ---- إتمام الضيف (رمز سلة) ----

    public function test_guest_checkout_end_to_end(): void
    {
        $token = (string) Str::uuid();
        $headers = ['X-Cart-Token' => $token];
        $v = $this->sellableVariant(50, null, 10);

        $this->addToCart($v, 2, $headers);

        $sid = $this->postJson('/api/v1/store/checkout', [], $headers)->assertSuccessful()->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(), $headers)->assertOk();
        $this->postJson("/api/v1/store/checkout/{$sid}/place", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'stock_reserved')
            ->assertJsonPath('data.total', '100.00');

        // طلب ضيف: بلا عميل مرتبط.
        $this->assertDatabaseHas('orders', ['channel' => 'web', 'customer_id' => null, 'status' => 'stock_reserved']);
    }

    public function test_guest_cannot_access_session_without_matching_token(): void
    {
        $token = (string) Str::uuid();
        $v = $this->sellableVariant();
        $this->addToCart($v, 1, ['X-Cart-Token' => $token]);
        $sid = $this->postJson('/api/v1/store/checkout', [], ['X-Cart-Token' => $token])->json('data.id');

        // رمز مختلف → ممنوع.
        $this->getJson("/api/v1/store/checkout/{$sid}", ['X-Cart-Token' => (string) Str::uuid()])
            ->assertForbidden();
    }
}
