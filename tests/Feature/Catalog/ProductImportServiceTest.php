<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImportService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$content); // BOM كما يفعل Excel
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function stock(Product $product): float
    {
        $w = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();

        return (float) InventoryStock::where('warehouse_id', $w->id)
            ->where('variant_id', $product->defaultVariant->id)->value('on_hand');
    }

    public function test_imports_creates_categories_prices_and_stock(): void
    {
        $csv = $this->csv(implode("\n", [
            'اسم الصنف,سعر البيع,الكمية,سعر الشراء,الباركود,التصنيف',
            'قميص رجالي,120,10,60,26000021,قمصان',
            'حذاء رياضي,150,5,80,,أحذية',
            ',0,0,0,999,قمصان',                       // بلا اسم → متجاوَز
        ]));

        $summary = app(ProductImportService::class)->import($csv);

        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertEmpty($summary['errors']);

        // التصنيفات أُنشئت
        $this->assertNotNull(Category::where('name', 'قمصان')->first());
        $this->assertNotNull(Category::where('name', 'أحذية')->first());

        $shirt = Product::where('barcode', '26000021')->first();
        $this->assertNotNull($shirt);
        $this->assertSame('قميص رجالي', $shirt->name);
        $this->assertEqualsWithDelta(120, (float) $shirt->retail_price, 0.01);
        $this->assertEqualsWithDelta(60, (float) $shirt->cost_price, 0.01);
        $this->assertEqualsWithDelta(10, $this->stock($shirt), 0.01);
        // السعر مُزامَن مع المتغيّر الافتراضي (يظهر في الموقع)
        $this->assertEqualsWithDelta(120, (float) $shirt->defaultVariant->retail_price, 0.01);

        $shoe = Product::where('name', 'حذاء رياضي')->first();
        $this->assertNull($shoe->barcode);
        $this->assertEqualsWithDelta(5, $this->stock($shoe), 0.01);
    }

    public function test_import_page_and_template_render(): void
    {
        $admin = User::where('email', 'admin@pluto-brand.com')->first();

        $this->actingAs($admin)->get('/admin/products/import')->assertOk()->assertSee('استيراد المنتجات');
        $this->actingAs($admin)->get('/admin/products/import/template')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_duplicate_barcode_updates_existing_product(): void
    {
        $first = $this->csv(implode("\n", [
            'اسم الصنف,سعر البيع,الكمية,سعر الشراء,الباركود,التصنيف',
            'قميص,120,10,60,26000021,قمصان',
        ]));
        app(ProductImportService::class)->import($first);

        $second = $this->csv(implode("\n", [
            'اسم الصنف,سعر البيع,الكمية,سعر الشراء,الباركود,التصنيف',
            'قميص محدّث,130,7,65,26000021,قمصان',
        ]));
        $summary = app(ProductImportService::class)->import($second);

        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, Product::where('barcode', '26000021')->count()); // لا تكرار

        $product = Product::where('barcode', '26000021')->first();
        $this->assertSame('قميص محدّث', $product->name);
        $this->assertEqualsWithDelta(130, (float) $product->retail_price, 0.01);
        $this->assertEqualsWithDelta(7, $this->stock($product), 0.01); // المخزون ضُبط على القيمة الجديدة
    }
}
