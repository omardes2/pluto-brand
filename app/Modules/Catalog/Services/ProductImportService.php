<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * استيراد المنتجات دفعةً من ملف CSV (يُحفَظ من Excel باسم «CSV UTF-8»).
 * الأعمدة (بالعنوان العربي، أي ترتيب): اسم الصنف، سعر البيع، الكمية، سعر الشراء، الباركود، التصنيف.
 * يُنشئ منتجًا لكل صف (أو يُحدّث الموجود بنفس الباركود)، ويضبط السعر والتكلفة والمخزون والتصنيف.
 */
class ProductImportService
{
    /** مرادفات عناوين الأعمدة → الحقل القانوني. */
    private const HEADERS = [
        'name' => ['اسم الصنف', 'الاسم', 'الصنف', 'name'],
        'retail_price' => ['سعر البيع', 'السعر', 'price'],
        'quantity' => ['الكمية', 'المخزون', 'quantity', 'qty'],
        'cost_price' => ['سعر الشراء', 'التكلفة', 'cost'],
        'barcode' => ['الباركود', 'باركود', 'barcode'],
        'category' => ['التصنيف', 'الفئة', 'category'],
    ];

    /** @var array<string, Category> تخزين مؤقّت للتصنيفات ضمن عملية الاستيراد. */
    private array $categoryCache = [];

    public function __construct(
        private readonly ProductService $products,
        private readonly ProductVariantService $variants,
    ) {}

    /**
     * @return array{created:int, updated:int, skipped:int, errors:array<int, array{row:int, message:string}>}
     */
    public function import(string $path): array
    {
        $this->categoryCache = [];
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $warehouse = $this->defaultWarehouse();

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException(__('تعذّر فتح الملف.'));
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new \RuntimeException(__('الملف فارغ.'));
            }
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]); // إزالة BOM
            $map = $this->mapHeader($header);
            if (! isset($map['name'])) {
                throw new \RuntimeException(__('لم يُعثر على عمود «اسم الصنف» في الملف.'));
            }

            $line = 1;
            while (($data = fgetcsv($handle)) !== false) {
                $line++;
                if ($this->isEmptyRow($data)) {
                    continue;
                }
                $row = $this->extractRow($data, $map);

                if (trim((string) $row['name']) === '') {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $this->importRow($row, $warehouse, $summary);
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
                }
            }
        } finally {
            fclose($handle);
        }

        return $summary;
    }

    private function importRow(array $row, ?Warehouse $warehouse, array &$summary): void
    {
        // حلّ التصنيف خارج معاملة الصف: يبقى محفوظًا حتى لو فشل إنشاء المنتج، وidempotent.
        $category = $this->resolveCategory(trim((string) ($row['category'] ?? '')));

        DB::transaction(function () use ($row, $warehouse, $category, &$summary) {
            $name = trim((string) $row['name']);
            $barcode = trim((string) ($row['barcode'] ?? '')) ?: null;
            $retail = $this->number($row['retail_price'] ?? null);
            $cost = $this->number($row['cost_price'] ?? null);
            $qty = $this->number($row['quantity'] ?? null);

            $payload = [
                'name' => $name,
                'category_id' => $category->id,
                'barcode' => $barcode,
                'retail_price' => $retail,
                'cost_price' => $cost,
            ];

            $existing = $barcode ? Product::where('barcode', $barcode)->first() : null;
            if ($existing) {
                $this->products->update($existing, $payload);
                $product = $existing->refresh();
                $summary['updated']++;
            } else {
                $product = $this->products->create($payload);
                $summary['created']++;
            }

            if ($warehouse) {
                $variant = $product->defaultVariant()->first();
                if ($variant) {
                    $this->variants->setVariantStock($variant, $warehouse, $qty, $cost > 0 ? $cost : null);
                }
            }
        });
    }

    /**
     * @return array<string, int> field => column index
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $cell) {
            $value = trim((string) $cell);
            foreach (self::HEADERS as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }
                if (in_array($value, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function extractRow(array $data, array $map): array
    {
        $row = [];
        foreach ($map as $field => $index) {
            $row[$field] = $data[$index] ?? null;
        }

        return $row;
    }

    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** يحوّل نصًّا رقميًّا (قد يحوي فواصل/مسافات) إلى عدد؛ الفارغ = 0. */
    private function number(mixed $value): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    /**
     * إيجاد أو إنشاء التصنيف بشكل idempotent عبر slug ثابت مشتقّ من الاسم.
     * يمنع تكرار الإنشاء (وخطأ فرادة الـ slug) عند تكرار الاسم أو إعادة الاستيراد.
     */
    private function resolveCategory(string $name): Category
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name)); // توحيد المسافات
        if ($name === '') {
            $name = __('غير مصنّف');
        }
        if (isset($this->categoryCache[$name])) {
            return $this->categoryCache[$name];
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            // اسم قد يُنتج slug فارغًا — بديل ثابت مشتقّ من الاسم.
            $slug = 'cat-'.substr(md5($name), 0, 12);
        }

        // firstOrCreate على الـ slug: نفس الاسم ⇐ نفس الـ slug ⇐ نفس التصنيف دائمًا.
        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true],
        );

        return $this->categoryCache[$name] = $category;
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }
}
