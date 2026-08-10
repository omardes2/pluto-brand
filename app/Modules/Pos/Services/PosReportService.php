<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
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
     * كشف المصروفات (حركات pay_out) في مدى تاريخي مع الإجمالي والتفصيل حسب النوع.
     *
     * @return array{rows: Collection, total: float, byType: Collection}
     */
    public function expenses(string $from, string $to, ?int $branchId = null): array
    {
        $movements = PosShiftMovement::query()
            ->where('type', PosShiftMovement::TYPE_PAY_OUT)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('branch_id', $branchId)))
            ->with(['shift:id,number', 'createdBy:id,name'])
            ->latest('id')
            ->get();

        return [
            'rows' => $movements,
            'total' => round((float) $movements->sum('amount'), 2),
            'byType' => $movements->groupBy(fn ($m) => $m->category ?: __('غير مصنّف'))
                ->map(fn ($g) => round((float) $g->sum('amount'), 2))
                ->sortDesc(),
        ];
    }

    /**
     * كشف مبيعات الأصناف في مدى تاريخي: كل صنف انباع مع الكمية (صافي المرتجعات)
     * والإيراد والتكلفة والربح — مرتّبًا تنازليًا بالإيراد.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    /**
     * تكلفة الوحدة لبند بيع: لقطة التكلفة وقت البيع، وإلا متوسط التكلفة (WAC) الحالي، وإلا
     * سعر شراء الصنف/المنتج. يضمن ظهور التكلفة والأرباح حتى لو لم تُسجَّل لقطة (أو كانت صفرًا
     * لأن المخزون أُدخل بلا تكلفة). يتطلّب تحميل variant.product مسبقًا.
     */
    private function unitCost(OrderItem $item): float
    {
        $snapshot = (float) ($item->wholesale_cost_snapshot ?? 0);
        if ($snapshot > 0) {
            return $snapshot;
        }
        $wac = (float) ($item->variant?->average_cost ?? 0);
        if ($wac > 0) {
            return $wac;
        }
        // ملاحظة: cost_price عمود غير قابل للـnull (افتراضي 0)، لذا نتخطّى الصفر لا الـnull فقط.
        $variantCost = (float) ($item->variant?->cost_price ?? 0);
        if ($variantCost > 0) {
            return $variantCost;
        }

        return (float) ($item->variant?->product?->cost_price ?? 0);
    }

    public function itemsSold(string $from, string $to, ?int $branchId = null): array
    {
        $orders = Order::query()
            ->where('channel', 'pos')
            ->whereNotNull('pos_shift_id')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('items.variant.product')
            ->get();

        $items = [];
        foreach ($orders as $order) {
            foreach ($order->items as $it) {
                $grossQty = (float) $it->qty;
                $netQty = $grossQty - (float) $it->returned_qty;
                if ($netQty <= 0) {
                    continue;
                }
                // المبيعات = صافي بعد خصم البند (موزّع تناسبيًا عند إرجاع جزئي).
                $lineDiscount = $grossQty > 0 ? (float) $it->discount * ($netQty / $grossQty) : 0.0;
                $lineRev = $netQty * (float) $it->unit_price - $lineDiscount;
                $lineCost = $netQty * $this->unitCost($it);

                $key = $it->variant_id;
                if (! isset($items[$key])) {
                    $name = $it->variant?->product?->name ?? $it->variant?->sku ?? '—';
                    if (! empty($it->variant?->name)) {
                        $name .= ' — '.$it->variant->name;
                    }
                    $items[$key] = ['name' => $name, 'sku' => $it->variant?->sku ?? '—', 'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
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

        return [
            'rows' => $items,
            'totals' => [
                'qty' => round(array_sum(array_column($items, 'qty')), 2),
                'revenue' => round(array_sum(array_column($items, 'revenue')), 2),
                'cost' => round(array_sum(array_column($items, 'cost')), 2),
                'profit' => round(array_sum(array_column($items, 'profit')), 2),
            ],
        ];
    }

    /**
     * كشف مبيعات الكاشيرين في مدى تاريخي: لكل كاشير عدد الفواتير والنقدي/البطاقة/الآجل
     * وإجمالي المبيعات — من حركات درج البيع مجمّعة حسب كاشير الوردية.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function cashierSales(string $from, string $to, ?int $branchId = null): array
    {
        $movements = PosShiftMovement::query()
            ->whereIn('type', [
                PosShiftMovement::TYPE_CASH_SALE,
                PosShiftMovement::TYPE_CARD_SALE,
                PosShiftMovement::TYPE_CREDIT_SALE,
            ])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('branch_id', $branchId)))
            ->with('shift.cashier:id,name')
            ->get(['id', 'pos_shift_id', 'type', 'amount', 'order_id']);

        $rows = $movements
            ->groupBy(fn ($m) => $m->shift?->user_id ?? 0)
            ->map(function ($group) {
                $sumOf = fn (string $type) => (float) $group->where('type', $type)->sum('amount');
                $cash = $sumOf(PosShiftMovement::TYPE_CASH_SALE);
                $card = $sumOf(PosShiftMovement::TYPE_CARD_SALE);
                $credit = $sumOf(PosShiftMovement::TYPE_CREDIT_SALE);

                return [
                    'cashier' => $group->first()->shift?->cashier?->name ?? __('غير معروف'),
                    'orders' => $group->pluck('order_id')->filter()->unique()->count(),
                    'cash' => round($cash, 2),
                    'card' => round($card, 2),
                    'credit' => round($credit, 2),
                    'total' => round($cash + $card + $credit, 2),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'orders' => array_sum(array_column($rows, 'orders')),
                'cash' => round(array_sum(array_column($rows, 'cash')), 2),
                'card' => round(array_sum(array_column($rows, 'card')), 2),
                'credit' => round(array_sum(array_column($rows, 'credit')), 2),
                'total' => round(array_sum(array_column($rows, 'total')), 2),
            ],
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
                $grossQty = (float) $it->qty;
                $netQty = $grossQty - (float) $it->returned_qty;
                if ($netQty <= 0) {
                    continue;
                }
                // المبيعات = صافي بعد خصم البند (الخصم موزّع تناسبيًا عند إرجاع جزئي).
                $lineDiscount = $grossQty > 0 ? (float) $it->discount * ($netQty / $grossQty) : 0.0;
                $lineRev = $netQty * (float) $it->unit_price - $lineDiscount;
                $lineCost = $netQty * $this->unitCost($it);
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
        // المرتجعات (استرداد نقدي من الدرج) — تُخصم من صافي مبيعات الوردية وربحها.
        $returns = (float) $shift->movements()->where('type', PosShiftMovement::TYPE_REFUND)->sum('amount');
        $netSales = round($revenue - $returns, 2);

        return [
            'shift' => $shift->loadMissing('cashier', 'warehouse', 'branch'),
            'items' => $items,
            'totals' => [
                'revenue' => round($revenue, 2),        // إجمالي المبيعات (صافي الخصومات، قبل المرتجعات)
                'returns' => round($returns, 2),
                'net_sales' => $netSales,               // صافي بعد المرتجعات
                'cost' => round($cost, 2),
                'gross_profit' => round($revenue - $cost, 2),
                'profit' => round($netSales - $cost, 2), // ربح الوردية بعد المرتجعات
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
        $credit = $sumOf(PosShiftMovement::TYPE_CREDIT_SALE);
        $expenses = $sumOf(PosShiftMovement::TYPE_PAY_OUT);
        $refunds = $sumOf(PosShiftMovement::TYPE_REFUND);
        $payIn = $sumOf(PosShiftMovement::TYPE_PAY_IN);

        $orders = $group
            ->whereIn('type', [PosShiftMovement::TYPE_CASH_SALE, PosShiftMovement::TYPE_CARD_SALE, PosShiftMovement::TYPE_CREDIT_SALE])
            ->pluck('order_id')->filter()->unique()->count();

        $row = [
            'orders' => $orders,
            'total_sales' => round($cash + $card + $credit, 2),
            'cash' => round($cash, 2),
            'card' => round($card, 2),
            'credit' => round($credit, 2),
            'expenses' => round($expenses, 2),
            'refunds' => round($refunds, 2),
            // الرصيد النهائي = صافي النقد الناتج = نقدي + إيداعات − مصروفات − مرتجعات نقدية (الذمم لا تدخل النقد).
            'net' => round($cash + $payIn - $expenses - $refunds, 2),
        ];

        return $isTotals ? $row : ['date' => $date] + $row;
    }
}
