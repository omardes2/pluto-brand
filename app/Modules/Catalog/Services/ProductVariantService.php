<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * توليد ومزامنة متغيّرات المنتج من محاور الخيارات (مقاس/لون…) — الضرب الديكارتي.
 * كل عملية داخل معاملة ذرّية (المبدأ 7). المخزون يمرّ حصريًا عبر InventoryService.
 */
class ProductVariantService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * يزامن متغيّرات المنتج مع مصفوفة المحاور المختارة.
     *
     * @param  array<int, array<int>>  $axes  خريطة attribute_id => [value_id, …]
     * @param  array<int, array<string, mixed>>  $overrides  قائمة صفوف لكل تركيبة: value_ids + sku/retail_price/promo_price/is_active/quantity
     * @return array<string, ProductVariant> المتغيّرات النشطة النهائية مفهرسة بمفتاح التركيبة
     */
    public function syncMatrix(Product $product, array $axes, array $overrides = []): array
    {
        return DB::transaction(function () use ($product, $axes, $overrides) {
            // القيم المختارة فعليًا لكل محور، مرتّبة حسب ترتيب السمة ثم القيمة.
            $axisValues = $this->resolveAxisValues($axes);

            // لا محاور → منتج بسيط: اكتفِ بالمتغيّر الافتراضي كما هو.
            if ($axisValues === []) {
                return [];
            }

            $product->attributes()->sync(array_keys($axisValues));

            $ovByKey = $this->indexOverrides($overrides);
            $warehouse = $this->defaultWarehouse();
            $combos = $this->cartesian($axisValues); // كل عنصر: قائمة قيم (ProductAttributeValue) مرتّبة
            $existing = $this->existingByKey($product);
            $desiredKeys = [];
            $result = [];

            foreach ($combos as $combo) {
                $valueIds = array_map(fn ($v) => $v->id, $combo);
                $key = $this->comboKey($valueIds);
                $desiredKeys[] = $key;
                $label = collect($combo)->map(fn ($v) => $v->label ?: $v->value)->implode(' / ');
                $ov = $ovByKey[$key] ?? [];

                $variant = $existing[$key] ?? new ProductVariant(['product_id' => $product->id]);
                $variant->product_id = $product->id;
                $variant->name = $label;
                $variant->is_active = array_key_exists('is_active', $ov) ? (bool) $ov['is_active'] : true;
                $variant->retail_price = $ov['retail_price'] ?? $variant->retail_price ?? $product->retail_price ?? 0;
                $variant->promo_price = array_key_exists('promo_price', $ov) ? ($ov['promo_price'] ?: null) : $variant->promo_price;
                if (! $variant->exists) {
                    $variant->is_default = false;
                    $variant->sku = $this->uniqueSku($product, $combo, $ov['sku'] ?? null);
                } elseif (filled($ov['sku'] ?? null)) {
                    $variant->sku = $ov['sku'];
                }
                $variant->save();

                // مزامنة قيم المحاور (attribute_id في الجدول الوسيط).
                $pivot = [];
                foreach ($combo as $value) {
                    $pivot[$value->id] = ['attribute_id' => $value->attribute_id];
                }
                $variant->attributeValues()->sync($pivot);

                // المخزون لكل متغيّر عبر تسوية مخزنية (إن قُدّمت كمية ووُجد مستودع).
                if ($warehouse && isset($ov['quantity']) && $ov['quantity'] !== '') {
                    $this->setVariantStock($variant, $warehouse, (float) $ov['quantity']);
                }

                $result[$key] = $variant;
            }

            // تعطيل التركيبات القديمة غير المطلوبة (لا حذف — حفاظًا على الطلبات/المخزون).
            foreach ($existing as $key => $variant) {
                if (! in_array($key, $desiredKeys, true)) {
                    $variant->update(['is_active' => false, 'is_default' => false]);
                }
            }

            $this->retireLegacyPlaceholder($product);
            $this->ensureSingleDefault($product, $result);

            return $result;
        });
    }

    /**
     * يضبط الكمية المتوفّرة لمتغيّر على مستودع عبر تسوية مخزنية (تظهر في سجلّ المخزن).
     * لا يكتب على جداول المخزون مباشرة — يمرّ عبر InventoryService (قفل + WAC + سجلّ).
     */
    public function setVariantStock(ProductVariant $variant, Warehouse $warehouse, float $target, ?float $unitCost = null): void
    {
        $current = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $delta = round($target - $current, 2);
        if (abs($delta) < 0.001) {
            return;
        }

        $opts = ['reason' => 'variant_matrix:'.$variant->sku];
        $delta > 0
            ? $this->inventory->adjustIn($variant, $warehouse, $delta, $unitCost, $opts)
            : $this->inventory->adjustOut($variant, $warehouse, -$delta, $opts);
    }

    /**
     * يفهرس صفوف التخصيص بمفتاح التركيبة (من value_ids).
     *
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<string, array<string, mixed>>
     */
    private function indexOverrides(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $row) {
            $valueIds = array_filter((array) ($row['value_ids'] ?? []));
            if ($valueIds === []) {
                continue;
            }
            $out[$this->comboKey($valueIds)] = $row;
        }

        return $out;
    }

    /** المستودع الافتراضي (النظام أحادي المستودع حاليًا). */
    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    /**
     * يحوّل خريطة المحاور إلى قيم فعلية مرتّبة.
     *
     * @param  array<int, array<int>>  $axes
     * @return array<int, array<int, ProductAttributeValue>> attribute_id => [قيم مرتّبة]
     */
    private function resolveAxisValues(array $axes): array
    {
        $valueIds = collect($axes)->flatten()->filter()->unique()->all();
        if ($valueIds === []) {
            return [];
        }

        $values = ProductAttributeValue::with('attribute')->whereIn('id', $valueIds)->get()->keyBy('id');

        $out = [];
        foreach ($axes as $attributeId => $ids) {
            $picked = collect($ids)->filter()
                ->map(fn ($id) => $values->get((int) $id))
                ->filter(fn ($v) => $v && (int) $v->attribute_id === (int) $attributeId)
                ->sortBy('sort_order')->values()->all();
            if ($picked !== []) {
                $out[(int) $attributeId] = $picked;
            }
        }

        // ترتيب المحاور حسب ترتيب السمة (لثبات تسمية التركيبات).
        uksort($out, function ($a, $b) use ($out) {
            $sa = $out[$a][0]->attribute?->sort_order ?? 0;
            $sb = $out[$b][0]->attribute?->sort_order ?? 0;

            return $sa <=> $sb ?: $a <=> $b;
        });

        return $out;
    }

    /**
     * الضرب الديكارتي لقيم المحاور.
     *
     * @param  array<int, array<int, ProductAttributeValue>>  $axisValues
     * @return array<int, array<int, ProductAttributeValue>>
     */
    private function cartesian(array $axisValues): array
    {
        $result = [[]];
        foreach ($axisValues as $values) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($values as $value) {
                    $next[] = [...$combo, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * المتغيّرات الحالية التي تحمل قيم سمات، مفهرسة بمفتاح التركيبة.
     *
     * @return array<string, ProductVariant>
     */
    private function existingByKey(Product $product): array
    {
        $out = [];
        $variants = $product->variants()->with('attributeValues')->get();
        foreach ($variants as $variant) {
            if ($variant->attributeValues->isEmpty()) {
                continue; // متغيّر بسيط/افتراضي بلا خيارات — يُعالَج في retireLegacyPlaceholder.
            }
            $out[$this->comboKey($variant->attributeValues->pluck('id')->all())] = $variant;
        }

        return $out;
    }

    /**
     * مفتاح تركيبة ثابت من معرّفات القيم (بغضّ النظر عن ترتيب الإرفاق).
     * البادئة «c» تمنع تحويل PHP للمفاتيح الرقمية إلى int (يكسر مقارنة strict).
     */
    private function comboKey(array $valueIds): string
    {
        $ids = array_map('intval', $valueIds);
        sort($ids);

        return 'c'.implode('-', $ids);
    }

    /** يولّد SKU فريدًا للتركيبة (أو يتحقّق من فرادة المخصّص). */
    private function uniqueSku(Product $product, array $combo, ?string $preferred): string
    {
        if (filled($preferred)) {
            return $preferred;
        }

        $suffix = collect($combo)->map(fn ($v) => Str::slug($v->value) ?: $v->id)->implode('-');
        $base = $product->sku.'-'.strtoupper($suffix);
        $sku = $base;
        $i = 1;
        while (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$i);
        }

        return $sku;
    }

    /**
     * يتقاعد المتغيّر الافتراضي القديم (placeholder بلا خيارات) عند تحويل المنتج لمتعدّد الخيارات:
     * يُحذف نهائيًا إن لم يكن مرجعًا لطلبات/مخزون، وإلا يُعطَّل ويُنزَع عنه الافتراضي.
     */
    private function retireLegacyPlaceholder(Product $product): void
    {
        $placeholders = $product->variants()->with('attributeValues')->get()
            ->filter(fn ($v) => $v->attributeValues->isEmpty());

        foreach ($placeholders as $variant) {
            $referenced = DB::table('order_items')->where('variant_id', $variant->id)->exists()
                || InventoryStock::where('variant_id', $variant->id)->where('on_hand', '!=', 0)->exists();

            if ($referenced) {
                $variant->update(['is_active' => false, 'is_default' => false]);
            } else {
                InventoryStock::where('variant_id', $variant->id)->delete();
                $variant->forceDelete();
            }
        }
    }

    /**
     * يضمن وجود متغيّر افتراضي نشط واحد بالضبط (يعتمد عليه الموقع والتقارير).
     *
     * @param  array<string, ProductVariant>  $desired
     */
    private function ensureSingleDefault(Product $product, array $desired): void
    {
        $fresh = $product->variants()->where('is_active', true)->orderBy('id')->get();
        if ($fresh->isEmpty()) {
            return;
        }

        $default = $fresh->firstWhere('is_default', true) ?? $fresh->first();
        $product->variants()->where('id', '!=', $default->id)->update(['is_default' => false]);
        if (! $default->is_default) {
            $default->update(['is_default' => true]);
        }
    }
}
