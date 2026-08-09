<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Support\Collection;

/**
 * خدمات مستودع للقراءة (Phase 5 / ADR-043) — تنبيهات نقص، بحث باركود، ولوحة مؤشّرات.
 * **للقراءة فقط**؛ تعيد استخدام نموذج المخزون القائم (reorder_level، available) دون تكرار.
 */
class WarehouseService
{
    /** بحث المتغيّر بالباركود الدقيق (المتغيّر ثم المنتج → متغيّره الافتراضي). */
    public function findByBarcode(string $barcode): ?ProductVariant
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $variant = ProductVariant::where('barcode', $barcode)->first();
        if ($variant) {
            return $variant;
        }

        return Product::where('barcode', $barcode)->first()?->defaultVariant;
    }

    /**
     * الحدّ الافتراضي العام للمخزون المنخفض (من الإعدادات) — يُطبَّق على أي صنف ليس له
     * حدّ إعادة طلب خاص (reorder_level). صفر = تعطيل الحدّ العام (تنبيه الأصناف ذات
     * الحدّ الخاص فقط أو التي نفد مخزونها).
     */
    public function defaultThreshold(): float
    {
        return max(0.0, (float) Settings::get('inventory.low_stock_threshold', 5));
    }

    /** أصناف تحت حدّ إعادة الطلب (تنبيهات نقص) — حدّ خاص أو الحدّ الافتراضي العام. */
    public function lowStock(Warehouse $warehouse): Collection
    {
        $threshold = $this->defaultThreshold();

        return InventoryStock::with('variant.product')
            ->where('warehouse_id', $warehouse->id)
            ->whereRaw('on_hand <= COALESCE(reorder_level, ?)', [$threshold])
            ->orderBy('on_hand')
            ->get();
    }

    public function lowStockCount(Warehouse $warehouse): int
    {
        $threshold = $this->defaultThreshold();

        return InventoryStock::where('warehouse_id', $warehouse->id)
            ->whereRaw('on_hand <= COALESCE(reorder_level, ?)', [$threshold])
            ->count();
    }

    /** مؤشّرات لوحة المستودع. @return array<string, float|int> */
    public function dashboard(Warehouse $warehouse): array
    {
        $stocks = InventoryStock::where('warehouse_id', $warehouse->id);

        return [
            'skus' => (int) (clone $stocks)->count(),
            'on_hand' => round((float) (clone $stocks)->sum('on_hand'), 3),
            'reserved' => round((float) (clone $stocks)->sum('reserved'), 3),
            'available' => round((float) (clone $stocks)->sum('on_hand') - (float) (clone $stocks)->sum('reserved'), 3),
            'damaged' => round((float) (clone $stocks)->sum('damaged'), 3),
            'stock_value' => round((float) (clone $stocks)->selectRaw('SUM(on_hand * average_cost) as v')->value('v'), 2),
            'low_stock' => $this->lowStockCount($warehouse),
            'open_counts' => (int) InventoryCount::where('warehouse_id', $warehouse->id)->whereIn('status', ['counting', 'review'])->count(),
        ];
    }
}
