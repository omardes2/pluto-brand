<?php

namespace App\Modules\Pos\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Support\Collection;

/**
 * كتالوج نقطة البيع — بحث/عرض المنتجات القابلة للبيع مع سعرها ومتوفّرها في مستودع الوردية.
 * تُجمَّع النتائج على مستوى **المنتج** (بطاقة واحدة لكل منتج) مع متغيّراته (ألوان/مقاسات)
 * ليختار الكاشير اللون ثم المقاس، بدل بطاقة لكل تركيبة.
 * السعر الفعّال = العرض الترويجي إن وُجد (> 0) وإلا سعر التجزئة (نفس منطق سلة المتجر).
 */
class PosCatalogService
{
    public function sellingPrice(ProductVariant $variant): float
    {
        $promo = (float) $variant->promo_price;

        return $promo > 0 ? $promo : (float) $variant->retail_price;
    }

    /**
     * منتجات قابلة للبيع (بحث بالاسم/الكود/الباركود، وفلترة بالفئة) مجمّعة على مستوى المنتج.
     * يُدرَج المنتج فقط إن كان له متغيّر نشط بمخزون متوفّر في مستودع الوردية.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(int $warehouseId, ?string $q = null, ?int $categoryId = null, int $limit = 40): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($categoryId, fn ($p) => $p->where('category_id', $categoryId))
            ->when($q !== null && $q !== '', function ($p) use ($q) {
                $p->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('barcode', 'like', "%{$q}%")
                        ->orWhereHas('variants', fn ($v) => $v
                            ->where('sku', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%"));
                });
            })
            // منتج له متغيّر نشط بمخزون متوفّر (on_hand − reserved > 0) في مستودع الوردية.
            ->whereHas('variants', fn ($v) => $v->where('is_active', true)
                ->whereHas('inventoryStocks', fn ($s) => $s
                    ->where('warehouse_id', $warehouseId)->whereRaw('on_hand - reserved > 0')))
            ->with([
                'primaryImage',
                'category:id,name',
                'variants' => fn ($v) => $v->where('is_active', true)->with('attributeValues.attribute'),
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $stocks = $this->stocksFor($products, $warehouseId);

        return $products->map(fn (Product $p) => $this->mapProduct($p, $stocks))->values()->all();
    }

    /** بحث دقيق بالباركود (متغيّر أو منتج) — يُرجع متغيّرًا واحدًا جاهزًا للإضافة أو null. */
    public function findByBarcode(int $warehouseId, string $code): ?array
    {
        $variant = ProductVariant::query()
            ->where('is_active', true) // لا يُباع متغيّر مُعطّل بالباركود
            ->with(['product:id,name'])
            ->where(function ($w) use ($code) {
                $w->where('barcode', $code)
                    ->orWhere('sku', $code) // يُطابق ملصق الباركود المطبوع بالـSKU عند غياب باركود خاص
                    ->orWhereHas('product', fn ($p) => $p->where('barcode', $code)->where('is_active', true));
            })
            ->first();

        if (! $variant) {
            return null;
        }

        $stock = InventoryStock::where('warehouse_id', $warehouseId)->where('variant_id', $variant->id)->first();
        $available = $stock ? (float) $stock->on_hand - (float) $stock->reserved : 0.0;

        return [
            'variant_id' => $variant->id,
            'name' => $this->variantName($variant->product, $variant),
            'price' => round($this->sellingPrice($variant), 2),
            'stock' => round($available, 2),
            'barcode' => $variant->barcode,
        ];
    }

