<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWebTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $this->variant = Product::factory()->create()->defaultVariant;
        $this->variant->update(['wholesale_price' => 0, 'barcode' => 'POS-TEST-123']);
        Product::whereKey($this->variant->product_id)->update(['is_active' => true, 'status' => 'active']);
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    private function openShiftViaHttp(): void
    {
        $this->post(route('admin.pos.shift.open'), [
            'warehouse_id' => $this->warehouse->id,
            'opening_float' => 100,
        ])->assertRedirect(route('admin.pos.screen'));
    }

    public function test_screen_redirects_to_open_form_when_no_shift(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.pos.screen'))
            ->assertRedirect(route('admin.pos.shift.open_form'));
    }

    public function test_open_shift_then_screen_loads(): void
    {
        $this->actingAs($this->admin());
        $this->openShiftViaHttp();

        $this->assertDatabaseHas('pos_shifts', ['user_id' => $this->admin()->id, 'status' => 'open']);
        $this->get(route('admin.pos.screen'))->assertOk()->assertSee('نقطة البيع');
    }

    public function test_products_and_barcode_endpoints(): void
    {
        $this->actingAs($this->admin());
        $this->openShiftViaHttp();

        $this->getJson(route('admin.pos.products'))
            ->assertOk()
            ->assertJsonStructure(['products' => [['variant_id', 'name', 'price', 'stock']]]);

        $this->getJson(route('admin.pos.barcode', ['code' => 'POS-TEST-123']))
            ->assertOk()
            ->assertJsonPath('product.variant_id', $this->variant->id);

        $this->getJson(route('admin.pos.barcode', ['code' => 'NOPE']))->assertNotFound();
    }

    public function test_sell_creates_pos_order_and_decrements_stock(): void
    {
        $this->actingAs($this->admin());
        $this->openShiftViaHttp();

        $res = $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3, 'unit_price' => 20]],
            'payment_method' => 'cash',
            'paid' => 100,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertEquals(60.0, (float) $res->json('total'));
        $this->assertEquals(40.0, (float) $res->json('change'));

        $order = Order::where('channel', 'pos')->firstOrFail();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('paid', $order->payment_status);

        $stock = InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertEquals(97, (float) $stock->on_hand);
    }

    public function test_discount_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->givePermissionTo(['pos.view', 'pos.sell', 'pos.shift.manage']); // بلا pos.discount

        $this->actingAs($user);
        $this->post(route('admin.pos.shift.open'), ['warehouse_id' => $this->warehouse->id, 'opening_float' => 0])
            ->assertRedirect();

        $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'discount' => 5,
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_close_shift_records_variance_and_redirects(): void
    {
        $this->actingAs($this->admin());
        $this->openShiftViaHttp();

        $this->postJson(route('admin.pos.sell'), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 5, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ])->assertOk();

        // متوقّع = 100 افتتاحي + 100 نقدي = 200. المعدود 195 ⇒ عجز 5.
        $this->post(route('admin.pos.shift.close'), ['counted_cash' => 195])
            ->assertRedirect(route('admin.pos.shift.open_form'));

        $shift = PosShift::where('user_id', $this->admin()->id)->firstOrFail();
        $this->assertSame('closed', $shift->status);
        $this->assertEquals(200.0, (float) $shift->expected_cash);
        $this->assertEquals(-5.0, (float) $shift->variance);
    }

    public function test_screen_forbidden_without_permission(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->actingAs($user)->get(route('admin.pos.screen'))->assertForbidden();
    }
}
