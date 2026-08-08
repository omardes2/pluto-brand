<?php

namespace App\Modules\Pos\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Support\Collection;

/**
 * كتالوج نقطة البيع — بحث/عرض المنتجات القابلة للبيع مع سعرها ومتوفّرها في مستودع الوردية.
 * السعر الفعّال = العرض الترويجي إن وُجد (> 0) وإلا سعر التجزئة (نفس منطق سلة المتجر — بلا تكرار سلوك).
 */
class PosCatalogService
{
    public function sellingPrice(ProductVariant $variant): float
    {
        $promo = (float) $variant->promo_price;

        return $promo > 0 ? $promo : (float) $variant->retail_price;
    }

    /**
     * قائمة المنتجات القابلة للبيع (بحث بالاسم/الكود/الباركود، وفلترة بالفئة).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(int $warehouseId, ?string $q = null, ?int $categoryId = null, int $limit = 40): array
    {
        $query = ProductVariant::query()
            ->with(['product:id,name,category_id,is_active', 'product.category:id,name'])
            ->whereHas('product', function ($p) use ($categoryId) {
                $p->where('is_active', true);
                if ($categoryId) {
                    $p->where('category_id', $categoryId);
                }
            });

        if ($q !== null && $q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('name', 'like', "%{$q}%")
                        ->orWhere('barcode', 'like', "%{$q}%"));
            });
        }

        $variants = $query->limit($limit)->get();

        return $this->mapVariants($variants, $warehouseId);
    }

    /** بحث دقيق بالباركود (متغيّر أو منتج) — يُرجع بندًا واحدًا أو null. */
    public function findByBarcode(int $warehouseId, string $code): ?array
    {
        $variant = ProductVariant::query()
            ->with(['product:id,name,category_id,is_active', 'product.category:id,name'])
            ->where('barcode', $code)
            ->orWhereHas('product', fn ($p) => $p->where('barcode', $code)->where('is_active', true))
            ->first();

        if (! $variant) {
            return null;
        }

        return $this->mapVariants(collect([$variant]), $warehouseId)[0] ?? null;
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
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function mapVariants($variants, int $warehouseId): array
    {
        $ids = $variants->pluck('id')->all();
        $stocks = InventoryStock::where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', $ids)->get()->keyBy('variant_id');

        return $variants->map(function (ProductVariant $v) use ($stocks) {
            $stock = $stocks->get($v->id);
            $available = $stock ? (float) $stock->on_hand - (float) $stock->reserved : 0.0;
            $price = $this->sellingPrice($v);
            $retail = (float) $v->retail_price;
            $name = $v->product?->name ?? $v->sku ?? '';
            if (! empty($v->name)) {
                $name .= ' — '.$v->name;
            }

            return [
                'variant_id' => $v->id,
                'name' => $name,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => round($price, 2),
                'retail' => round($retail, 2),
                'has_promo' => $price + 0.001 < $retail,
                'stock' => round($available, 2),
                'category_id' => $v->product?->category_id,
                'category_name' => $v->product?->category?->name,
            ];
        })->values()->all();
    }
}