    /**
     * أصناف لطباعة ملصقات الباركود — كل متغيّر نشط بكود فعّال (باركوده أو باركود منتجه أو SKU).
     * لا يعتمد على وردية/مستودع؛ يشمل الأصناف بلا مخزون أيضًا.
     *
     * @return array<int, array<string, mixed>>
     */
    public function barcodeItems(?string $q = null, ?int $categoryId = null, int $limit = 1000): array
    {
        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->when($categoryId, fn ($query) => $query
                ->whereHas('product', fn ($p) => $p->where('category_id', $categoryId)))
            ->when($q !== null && $q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('barcode', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%"));
                });
            })
            ->with(['product:id,name,barcode,category_id', 'product.category:id,name'])
            ->limit($limit)
            ->get();

        return $variants
            ->map(function (ProductVariant $v) {
                $code = $v->barcode ?: ($v->product?->barcode ?: $v->sku);
                $option = (! empty($v->name) && $v->name !== $v->product?->name) ? $v->name : null;

                return [
                    'variant_id' => $v->id,
                    'product' => $v->product?->name ?? $v->sku,
                    'option' => $option,
                    'barcode' => (string) $code,
                    'price' => round($this->sellingPrice($v), 2),
                    'category' => $v->product?->category?->name,
                ];
            })
            ->filter(fn ($r) => $r['barcode'] !== '')
            ->sortBy([['product', 'asc'], ['option', 'asc']])
            ->values()
            ->all();
    }

    /** @return array<int, array{id:int, name:string}> */
    public function categories(): array
    {
        return Category::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, InventoryStock>
     */
    private function stocksFor($products, int $warehouseId)
    {
        $variantIds = $products->flatMap(fn (Product $p) => $p->variants->pluck('id'))->all();

        return InventoryStock::where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', $variantIds)->get()->keyBy('variant_id');
    }

    /**
     * يبني حمولة منتج: بيانات البطاقة + محاور الخيارات (ألوان/مقاسات) + متغيّراته.
     *
     * @return array<string, mixed>
     */
    private function mapProduct(Product $p, $stocks): array
    {
        $variants = $p->variants->map(function (ProductVariant $v) use ($p, $stocks) {
            $stock = $stocks->get($v->id);
            $available = $stock ? (float) $stock->on_hand - (float) $stock->reserved : 0.0;
            $price = $this->sellingPrice($v);
            $retail = (float) $v->retail_price;

            $values = [];
            foreach ($v->attributeValues as $av) {
                $values[(int) $av->attribute_id] = [
                    'value_id' => (int) $av->id,
                    'label' => $av->label ?: $av->value,
                    'color_hex' => $av->color_hex,
                ];
            }

            return [
                'variant_id' => $v->id,
                'name' => $this->variantName($p, $v),
                'price' => round($price, 2),
                'retail' => round($retail, 2),
                'has_promo' => $price + 0.001 < $retail,
                'stock' => round($available, 2),
                'barcode' => $v->barcode,
                'values' => $values, // attribute_id => {value_id,label,color_hex}
            ];
        })->values();

        return [
            'product_id' => $p->id,
            'name' => $p->name,
            'image' => $p->primaryImage?->url(),
            'price' => round((float) $variants->min('price'), 2),
            'retail' => round((float) $variants->min('retail'), 2),
            'has_promo' => (bool) $variants->contains(fn ($x) => $x['has_promo']),
            'stock' => round((float) $variants->sum('stock'), 2),
            'category_id' => $p->category_id,
            'category_name' => $p->category?->name,
            'axes' => $this->axes($p),
            'variants' => $variants->all(),
        ];
    }

    /**
     * محاور الخيارات المتوفّرة للمنتج (مقاس/لون…)، مرتّبة بجعل محور اللون أولًا (للعرض كقائمة علوية).
     *
     * @return array<int, array<string, mixed>>
     */
    private function axes(Product $p): array
    {
        $byId = [];
        foreach ($p->variants as $v) {
            foreach ($v->attributeValues as $av) {
                $aid = (int) $av->attribute_id;
                if (! isset($byId[$aid])) {
                    $byId[$aid] = [
                        'id' => $aid,
                        'name' => $av->attribute?->name,
                        'is_color' => ! empty($av->color_hex) || $av->attribute?->type === 'color',
                        'sort' => (int) ($av->attribute?->sort_order ?? 0),
                    ];
                }
            }
        }

        return collect($byId)
            ->sortBy(fn ($a) => sprintf('%d-%05d', $a['is_color'] ? 0 : 1, $a['sort']))
            ->values()
            ->map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'is_color' => $a['is_color']])
            ->all();
    }

    /** اسم العرض: اسم المنتج + تسمية الخيار إن اختلفت عن اسم المنتج (المتغيّر الافتراضي يحمل الاسم نفسه). */
    private function variantName(?Product $p, ProductVariant $v): string
    {
        $name = $p?->name ?? $v->sku ?? '';
        if (! empty($v->name) && $v->name !== $p?->name) {
            $name .= ' — '.$v->name;
        }

        return $name;
    }
}
