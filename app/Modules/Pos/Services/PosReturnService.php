<?php

namespace App\Modules\Pos\Services;

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
    ) {}

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

        $order->loadMissing('items');

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
            $refund = round((float) $request->items->sum(fn ($ri) => (float) $ri->qty * (float) $ri->unit_price_snapshot), 2);

            // استرداد نقدي من الدرج (حركة refund تُخفّض النقد المتوقّع).
            $this->shifts->recordRefund($shift, $order, $refund, $note);

            return ['request' => $request->fresh(), 'refund' => $refund];
        });
    }
}
