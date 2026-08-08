<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_pos_tables_and_order_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('pos_shifts'));
        $this->assertTrue(Schema::hasTable('pos_shift_movements'));
        $this->assertTrue(Schema::hasColumn('orders', 'pos_shift_id'));
    }

    public function test_pos_permissions_and_cashier_role_seeded(): void
    {
        foreach (['pos.view', 'pos.sell', 'pos.discount', 'pos.refund', 'pos.refund.no_invoice', 'pos.shift.manage'] as $perm) {
            $this->assertTrue(Permission::where('name', $perm)->where('guard_name', 'web')->exists(), "missing permission {$perm}");
        }

        $cashier = Role::where('name', 'cashier')->first();
        $this->assertNotNull($cashier, 'cashier role not seeded');
        $this->assertTrue($cashier->hasPermissionTo('pos.sell'));
        $this->assertTrue($cashier->hasPermissionTo('pos.refund'));
        // الإرجاع دون فاتورة أصلية للمشرف فقط — لا يملكه الكاشير.
        $this->assertFalse($cashier->hasPermissionTo('pos.refund.no_invoice'));
    }

    public function test_pos_payment_methods_and_settings_seeded(): void
    {
        foreach (['cash', 'card'] as $code) {
            $m = PaymentMethod::where('code', $code)->first();
            $this->assertNotNull($m, "missing payment method {$code}");
            $this->assertTrue((bool) $m->is_active);
        }

        $this->assertSame('عميل نقدي', Settings::get('pos.default_customer_name'));
        $this->assertTrue((bool) Settings::get('pos.enabled'));
    }

    public function test_can_create_shift_with_movement(): void
    {
        $shift = PosShift::create([
            'number' => 'SHIFT-2026-0001',
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'treasury_id' => Treasury::query()->firstOrFail()->id,
            'user_id' => User::query()->firstOrFail()->id,
            'status' => PosShift::STATUS_OPEN,
            'opening_float' => 200,
            'opened_at' => now(),
        ]);

        $this->assertNotEmpty($shift->uuid, 'uuid auto-generated');
        $this->assertTrue($shift->isOpen());

        $movement = $shift->movements()->create([
            'type' => PosShiftMovement::TYPE_OPENING,
            'amount' => 200,
            'note' => 'رصيد افتتاحي',
        ]);

        $this->assertNotEmpty($movement->uuid);
        $this->assertSame($shift->id, $movement->shift->id);
        $this->assertSame(1, $shift->movements()->count());
    }
}
