<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Pos\Models\PosReturnLine;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use Illuminate\Database\Migrations\Migration;

/**
 * تعبئة تلقائية لبنود المرتجعات بدون فاتورة القديمة (قبل جدول pos_return_lines) من حركات
 * المخزون، حتى تُخصم من المبيعات/التكلفة/الربح في التقارير دون أمر يدوي. idempotent:
 * يتخطّى الورديات التي لها بنود مرتجع بدون فاتورة مسبقًا. سعر الوحدة يُشتقّ من إجمالي
 * استرداد الوردية بدون فاتورة ÷ الكمية؛ والتكلفة من حركة المخزون وإلا متوسط تكلفة الصنف.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = InventoryMovement::query()
            ->where('reason', 'like', 'pos_return_no_invoice:%')
            ->get()
            ->groupBy('reference_id');

        foreach ($groups as $shiftId => $rows) {
            if (! $shiftId || PosReturnLine::where('pos_shift_id', $shiftId)->whereNull('order_id')->exists()) {
                continue;
            }
            $shift = PosShift::find($shiftId);
            if (! $shift) {
                continue;
            }

            $totalRefund = (float) PosShiftMovement::where('pos_shift_id', $shiftId)
                ->where('type', PosShiftMovement::TYPE_REFUND)->whereNull('order_id')->sum('amount');
            $totalQty = (float) $rows->sum('qty');
            if ($totalQty <= 0) {
                continue;
            }
            $unitPrice = round($totalRefund / $totalQty, 2);

            foreach ($rows as $mv) {
                $cost = (float) $mv->unit_cost;
                if ($cost <= 0) {
                    $cost = (float) (ProductVariant::whereKey($mv->variant_id)->value('average_cost') ?? 0);
                }
                PosReturnLine::create([
                    'pos_shift_id' => $shiftId,
                    'order_id' => null,
                    'variant_id' => $mv->variant_id,
                    'qty' => (float) $mv->qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $cost,
                    'created_at' => $mv->created_at ?? now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // لا تراجُع — البنود المُعبّأة تبقى (تصحيح بيانات لمرّة واحدة).
    }
};
