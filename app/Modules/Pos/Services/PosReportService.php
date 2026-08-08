<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Sales\Models\Order;
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
     * قائمة الورديات في مدى تاريخي (حسب وقت الفتح) مع إجمالي مصروفات كل وردية.
     */
    public function shifts(string $from, string $to, ?int $branchId = null): Collection
    {
        return PosShift::query()
            ->whereBetween('opened_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('cashier:id,name')
            ->withSum(['movements as expenses' => fn ($q) => $q->where('type', PosShiftMovement::TYPE_PAY_OUT)], 'amount')
            ->latest('opened_at')
            ->get();
    }

    /**
     * تفاصيل وردية كاملة: الأصناف المباعة (صافي بعد المرتجعات) مع الربح (تكلفة/بيع/ربح)،
     * الإجماليات (الربح، المصروفات، صافي النقد)، وكل حركات الدرج.
     *
     * @return array<string, mixed>
     */
    public function shiftDetail(PosShift $shift): array
    {
        $orders = Order::where('pos_shift_id', $shift->id)->with('items.variant.product')->get();

        $items = [];
        $revenue = 0.0;
        $cost = 0.0;

        foreach ($orders as $order) {
            foreach ($order->items as $it) {
                $netQty = (float) $it->qty - (float) $it->returned_qty;
                if ($netQty <= 0) {
                    continue;
                }
                $lineRev = $netQty * (float) $it->unit_price;
                $lineCost = $netQty * (float) ($it->wholesale_cost_snapshot ?? 0);
                $revenue += $lineRev;
                $cost += $lineCost;

                $key = $it->variant_id;
                if (! isset($items[$key])) {
                    $name = $it->variant?->product?->name ?? $it->variant?->sku ?? '—';
                    if (! empty($it->variant?->name)) {
                        $name .= ' — '.$it->variant->name;
                    }
                    $items[$key] = ['name' => $name, 'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
                }
                $items[$key]['qty'] += $netQty;
                $items[$key]['revenue'] += $lineRev;
                $items[$key]['cost'] += $lineCost;
            }
        }

        $items = array_map(function ($r) {
            $r['qty'] = round($r['qty'], 2);
            $r['revenue'] = round($r['revenue'], 2);
            $r['cost'] = round($r['cost'], 2);
            $r['profit'] = round($r['revenue'] - $r['cost'], 2);

            return $r;
        }, array_values($items));
        usort($items, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $expenses = (float) $shift->movements()->where('type', PosShiftMovement::TYPE_PAY_OUT)->sum('amount');

        return [
            'shift' => $shift->loadMissing('cashier', 'warehouse', 'branch'),
            'items' => $items,
            'totals' => [
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'profit' => round($revenue - $cost, 2),
                'expenses' => round($expenses, 2),
                'net_cash' => round((float) $shift->expected_cash - (float) $shift->opening_float, 2),
            ],
            'movements' => $shift->movements()->latest('id')->get(),
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
