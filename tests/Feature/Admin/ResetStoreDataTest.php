<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResetStoreDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reset_clears_transactions_and_products_but_keeps_catalog_meta(): void
    {
        // بيانات كتالوج تبقى.
        $category = Category::factory()->create(['name' => 'قمصان']);
        $brand = Brand::factory()->create(['name' => 'بلوتو']);
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس']);
        ProductAttributeValue::factory()->count(3)->create(['attribute_id' => $attr->id]);

        // منتج + مخزون (يُحذف).
        $product = Product::factory()->active()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        app(InventoryService::class)->receive($product->defaultVariant, $warehouse, 10, 5);

        // طلب بيع (يُحذف).
        $order = Order::factory()->confirmed()->create();
        $order->items()->create([
            'variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 10,
            'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 10,
        ]);

        // وردية كاشير (تُحذف).
        DB::table('pos_shifts')->insert([
            'uuid' => (string) Str::uuid(),
            'number' => 'SH-TEST-1', 'branch_id' => Branch::first()->id,
            'warehouse_id' => $warehouse->id, 'treasury_id' => Treasury::first()->id,
            'user_id' => User::where('email', 'admin@pluto-brand.com')->first()->id,
            'status' => 'open', 'opening_float' => 100, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // قيد محاسبي (يُحذف).
        JournalEntry::factory()->create();

        // رصيد صندوق (يُصفَّر).
        Treasury::query()->update(['opening_balance' => 500]);

        Artisan::call('store:reset', ['--force' => true]);

        // حُذف كل ما هو تشغيلي.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryStock::count());
        $this->assertSame(0, DB::table('pos_shifts')->count());
        $this->assertSame(0, JournalEntry::count());

        // بقيت السمات والقيم والتصنيفات والعلامات.
        $this->assertGreaterThan(0, ProductAttribute::count());
        $this->assertSame(3, ProductAttributeValue::count());
        $this->assertGreaterThan(0, Category::count());
        $this->assertGreaterThan(0, Brand::count());

        // الصناديق صُفِّرت.
        $this->assertSame(0.0, (float) Treasury::query()->max('opening_balance'));

        // البنية الأساسية محفوظة.
        $this->assertNotNull(User::where('email', 'admin@pluto-brand.com')->first());
        $this->assertGreaterThan(0, Warehouse::count());
    }

    public function test_with_categories_option_also_clears_categories_but_keeps_attributes(): void
    {
        Category::factory()->create(['name' => 'قمصان']);
        Brand::factory()->create(['name' => 'بلوتو']);
        $attr = ProductAttribute::factory()->create(['name' => 'المقاس']);
        ProductAttributeValue::factory()->count(2)->create(['attribute_id' => $attr->id]);

        Artisan::call('store:reset', ['--force' => true, '--with-categories' => true]);

        // التصنيفات والعلامات حُذفت.
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Brand::count());
        // السمات وقيمها بقيت.
        $this->assertGreaterThan(0, ProductAttribute::count());
        $this->assertSame(2, ProductAttributeValue::count());
    }
}
