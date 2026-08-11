<?php

namespace App\Modules\Pos\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Pos\Models\PosReturnLine;
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

    /** اسم الصنف مع خيار المتغيّر (لون/مقاس) للعرض في التقارير. */
    private function variantLabel(?ProductVariant $variant): string
    {
        $name = $variant?->product?->name ?? $variant?->sku ?? '—';
        if (! empty($variant?->name)) {
            $name .= ' — '.$variant->name;
        }

        return $name;
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
                $qty = (float) $it->qty; // إجمالي المباع
                if ($qty <= 0) {
                    continue;
                }
                $unitCost = $this->unitCost($it);
                $lineRev = $qty * (float) $it->unit_price - (float) $it->discount;
                $lineCost = $qty * $unitCost;

                $key = $it->variant_id;
                if (! isset($items[$key])) {
                    $items[$key] = ['name' => $this->variantLabel($it->variant), 'sku' => $it->variant?->sku ?? '—',
                        'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'returned_qty' => 0.0, 'returns' => 0.0];
                }
                $items[$key]['qty'] += $qty;
                $items[$key]['revenue'] += $lineRev;
                $items[$key]['cost'] += $lineCost;

                // إرجاع بفاتورة (returned_qty) — يُخصم من المبيعات والتكلفة والكمية.
                $retQty = (float) $it->returned_qty;
                if ($retQty > 0) {
                    $retDiscount = $qty > 0 ? (float) $it->discount * ($retQty / $qty) : 0.0;
                    $rRev = $retQty * (float) $it->unit_price - $retDiscount;
                    $items[$key]['qty'] -= $retQty;
                    $items[$key]['revenue'] -= $rRev;
                    $items[$key]['cost'] -= $retQty * $unitCost;
                    $items[$key]['returned_qty'] += $retQty;
                    $items[$key]['returns'] += $rRev;
                }
            }
        }

        // خصم المرتجعات بدون فاتورة لكل صنف — من المبيعات والتكلفة والكمية.
        foreach ($this->returnsByVariant($from, $to, $branchId) as $variantId => $ret) {
            if (! isset($items[$variantId])) {
                $items[$variantId] = ['name' => $ret['name'], 'sku' => $ret['sku'],
                    'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'returned_qty' => 0.0, 'returns' => 0.0];
            }
            $items[$variantId]['qty'] -= $ret['qty'];
            $items[$variantId]['revenue'] -= $ret['revenue'];
            $items[$variantId]['cost'] -= $ret['cost'];
            $items[$variantId]['returned_qty'] += $ret['qty'];
            $items[$variantId]['returns'] += $ret['revenue'];
        }

        $items = array_map(function ($r) {
            $r['qty'] = round($r['qty'], 2);
            $r['revenue'] = round($r['revenue'], 2);
            $r['cost'] = round($r['cost'], 2);
            $r['returned_qty'] = round($r['returned_qty'], 2);
            $r['returns'] = round($r['returns'], 2);
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
                'returns' => round(array_sum(array_column($items, 'returns')), 2),
                'profit' => round(array_sum(array_column($items, 'profit')), 2),
            ],
        ];
    }

    /**
     * مرتجعات نقطة البيع مجمّعة حسب المتغيّر في مدى زمني (وفرع اختياري) — من pos_return_lines.
     *
     * @return array<int, array{name:string, sku:string, qty:float, revenue:float, cost:float}>
     */
    public function returnsByVariant(string $from, string $to, ?int $branchId = null): array
    {
        $lines = PosReturnLine::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('branch_id', $branchId)))
            ->with('variant.product')
            ->get();

        $out = [];
        foreach ($lines as $rl) {
            $key = $rl->variant_id;
            if (! isset($out[$key])) {
                $out[$key] = ['name' => $this->variantLabel($rl->variant), 'sku' => $rl->variant?->sku ?? '—',
                    'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
            }
            $out[$key]['qty'] += (float) $rl->qty;
            $out[$key]['revenue'] += (float) $rl->qty * (float) $rl->unit_price;
            $out[$key]['cost'] += (float) $rl->qty * (float) $rl->unit_cost;
        }

        return $out;
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

        $sales = [];   // صفوف المبيعات (لكل متغيّر)
        $returns = []; // صفوف المرتجعات (لكل متغيّر)
        $revenue = 0.0;
        $cost = 0.0;
        $returnsRevenue = 0.0;
        $returnsCost = 0.0;

        $addReturn = function (int $key, string $name, float $qty, float $rRev, float $rCost) use (&$returns, &$returnsRevenue, &$returnsCost) {
            if (! isset($returns[$key])) {
                $returns[$key] = ['name' => $name, 'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'is_return' => true];
            }
            $returns[$key]['qty'] += $qty;
            $returns[$key]['revenue'] += $rRev;
            $returns[$key]['cost'] += $rCost;
            $returnsRevenue += $rRev;
            $returnsCost += $rCost;
        };

        // صفوف المبيعات (بالكامل قبل المرتجعات).
        foreach ($orders as $order) {
            foreach ($order->items as $it) {
                $qty = (float) $it->qty;
                if ($qty <= 0) {
                    continue;
                }
                $unitCost = $this->unitCost($it);
                $lineRev = $qty * (float) $it->unit_price - (float) $it->discount;
                $lineCost = $qty * $unitCost;
                $revenue += $lineRev;
                $cost += $lineCost;

                $key = $it->variant_id;
                if (! isset($sales[$key])) {
                    $sales[$key] = ['name' => $this->variantLabel($it->variant), 'qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'is_return' => false];
                }
                $sales[$key]['qty'] += $qty;
                $sales[$key]['revenue'] += $lineRev;
                $sales[$key]['cost'] += $lineCost;

                // إرجاع بفاتورة (returned_qty) — صفّ إرجاع مستقل.
                $retQty = (float) $it->returned_qty;
                if ($retQty > 0) {
                    $retDiscount = $qty > 0 ? (float) $it->discount * ($retQty / $qty) : 0.0;
                    $addReturn($key, $this->variantLabel($it->variant), $retQty,
                        round($retQty * (float) $it->unit_price - $retDiscount, 2), round($retQty * $unitCost, 2));
                }
            }
        }

        // إرجاع بدون فاتورة — صفّ إرجاع مستقل باسم الصنف واللون/المقاس.
        foreach (PosReturnLine::where('pos_shift_id', $shift->id)->with('variant.product')->get() as $rl) {
            $addReturn($rl->variant_id, $this->variantLabel($rl->variant), (float) $rl->qty,
                round((float) $rl->qty * (float) $rl->unit_price, 2), round((float) $rl->qty * (float) $rl->unit_cost, 2));
        }

        // صفوف العرض: المبيعات أولًا (موجبة) ثم المرتجعات (سالبة) — كلٌّ باسم الصنف واللون/المقاس.
        $items = [];
        foreach ($sales as $r) {
            $items[] = [
                'name' => $r['name'], 'qty' => round($r['qty'], 2),
                'revenue' => round($r['revenue'], 2), 'cost' => round($r['cost'], 2),
                'profit' => round($r['revenue'] - $r['cost'], 2), 'is_return' => false,
            ];
        }
        foreach ($returns as $r) {
            $items[] = [
                'name' => $r['name'], 'qty' => round($r['qty'], 2),
                'revenue' => -round($r['revenue'], 2), 'cost' => -round($r['cost'], 2),
                'profit' => -round($r['revenue'] - $r['cost'], 2), 'is_return' => true,
            ];
        }

        $expenses = (float) $shift->movements()->where('type', PosShiftMovement::TYPE_PAY_OUT)->sum('amount');
        $returnsRevenue = round($returnsRevenue, 2);
        $returnsCost = round($returnsCost, 2);
        $netSales = round($revenue - $returnsRevenue, 2);
        $netCost = round($cost - $returnsCost, 2);

        return [
            'shift' => $shift->loadMissing('cashier', 'warehouse', 'branch'),
            'items' => $items,
            'totals' => [
                'revenue' => round($revenue, 2),          // إجمالي المبيعات (قبل المرتجعات)
                'returns' => $returnsRevenue,             // مبيعات المرتجعات
                'returns_cost' => $returnsCost,           // تكلفة المرتجعات (تُعكَس)
                'net_sales' => $netSales,                 // صافي المبيعات بعد المرتجعات
                'cost' => round($cost, 2),                // إجمالي التكلفة (قبل المرتجعات)
                'net_cost' => $netCost,                   // صافي التكلفة بعد عكس المرتجعات
                'gross_profit' => round($revenue - $cost, 2),
                'profit' => round($netSales - $netCost, 2), // ربح الوردية = صافي المبيعات − صافي التكلفة
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
