<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * منطق أعمال المنتجات (خارج المتحكمات): توليد slug، اشتقاق is_active من status،
 * مزامنة الوسوم والسمات والمتغيّرات — كله داخل معاملة ذرّية (المبدأ 7).
 */
class ProductService
{
    public function __construct(private readonly ProductVariantService $variants) {}

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            [$tagIds, $attributeIds, $axes, $overrides, $attributes] = $this->extractRelations($data);

            // قيم افتراضية للحقول المُزالة من النموذج المبسّط.
            $attributes['status'] = $attributes['status'] ?? 'active';
            $attributes['visibility'] = $attributes['visibility'] ?? 'visible';
            $attributes['type'] = $attributes['type'] ?? 'simple';
            if (empty($attributes['sku'])) {
                $attributes['sku'] = $this->generateSku();
            }
            if (empty($attributes['unit_id'])) {
                $attributes['unit_id'] = Unit::query()->orderBy('id')->value('id');
            }

            $attributes['slug'] = $this->resolveSlug($attributes);
            $attributes['is_active'] = $attributes['status'] === 'active';
            $attributes = $this->normalizePrices($attributes);

            $product = Product::create($attributes);
            $product->tags()->sync($tagIds);
            $this->ensureDefaultVariant($product);
            $this->syncVariantPrices($product, $attributes);
            $this->applyOptions($product, $attributeIds, $axes, $overrides);

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            [$tagIds, $attributeIds, $axes, $overrides, $attributes] = $this->extractRelations($data);

            if (isset($attributes['name']) || isset($attributes['slug'])) {
                $attributes['slug'] = $this->resolveSlug($attributes, $product);
            }

            if (array_key_exists('status', $attributes)) {
                $attributes['is_active'] = $attributes['status'] === 'active';
            }

            $attributes = $this->normalizePrices($attributes);
            $product->update($attributes);
            $this->syncVariantPrices($product, $attributes);

            if ($tagIds !== null) {
                $product->tags()->sync($tagIds);
            }

            $this->applyOptions($product, $attributeIds, $axes, $overrides);

            return $product;
        });
    }

    /**
     * يطبّق الخيارات على المنتج: إن وُجدت محاور بقيم → يولّد مصفوفة المتغيّرات؛
     * وإلا يزامن السمات المطبّقة (منتج بسيط) كما في السابق.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     */
    private function applyOptions(Product $product, ?array $attributeIds, ?array $axes, array $overrides): void
    {
        if (is_array($axes) && $this->hasSelectedValues($axes)) {
            $this->variants->syncMatrix($product, $axes, $overrides);

            return;
        }

        if ($attributeIds !== null) {
            $product->attributes()->sync($attributeIds);
        }
    }

    /** هل تحوي المحاور قيمة واحدة على الأقل؟ */
    private function hasSelectedValues(array $axes): bool
    {
        foreach ($axes as $values) {
            if (array_filter((array) $values) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * أعمدة السعر غير القابلة للـ null (لها default 0) — لا يجوز كتابة null عليها.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizePrices(array $attributes): array
    {
        foreach (['retail_price', 'cost_price'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] === null) {
                $attributes[$key] = 0;
            }
        }

        return $attributes;
    }

    /**
     * مزامنة أسعار المنتج مع متغيّره الافتراضي — الموقع والطلبات يقرآن سعر المتغيّر.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncVariantPrices(Product $product, array $data): void
    {
        $priceData = array_intersect_key($data, array_flip(['retail_price', 'promo_price', 'cost_price', 'wholesale_price']));
        if ($priceData === []) {
            return;
        }
        $variant = $product->defaultVariant()->first();
        $variant?->update($priceData);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * كل منتج يملك متغيّرًا افتراضيًا واحدًا على الأقل — أساس المخزون (ADR-024).
     */
    public function ensureDefaultVariant(Product $product): void
    {
        if ($product->variants()->exists()) {
            return;
        }

        $product->variants()->create([
            'sku' => $product->sku,
            'name' => $product->name,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * يفصل مصفوفات العلاقات عن سمات النموذج.
     *
     * @return array{0: ?array, 1: ?array, 2: ?array, 3: array, 4: array}
     */
    private function extractRelations(array $data): array
    {
        $tagIds = array_key_exists('tag_ids', $data) ? (array) $data['tag_ids'] : null;
        $attributeIds = array_key_exists('attribute_ids', $data) ? (array) $data['attribute_ids'] : null;
        $axes = array_key_exists('axes', $data) ? (array) $data['axes'] : null;
        $overrides = array_key_exists('variants', $data) ? (array) $data['variants'] : [];

        unset($data['tag_ids'], $data['attribute_ids'], $data['axes'], $data['variants']);

        return [$tagIds, $attributeIds, $axes, $overrides, $data];
    }

    /** يولّد رمز منتج (SKU) فريدًا عند عدم إدخاله في النموذج المبسّط. */
    private function generateSku(): string
    {
        do {
            $sku = 'P-'.strtoupper(Str::random(8));
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    private function resolveSlug(array $data, ?Product $product = null): string
    {
        $source = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($data['name'] ?? $product?->name ?? '');

        return SlugGenerator::make(Product::class, $source, $product?->id);
    }
}
