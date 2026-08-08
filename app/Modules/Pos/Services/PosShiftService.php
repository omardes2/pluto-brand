<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Sales\Models\Order;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خدمة وردية نقطة البيع: فتح/إغلاق الوردية، حركات الدرج اليدوية (إيداع/سحب)،
 * تسجيل أثر البيع، وحساب النقد المتوقّع. النقد المتوقّع يُشتقّ من حركات الدرج
 * (مصدر واحد للحقيقة) لا من عدّادات متفرّقة.
 */
class PosShiftService
{
    /** أنواع الحركات المؤثّرة على النقد داخل/خارج الدرج. */
    private const CASH_IN = [PosShiftMovement::TYPE_CASH_SALE, PosShiftMovement::TYPE_PAY_IN];

    private const CASH_OUT = [PosShiftMovement::TYPE_REFUND, PosShiftMovement::TYPE_PAY_OUT];

    /**
     * فتح وردية للكاشير. يمنع فتح وردية ثانية لنفس الكاشير قبل إغلاق الأولى.
     *
     * @param  array{warehouse_id:int, branch_id?:int, treasury_id?:int, opening_float?:float, notes?:string}  $data
     */
    public function open(User $cashier, array $data): PosShift
    {
        if (PosShift::where('user_id', $cashier->id)->where('status', PosShift::STATUS_OPEN)->exists()) {
            throw ValidationException::withMessages(['shift' => __('لديك وردية مفتوحة بالفعل — أغلقها قبل فتح وردية جديدة.')]);
        }

        $warehouseId = $data['warehouse_id'] ?? null;
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => __('حدّد المستودع لفتح الوردية.')]);
        }

        $treasury = isset($data['treasury_id'])
            ? Treasury::where('id', $data['treasury_id'])->where('is_active', true)->first()
            : Treasury::where('type', 'cash')->where('is_active', true)->orderByDesc('is_default')->first();
        if (! $treasury) {
            throw ValidationException::withMessages(['treasury_id' => __('لا يوجد صندوق نقدي صالح لفتح الوردية.')]);
        }

        $branchId = $data['branch_id'] ?? $cashier->branch_id;
        $openingFloat = round((float) ($data['opening_float'] ?? 0), 2);
        $actorId = auth()->id() ?? $cashier->id;

        return DB::transaction(function () use ($cashier, $branchId, $warehouseId, $treasury, $openingFloat, $data, $actorId) {
            $shift = PosShift::create([
                'number' => NumberGenerator::next('pos_shifts', 'number', 'SHIFT', (int) now()->year),
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'treasury_id' => $treasury->id,
                'user_id' => $cashier->id,
                'status' => PosShift::STATUS_OPEN,
                'opening_float' => $openingFloat,
                'expected_cash' => $openingFloat,
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            if ($openingFloat > 0) {
                $shift->movements()->create([
                    'type' => PosShiftMovement::TYPE_OPENING,
                    'amount' => $openingFloat,
                    'note' => __('رصيد افتتاحي'),
                    'created_by' => $actorId,
                ]);
            }

            return $shift;
        });
    }

    /**
     * إيداع/سحب نقدي يدوي من الدرج (pay_in / pay_out). لا يُسحب أكثر من المتوفّر.
     */
    public function addMovement(PosShift $shift, string $type, float $amount, ?string $note = null): PosShiftMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('الوردية مغلقة.')]);
        }
        if (! in_array($type, [PosShiftMovement::TYPE_PAY_IN, PosShiftMovement::TYPE_PAY_OUT], true)) {
            throw ValidationException::withMessages(['type' => __('نوع حركة غير مسموح — الإيداع أو السحب فقط.')]);
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون أكبر من صفر.')]);
        }
        if ($type === PosShiftMovement::TYPE_PAY_OUT && $amount > $this->computeExpectedCash($shift) + 0.001) {
            throw ValidationException::withMessages(['amount' => __('لا يمكن سحب أكثر من النقد المتوفّر في الدرج.')]);
        }

        return DB::transaction(function () use ($shift, $type, $amount, $note) {
            $movement = $shift->movements()->create([
                'type' => $type,
                'amount' => $amount,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            $shift->expected_cash = $this->computeExpectedCash($shift);
            $shift->save();

            return $movement;
        });
    }

    /**
     * تسجيل مصروف يومي (غداء/كهرباء/…) — سحب نقدي من الدرج بنوع وملاحظة.
     * يُسجَّل كحركة pay_out بتصنيف، ويُخفّض النقد المتوقّع في الدرج.
     */
    public function addExpense(PosShift $shift, string $category, float $amount, ?string $note = null): PosShiftMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('الوردية مغلقة.')]);
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('مبلغ المصروف يجب أن يكون أكبر من صفر.')]);
        }
        $category = trim($category) !== '' ? trim($category) : __('أخرى');

        return DB::transaction(function () use ($shift, $category, $amount, $note) {
            $movement = $shift->movements()->create([
                'type' => PosShiftMovement::TYPE_PAY_OUT,
                'category' => $category,
                'amount' => $amount,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            $shift->expected_cash = $this->computeExpectedCash($shift);
            $shift->save();

            return $movement;
        });
    }

    /**
     * تسجيل بيع على الوردية: حركة درج (نقدي/بطاقة) + تحديث عدّادات الوردية والنقد المتوقّع.
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
     * إغلاق الوردية وتسويتها: يحسب النقد المتوقّع، ويقارنه بالمعدود فعليًا (الفرق = معدود − متوقّع).
     */
    public function close(PosShift $shift, float $countedCash, ?string $note = null): PosShift
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('الوردية مغلقة بالفعل.')]);
        }

        $countedCash = round($countedCash, 2);
        $expected = $this->computeExpectedCash($shift);

        $shift->update([
            'status' => PosShift::STATUS_CLOSED,
            'expected_cash' => $expected,
            'counted_cash' => $countedCash,
            'variance' => round($countedCash - $expected, 2),
            'closed_at' => now(),
            'notes' => $note ?? $shift->notes,
        ]);

        return $shift->fresh();
    }

    /**
     * النقد المتوقّع في الدرج = الرصيد الافتتاحي + (مبيعات نقدية + إيداعات) − (مرتجعات نقدية + سحوبات).
     * حركة «opening» تُستثنى من الجمع (الرصيد الافتتاحي محسوب مرّة عبر opening_float).
     */
    public function computeExpectedCash(PosShift $shift): float
    {
        $cashIn = (float) $shift->movements()->whereIn('type', self::CASH_IN)->sum('amount');
        $cashOut = (float) $shift->movements()->whereIn('type', self::CASH_OUT)->sum('amount');

        return round((float) $shift->opening_float + $cashIn - $cashOut, 2);
    }
}
