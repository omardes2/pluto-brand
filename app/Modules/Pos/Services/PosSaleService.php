<?php

namespace App\Modules\Pos\Services;

use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خدمة البيع في نقطة البيع (POS). عملية بيع مباشرة داخل المحل تُنسّق الخدمات القائمة
 * داخل معاملة واحدة (لا تكرار منطق):
 *   إنشاء طلب channel=pos → fulfillDirect (ترحيل محاسبي + خصم مخزون + تسليم فوري)
 *   → collect (تحصيل كامل في صندوق الوردية) → تسجيل حركة الدرج وتحديث أرصدة الوردية.
 *
 * حدّ سعر الجملة يُطبَّق تلقائيًا داخل OrderService::create لكل الأصناف (لا بيع بأقل منه).
 */
class PosSaleService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPaymentService $payments,
        private readonly PosShiftService $shifts,
    ) {}

    /**
     * @param  array{items:array<int,array{variant_id:int,qty:float,unit_price:float,discount?:float}>,
     *     customer_id?:int|null, customer_name?:string, customer_phone?:string,
     *     discount?:float, payment_method?:string, note?:string}  $payload
     */
    public function sell(PosShift $shift, array $payload): Order
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('لا يمكن البيع على وردية مغلقة.')]);
        }

        $method = $payload['payment_method'] ?? 'cash';
        if (! in_array($method, ['cash', 'card', 'credit'], true)) {
            throw ValidationException::withMessages(['payment_method' => __('طريقة الدفع يجب أن تكون نقدي أو بطاقة أو آجل.')]);
        }
        if ($method === 'credit' && empty($payload['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => __('البيع الآجل (ذمم) يتطلّب اختيار عميل مسجّل.')]);
        }

        $items = $this->applyOrderDiscount($payload['items'] ?? [], (float) ($payload['discount'] ?? 0));
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => __('لا توجد أصناف في الفاتورة.')]);
        }

        return DB::transaction(function () use ($shift, $payload, $items, $method) {
            $data = [
                'branch_id' => $shift->branch_id,
                'warehouse_id' => $shift->warehouse_id,
                'customer_id' => $payload['customer_id'] ?? null,
                'customer_name' => $payload['customer_name']
                    ?? (string) Settings::get('pos.default_customer_name', 'عميل نقدي'),
                'customer_phone' => $payload['customer_phone'] ?? '',
                'channel' => 'pos',
                'notes' => $payload['note'] ?? null,
            ];

            $order = $this->orders->create($data, $items, (int) now()->year);
            $order->update(['pos_shift_id' => $shift->id]);

            // لقطة تكلفة الوحدة (WAC للمستودع) على البنود لحساب الأرباح في تقارير الوردية.
            $order->loadMissing('items');
            $stocks = InventoryStock::where('warehouse_id', $shift->warehouse_id)
                ->whereIn('variant_id', $order->items->pluck('variant_id'))->get()->keyBy('variant_id');
            foreach ($order->items as $item) {
                $cost = optional($stocks->get($item->variant_id))->average_cost;
                if ($cost !== null) {
                    $item->update(['wholesale_cost_snapshot' => $cost]);
                }
            }

            // بيع مباشر: تأكيد + حجز + شحن + تسليم فوري (ترحيل محاسبي وخصم مخزون).
            $this->orders->fulfillDirect($order);
            $order->refresh();

            // تحصيل كامل المبلغ في صندوق الوردية — عدا الآجل (ذمم) فيبقى غير مدفوع على حساب العميل.
            if ($method !== 'credit') {
                $this->payments->collect($order, $shift->treasury_id, (float) $order->total);
            }

            // تسجيل حركة الدرج وتحديث أرصدة الوردية (الآجل يُسجَّل كـ credit_sale بلا أثر نقدي).
            $this->shifts->recordSale($shift->fresh(), $order->fresh(), $method);

            return $order->fresh(['items']);
        });
    }

    /**
     * توزيع خصم الفاتورة على البنود تناسبيًا بقيمة كل بند (فوق أي خصم بند قائم)،
     * مع امتصاص كسر التقريب في البند الأخير — فيبقى discount_total مطابقًا للخصم المطلوب.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function applyOrderDiscount(array $items, float $orderDiscount): array
    {
        if ($orderDiscount <= 0 || empty($items)) {
            return array_values($items);
        }

        $items = array_values($items);
        $subtotal = array_sum(array_map(fn ($i) => (float) $i['qty'] * (float) $i['unit_price'], $items));
        if ($subtotal <= 0) {
            return $items;
        }

        $orderDiscount = min($orderDiscount, $subtotal);
        $allocated = 0.0;
        $last = count($items) - 1;

        foreach ($items as $idx => &$item) {
            $lineValue = (float) $item['qty'] * (float) $item['unit_price'];
            $share = $idx === $last
                ? round($orderDiscount - $allocated, 2)
                : round($orderDiscount * $lineValue / $subtotal, 2);
            $allocated += $idx === $last ? 0 : $share;
            $item['discount'] = (float) ($item['discount'] ?? 0) + $share;
        }

        return $items;
    }
}
