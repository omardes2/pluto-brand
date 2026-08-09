<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    private function stockedProduct(float $qty): Product
    {
        $product = Product::factory()->active()->create();
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, $qty, 10);

        return $product;
    }

    public function test_default_threshold_flags_low_items_without_per_item_level(): void
    {
        Settings::set('inventory.low_stock_threshold', 5);
        $low = $this->stockedProduct(3);   // ≤ 5 → منخفض
        $ok = $this->stockedProduct(20);   // > 5 → طبيعي

        $rows = app(WarehouseService::class)->lowStock($this->warehouse);
        $variantIds = $rows->pluck('variant_id');

        $this->assertTrue($variantIds->contains($low->defaultVariant->id));
        $this->assertFalse($variantIds->contains($ok->defaultVariant->id));
    }

    public function test_per_item_reorder_level_overrides_default(): void
    {
        Settings::set('inventory.low_stock_threshold', 5);
        $product = $this->stockedProduct(3); // على 3 قطع

        // حدّ خاص = 2 → 3 > 2 فلا يُعتبر منخفضًا رغم أنه تحت الافتراضي (5).
        InventoryStock::where('variant_id', $product->defaultVariant->id)->update(['reorder_level' => 2]);

        $rows = app(WarehouseService::class)->lowStock($this->warehouse);
        $this->assertFalse($rows->pluck('variant_id')->contains($product->defaultVariant->id));
    }

    public function test_zero_threshold_disables_global_but_keeps_out_of_stock(): void
    {
        Settings::set('inventory.low_stock_threshold', 0);
        $some = $this->stockedProduct(3);  // بلا حدّ خاص + الافتراضي 0 → لا يظهر
        $empty = $this->stockedProduct(5); // ثم نُفرِّغه للصفر أدناه
        InventoryStock::where('variant_id', $empty->defaultVariant->id)->update(['on_hand' => 0]); // نفد → 0 ≤ 0 → يظهر

        $ids = app(WarehouseService::class)->lowStock($this->warehouse)->pluck('variant_id');
        $this->assertFalse($ids->contains($some->defaultVariant->id));
        $this->assertTrue($ids->contains($empty->defaultVariant->id));
    }

    public function test_low_stock_page_renders_with_threshold_note(): void
    {
        Settings::set('inventory.low_stock_threshold', 5);
        $this->stockedProduct(2);

        $admin = User::where('email', 'admin@pluto-brand.com')->first();
        $this->actingAs($admin)->get('/admin/inventory/low-stock')
            ->assertOk()
            ->assertSee(__('warehouse.default_threshold_tag'));
    }

    public function test_threshold_saved_from_settings_screen(): void
    {
        $admin = User::where('email', 'admin@pluto-brand.com')->first();

        $this->actingAs($admin)->put('/admin/settings', [
            'inventory_low_stock_threshold' => 8,
        ])->assertRedirect();

        $this->assertSame('8', (string) Settings::get('inventory.low_stock_threshold'));
    }
}
