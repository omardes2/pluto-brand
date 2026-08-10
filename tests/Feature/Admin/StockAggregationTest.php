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
 * «المتوفّر» في القوائم يجب أن يحسب مخزون المتغيّرات النشطة فقط (القابل للبيع)،
 * فيتطابق مع مجموع كميّات المقاسات/الألوان — دون العالق على متغيّرات مُعطَّلة.
 */
class StockAggregationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    private function productWithOrphanStock(): Product
    {
        $product = Product::factory()->active()->create();
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 28, 10); // نشط

        $retired = ProductVariant::factory()->create(['product_id' => $product->id]);
        app(InventoryService::class)->receive($retired, $this->warehouse, 22, 10);
        $retired->update(['is_active' => false]); // مخزون عالق على متغيّر مُعطَّل

        return $product;
    }

    public function test_active_stocks_relation_excludes_inactive_variants(): void
    {
        $product = $this->productWithOrphanStock();

        $product->loadSum('activeStocks as active_sum', 'on_hand');
        $product->loadSum('stocks as all_sum', 'on_hand');

        $this->assertSame(28.0, (float) $product->active_sum); // القابل للبيع
        $this->assertSame(50.0, (float) $product->all_sum);    // الكل (نشط + عالق)
    }

    public function test_stocks_list_shows_active_available(): void
    {
        $product = $this->productWithOrphanStock();
        $admin = User::where('email', 'admin@pluto-brand.com')->first();

        $res = $this->actingAs($admin)->get('/admin/inventory/stocks?search='.urlencode($product->name))->assertOk();
        $res->assertSee('28');
        $res->assertDontSee('>50<', false); // لا يظهر الإجمالي العالق
    }
}
