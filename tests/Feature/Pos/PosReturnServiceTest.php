<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosReturnServiceTest extends TestCase
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
        $this->variant->update(['wholesale_price' => 0]);
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
        $this->actingAs(User::where('email', 'admin@pluto-brand.com')->firstOrFail());
    }

    private function openShift(): PosShift
    {
        return app(PosShiftService::class)->open(User::where('email', 'admin@pluto-brand.com')->first(), [
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => Branch::default()->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->first()->id,
            'opening_float' => 100,
        ]);
    }

    private function sell(PosShift $shift, float $qty): Order
    {
        return app(PosSaleService::class)->sell($shift->fresh(), [
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
    }

    private function stock(): InventoryStock
    {
        return InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->firstOrFail();
    }

    public function test_partial_return_restocks_and_refunds_from_drawer(): void
    {
        $shift = $this->openShift();
        $order = $this->sell($shift, 5);            // stock 95، درج 200
        $itemId = $order->items->first()->id;

        $result = app(PosReturnService::class)->refund($shift->fresh(), $order, [
            ['order_item_id' => $itemId, 'qty' => 2, 'condition' => 'sellable'],
        ]);

        $this->assertEquals(40.0, $result['refund']);            // 2 × 20
        $this->assertEquals(97, (float) $this->stock()->on_hand); // 95 + 2 عادت للرف
        $this->assertSame('partially_returned', $order->fresh()->status);
        $this->assertEquals(2, (float) $order->items->first()->fresh()->returned_qty);

        $shift->refresh();
        $this->assertEquals(40.0, (float) $shift->cash_refunds);
        $this->assertEquals(160.0, (float) $shift->expected_cash); // 200 − 40
        $this->assertSame(1, $shift->movements()->where('type', PosShiftMovement::TYPE_REFUND)->count());
    }

    public function test_full_return_marks_order_returned(): void
    {
        $shift = $this->openShift();
        $order = $this->sell($shift, 3);

        app(PosReturnService::class)->refund($shift->fresh(), $order, [
            ['order_item_id' => $order->items->first()->id, 'qty' => 3, 'condition' => 'sellable'],
        ]);

        $this->assertSame('returned', $order->fresh()->status);
        $this->assertEquals(100, (float) $this->stock()->on_hand); // عاد كل شيء
    }

    public function test_damaged_condition_routes_to_damaged_bin(): void
    {
        $shift = $this->openShift();
        $order = $this->sell($shift, 2);              // on_hand 98

        app(PosReturnService::class)->refund($shift->fresh(), $order, [
            ['order_item_id' => $order->items->first()->id, 'qty' => 2, 'condition' => 'damaged'],
        ]);

        $stock = $this->stock();
        $this->assertEquals(98, (float) $stock->on_hand);  // لم يعُد للرفّ (تالف)
        $this->assertEquals(2, (float) $stock->damaged);   // ذهب لمخزون التالف
    }

    public function test_cannot_return_more_than_sold(): void
    {
        $shift = $this->openShift();
        $order = $this->sell($shift, 2);

        $this->expectException(ValidationException::class);
        app(PosReturnService::class)->refund($shift->fresh(), $order, [
            ['order_item_id' => $order->items->first()->id, 'qty' => 5],
        ]);
    }
}
