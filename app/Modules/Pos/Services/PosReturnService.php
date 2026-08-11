<?php

namespace App\Modules\Pos\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Pos\Models\PosReturnLine;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Returns\Support\ReturnWorkflow;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إرجاع/تبديل نقطة البيع — مسار فوري مبسّط يقود محرّك المرتجعات (RMA) بالكامل
 * (create→approve→receive→inspect→complete) لإعادة استخدام منطق المخزون/الحالة/العمولة
 * دون تكرار. الاسترداد النقدي يتم على مستوى POS من درج الوردية (طلبات POS بلا سجلات Payment).
 */
class PosReturnService
{
    public function __construct(
        private readonly ReturnService $rma,
        private readonly PosShiftService $shifts,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * إرجاع بدون فاتورة أصلية — إعادة الأصناف للمخزون (صالح/تالف) واسترداد نقدي من الدرج بالسعر المُدخَل.
     * لا يرتبط بطلب؛ يُسجَّل كحركة استرداد على الوردية (مسار مبسّط بلا موافقة).
     *
     * @param  array<int, array{variant_id:int, qty:float, unit_price:float, condition?:string}>  $lines
     * @return array{refund: float}
     */
    public function refundWithoutInvoice(PosShift $shift, array $lines, ?string $note = null): array
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('الوردية مغلقة.')]);
        }
        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => __('حدّد صنفًا واحدًا على الأقل للإرجاع.')]);
        }

        $warehouse = Warehouse::findOrFail($shift->warehouse_id);

        return DB::transaction(function () use ($shift, $lines, $warehouse, $note) {
            $refund = 0.0;
            foreach ($lines as $line) {
                $variant = ProductVariant::findOrFail((int) $line['variant_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages(['qty' => __('الكمية يجب أن تكون أكبر من صفر.')]);
                }

                $opts = ['reference_type' => PosShift::class, 'reference_id' => $shift->id, 'reason' => 'pos_return_no_invoice:'.$shift->number];
                if (($line['condition'] ?? 'sellable') === 'damaged') {
                    $this->inventory->returnToDamaged($variant, $warehouse, $qty, $opts);
                } else {
                    $this->inventory->returnToStock($variant, $warehouse, $qty, null, $opts);
                }

                // بند مرتجع قابل للاستعلام — لخصم المبيعات والتكلفة في التقارير.
                PosReturnLine::create([
                    'pos_shift_id' => $shift->id,
                    'order_id' => null,
                    'variant_id' => $variant->id,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => (float) ($variant->average_cost ?: $variant->cost_price ?: 0),
                    'created_at' => now(),
                ]);

                $refund += $qty * $unitPrice;
            }
            $refund = round($refund, 2);

            $this->shifts->recordRefund($shift, null, $refund, $note ?? __('إرجاع بدون فاتورة'));

            return ['refund' => $refund];
        });
    }

    /**
     * إرجاع بالفاتورة الأصلية مع استرداد نقدي من درج الوردية.
     *
     * @param  array<int, array{order_item_id:int, qty:float, condition?:string}>  $lines
     * @return array{request: ReturnRequest, refund: float}
     */
    public function refund(PosShift $shift, Order $order, array $lines, ?string $reasonCode = null, ?string $note = null): array
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('الوردية مغلقة.')]);
        }
        if ($order->channel !== 'pos') {
            throw ValidationException::withMessages(['order' => __('الفاتورة ليست من نقطة البيع.')]);
        }
        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => __('حدّد صنفًا واحدًا على الأقل للإرجاع.')]);
        }

        $order->loadMissing('items.variant');

        return DB::transaction(function () use ($shift, $order, $lines, $reasonCode, $note) {
            $data = [
                'type' => 'return',
                'reason_code' => in_array($reasonCode, ReturnWorkflow::REASONS, true) ? $reasonCode : 'changed_mind',
                'resolution' => 'no_refund', // الاسترداد النقدي يتم على مستوى POS من الدرج
                'notes' => $note,
            ];
            $items = array_map(fn ($l) => [
                'order_item_id' => (int) $l['order_item_id'],
                'qty' => (float) $l['qty'],
            ], $lines);

            // قود سير RMA بالكامل.
            $request = $this->rma->create($order, $data, $items, (int) now()->year);
            $this->rma->approve($request);
            $this->rma->receive($request);

            $request->loadMissing('items');
            $conditionByOrderItem = [];
            foreach ($lines as $l) {
                $conditionByOrderItem[(int) $l['order_item_id']] = $l['condition'] ?? 'sellable';
            }

            $inspections = [];
            foreach ($request->items as $ri) {
                $damaged = ($conditionByOrderItem[$ri->order_item_id] ?? 'sellable') === 'damaged';
                $inspections[$ri->id] = $damaged
                    ? ['inspection_result' => 'damaged', 'inventory_route' => 'damaged']
                    : ['inspection_result' => 'sellable', 'inventory_route' => 'restock'];
            }
            $this->rma->inspect($request, $inspections);
            $this->rma->complete($request);

            // مبلغ الاسترداد = Σ(كمية × سعر الوحدة وقت البيع) من بنود المرتجع.
            // ملاحظة: الإرجاع بفاتورة يُخصم في التقارير عبر returned_qty على بنود الطلب،
            // فلا يُسجَّل هنا في pos_return_lines (المخصّص للإرجاع بدون فاتورة) تفاديًا للازدواج.
            $refund = round((float) $request->items->sum(fn ($ri) => (float) $ri->qty * (float) $ri->unit_price_snapshot), 2);

            // استرداد نقدي من الدرج (حركة refund تُخفّض النقد المتوقّع).
            $this->shifts->recordRefund($shift, $order, $refund, $note);

            return ['request' => $request->fresh(), 'refund' => $refund];
        });
    }
}
