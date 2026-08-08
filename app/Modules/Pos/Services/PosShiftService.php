<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * خدمة وردية نقطة البيع. المرحلة 1 تغطّي تسجيل أثر البيع على الدرج؛ فتح/إغلاق
 * الوردية والتسوية تُضاف في المرحلة 2 على نفس الخدمة (بلا تكرار).
 */
class PosShiftService
{
    /**
     * تسجيل بيع على الوردية: حركة درج (نقدي/بطاقة) + تحديث أرصدة الوردية المجمّعة.
     */
    public function recordSale(PosShift $shift, Order $order, string $method): PosShiftMovement
    {
        return DB::transaction(function () use ($shift, $order, $method) {
            $amount = (float) $order->total;
            $type = $method === 'card' ? PosShiftMovement::TYPE_CARD_SALE : PosShiftMovement::TYPE_CASH_SALE;

            $movement = $shift->movements()->create([
                'type' => $type,
                'amount' => $amount,
                'order_id' => $order->id,
                'reference' => $order->number,
                'created_by' => auth()->id(),
            ]);

            if ($method === 'card') {
                $shift->card_sales = (float) $shift->card_sales + $amount;
            } else {
                $shift->cash_sales = (float) $shift->cash_sales + $amount;
            }
            $shift->total_sales = (float) $shift->total_sales + $amount;
            $shift->orders_count = (int) $shift->orders_count + 1;
            $shift->expected_cash = $this->computeExpectedCash($shift);
            $shift->save();

            return $movement;
        });
    }

    /**
     * النقد المتوقّع في الدرج = الرصيد الافتتاحي + المبيعات النقدية − المرتجعات النقدية.
     * (الإيداع/السحب اليدويان يُضافان في المرحلة 2.)
     */
    public function computeExpectedCash(PosShift $shift): float
    {
        return round(
            (float) $shift->opening_float
            + (float) $shift->cash_sales
            - (float) $shift->cash_refunds,
            2
        );
    }
}
