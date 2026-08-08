<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosShiftMovement;
use Illuminate\Support\Collection;

/**
 * تقارير نقطة البيع — أرشفة يومية للمبيعات والمدفوعات والمصروفات من حركات الدرج.
 * التجميع في PHP (متوافق مع أي قاعدة بيانات) على مدى تاريخي محدود.
 */
class PosReportService
{
    /**
     * ملخّص يومي بين تاريخين (شامل)، مع إجماليات المدى.
     *
     * @return array{days: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function dailySummary(string $from, string $to, ?int $branchId = null): array
    {
        $movements = PosShiftMovement::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('branch_id', $branchId)))
            ->get(['type', 'amount', 'order_id', 'created_at']);

        $days = $movements
            ->groupBy(fn ($m) => $m->created_at->toDateString())
            ->map(fn ($group, $date) => $this->summarize($date, $group))
            ->sortByDesc('date')
            ->values()
            ->all();

        return [
            'days' => $days,
            'totals' => $this->summarize('', $movements, isTotals: true),
        ];
    }

    /**
     * @param  Collection<int, PosShiftMovement>  $group
     * @return array<string, mixed>
     */
    private function summarize(string $date, $group, bool $isTotals = false): array
    {
        $sumOf = fn (string $type) => (float) $group->where('type', $type)->sum('amount');

        $cash = $sumOf(PosShiftMovement::TYPE_CASH_SALE);
        $card = $sumOf(PosShiftMovement::TYPE_CARD_SALE);
        $expenses = $sumOf(PosShiftMovement::TYPE_PAY_OUT);
        $refunds = $sumOf(PosShiftMovement::TYPE_REFUND);
        $payIn = $sumOf(PosShiftMovement::TYPE_PAY_IN);

        $orders = $group
            ->whereIn('type', [PosShiftMovement::TYPE_CASH_SALE, PosShiftMovement::TYPE_CARD_SALE])
            ->pluck('order_id')->filter()->unique()->count();

        $row = [
            'orders' => $orders,
            'total_sales' => round($cash + $card, 2),
            'cash' => round($cash, 2),
            'card' => round($card, 2),
            'expenses' => round($expenses, 2),
            'refunds' => round($refunds, 2),
            // الرصيد النهائي = صافي النقد الناتج = نقدي + إيداعات − مصروفات − مرتجعات نقدية.
            'net' => round($cash + $payIn - $expenses - $refunds, 2),
        ];

        return $isTotals ? $row : ['date' => $date] + $row;
    }
}
