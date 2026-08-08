<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Pos\Services\PosSaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosSaleServiceTest extends TestCase
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
        $this->variant->update(['wholesale_price' => 0]); // تعطيل حدّ الجملة في الاختبارات العامة
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
        $this->actingAs($this->admin());
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->firstOrFail();
    }

    private function service(): PosSaleService
    {
        return app(PosSaleService::class);
    }

    private function openShift(float $opening = 100): PosShift
    {
        return PosShift::create([
            'number' => 'SHIFT-'.now()->year.'-0001',
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->firstOrFail()->id,
            'user_id' => $this->admin()->id,
            'status' => PosShift::STATUS_OPEN,
            'opening_float' => $opening,
            'opened_at' => now(),
        ]);
    }

    private function stock(): InventoryStock
    {
        return InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->firstOrFail();
    }

    public function test_cash_sale_fulfills_order_decrements_stock_and_collects(): void
    {
        $shift = $this->openShift(100);

        $order = $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 5, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);

        $this->assertSame('pos', $order->channel);
        $this->assertSame($shift->id, $order->pos_shift_id);
        $this->assertSame('delivered', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(100.0, (float) $order->total);

        // المخزون خُصم فورًا.
        $this->assertEquals(95, (float) $this->stock()->on_hand);

        // أرصدة الوردية + حركة الدرج.
        $shift->refresh();
        $this->assertEquals(100.0, (float) $shift->cash_sales);
        $this->assertEquals(100.0, (float) $shift->total_sales);
        $this->assertSame(1, $shift->orders_count);
        $this->assertEquals(200.0, (float) $shift->expected_cash); // 100 افتتاحي + 100 نقدي

        $movement = $shift->movements()->first();
        $this->assertSame(PosShiftMovement::TYPE_CASH_SALE, $movement->type);
        $this->assertEquals(100.0, (float) $movement->amount);
        $this->assertSame($order->id, $movement->order_id);
    }

    public function test_card_sale_records_card_movement_without_affecting_cash(): void
    {
        $shift = $this->openShift(100);

        $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'card',
        ]);

        $shift->refresh();
        $this->assertEquals(40.0, (float) $shift->card_sales);
        $this->assertEquals(0.0, (float) $shift->cash_sales);
        $this->assertEquals(100.0, (float) $shift->expected_cash); // البطاقة لا تدخل الدرج
        $this->assertSame(PosShiftMovement::TYPE_CARD_SALE, $shift->movements()->first()->type);
    }

    public function test_order_discount_distributes_and_reduces_total(): void
    {
        $shift = $this->openShift();
        $other = Product::factory()->create()->defaultVariant;
        $other->update(['wholesale_price' => 0]);
        app(InventoryService::class)->receive($other, $this->warehouse, 100, 10);

        $order = $this->service()->sell($shift, [
            'items' => [
                ['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 100],
                ['variant_id' => $other->id, 'qty' => 1, 'unit_price' => 100],
            ],
            'discount' => 30,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(200.0, (float) $order->subtotal);
        $this->assertEquals(30.0, (float) $order->discount_total);
        $this->assertEquals(170.0, (float) $order->total);
    }

    public function test_credit_sale_creates_unpaid_order_on_customer(): void
    {
        $shift = $this->openShift(100);
        $customer = Customer::factory()->create(['branch_id' => Branch::default()->id]);

        $order = $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => 20]],
            'payment_method' => 'credit',
            'customer_id' => $customer->id,
        ]);

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('delivered', $order->status);
        $this->assertNotSame('paid', $order->payment_status); // ذمم غير مدفوعة
        $this->assertEquals(0.0, (float) $order->amount_paid);

        $shift->refresh();
        $this->assertEquals(40.0, (float) $shift->credit_sales);
        $this->assertEquals(0.0, (float) $shift->cash_sales);
        $this->assertEquals(100.0, (float) $shift->expected_cash); // الآجل لا يدخل الدرج
        $this->assertSame(PosShiftMovement::TYPE_CREDIT_SALE, $shift->movements()->first()->type);
    }

    public function test_credit_requires_customer(): void
    {
        $shift = $this->openShift();

        $this->expectException(ValidationException::class);
        $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'credit',
        ]);
    }

    public function test_cannot_sell_below_wholesale_price(): void
    {
        $this->variant->update(['wholesale_price' => 50]);
        $shift = $this->openShift();

        $this->expectException(ValidationException::class);
        $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 40]],
            'payment_method' => 'cash',
        ]);
    }

    public function test_cannot_sell_on_closed_shift(): void
    {
        $shift = $this->openShift();
        $shift->update(['status' => PosShift::STATUS_CLOSED]);

        $this->expectException(ValidationException::class);
        $this->service()->sell($shift, [
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 20]],
            'payment_method' => 'cash',
        ]);
    }

    public function test_overselling_beyond_stock_rolls_back_completely(): void
    {
        $shift = $this->openShift();

        $threw = false;
        try {
            $this->service()->sell($shift, [
                'items' => [['variant_id' => $this->variant->id, 'qty' => 1000, 'unit_price' => 20]],
                'payment_method' => 'cash',
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'البيع بكمية أكبر من المخزون يجب أن يفشل');
        // معاملة واحدة: لا خصم مخزون ولا طلب POS ولا حركة درج.
        $this->assertEquals(100, (float) $this->stock()->on_hand);
        $this->assertSame(0, $shift->fresh()->orders_count);
        $this->assertSame(0, $shift->movements()->count());
    }
}
