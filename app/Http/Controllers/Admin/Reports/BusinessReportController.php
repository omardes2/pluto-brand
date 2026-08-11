<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * نظام التقارير (المبيعات + الذمم). قراءة فقط، مجاميع مباشرة من البيانات الحيّة، مع فلتر
 * نطاق زمني (من/إلى + اختصارات) وتصدير CSV. المبيعات تُحتسب على الطلبات المؤكّدة فأكثر.
 */
class BusinessReportController extends Controller
{
    /** حالات تُستبعد من المبيعات (غير مؤكّدة أو ملغاة). */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled'];

    /** المبيعات حسب الزبون: عدد الطلبات ومجموع المبيعات لكل زبون ضمن الفترة. */
    public function salesByCustomer(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        // مرتجعات بفاتورة لكل زبون (قيمة returned_qty، خصم موزّع تناسبيًا).
        $retExpr = 'SUM(oi.returned_qty * oi.unit_price - oi.discount * oi.returned_qty / NULLIF(oi.qty, 0))';
        $regReturns = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotNull('o.customer_id')->whereNotIn('o.status', self::EXCLUDED_STATUSES)
            ->whereBetween('o.created_at', [$range->from, $range->to])
            ->groupBy('o.customer_id')->selectRaw('o.customer_id, '.$retExpr.' as ret')
            ->pluck('ret', 'o.customer_id');
        $guestReturns = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNull('o.customer_id')->whereNotIn('o.status', self::EXCLUDED_STATUSES)
            ->whereBetween('o.created_at', [$range->from, $range->to])
            ->groupBy('o.customer_name')->selectRaw('o.customer_name, '.$retExpr.' as ret')
            ->pluck('ret', 'o.customer_name');
        // مرتجعات بدون فاتورة → تُخصم من زبون نقدي (لا ترتبط بزبون).
        $noInvoiceReturns = (float) DB::table('pos_return_lines')
            ->whereBetween('created_at', [$range->from, $range->to])->sum(DB::raw('qty * unit_price'));
        $cashName = (string) Settings::get('pos.default_customer_name', 'عميل نقدي');

        $registered = Order::query()
            ->whereNotNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_id')
            ->get();

        $names = Customer::whereIn('id', $registered->pluck('customer_id'))->pluck('name', 'id');

        $rows = $registered->map(function ($r) use ($names, $regReturns) {
            $returns = (float) ($regReturns[$r->customer_id] ?? 0);

            return [
                'name' => $names[$r->customer_id] ?? ('#'.$r->customer_id),
                'orders_count' => (int) $r->orders_count,
                'returns' => round($returns, 2),
                'sales_total' => round((float) $r->sales_total - $returns, 2),
            ];
        });

        $guests = Order::query()
            ->whereNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('customer_name, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_name')
            ->get()
            ->map(function ($r) use ($guestReturns, $noInvoiceReturns, $cashName) {
                $name = $r->customer_name ?: $cashName;
                $returns = (float) ($guestReturns[$r->customer_name] ?? 0);
                if ($name === $cashName) {
                    $returns += $noInvoiceReturns; // مرتجعات بدون فاتورة على زبون نقدي
                }

                return [
                    'name' => $name,
                    'orders_count' => (int) $r->orders_count,
                    'returns' => round($returns, 2),
                    'sales_total' => round((float) $r->sales_total - $returns, 2),
                ];
            });

        $rows = $rows->concat($guests)->sortByDesc('sales_total')->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-customer', [__('الزبون'), __('عدد الطلبات'), __('المرتجعات'), __('صافي المبيعات')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['returns'], 2, '.', ''), number_format($r['sales_total'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_customer', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalReturns' => $rows->sum('returns'),
            'totalSales' => $rows->sum('sales_total'),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب المنتج: الكمية المباعة، إجمالي البيع، متوسط سعر القطعة، الربح الإجمالي. */
    public function salesByProduct(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        // تكلفة الوحدة الفعّالة: لقطة البيع ← متوسط التكلفة ← سعر تكلفة المتغيّر ← سعر تكلفة المنتج
        // (NULLIF لتخطّي الصفر). تُطابق سلسلة التراجُع في PosReportService::unitCost.
        $effCost = 'COALESCE(NULLIF(order_items.wholesale_cost_snapshot,0), NULLIF(product_variants.average_cost,0), '
            .'NULLIF(product_variants.cost_price,0), NULLIF(products.cost_price,0), 0)';

        // مبيعات إجمالية (قبل المرتجعات) + مرتجعات بفاتورة (returned_qty) لكل منتج.
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->groupBy('products.id', 'products.name')
            ->selectRaw("products.id as product_id, products.name as product_name,
                SUM(order_items.qty) as gross_qty,
                SUM(order_items.qty * order_items.unit_price - order_items.discount) as gross_sale,
                SUM(order_items.qty * {$effCost}) as gross_cost,
                SUM(order_items.returned_qty) as inv_ret_qty,
                SUM(order_items.returned_qty * order_items.unit_price
                    - order_items.discount * order_items.returned_qty / NULLIF(order_items.qty, 0)) as inv_ret_sale,
                SUM(order_items.returned_qty * {$effCost}) as inv_ret_cost")
            ->get();

        // مرتجعات بدون فاتورة لكل منتج (pos_return_lines).
        $noInvoice = DB::table('pos_return_lines as prl')
            ->join('product_variants as pv', 'pv.id', '=', 'prl.variant_id')
            ->whereBetween('prl.created_at', [$range->from, $range->to])
            ->groupBy('pv.product_id')
            ->selectRaw('pv.product_id, SUM(prl.qty) as ret_qty, SUM(prl.qty * prl.unit_price) as ret_sale, SUM(prl.qty * prl.unit_cost) as ret_cost')
            ->get()->keyBy('product_id');

        $rows = $rows->map(function ($r) use ($noInvoice) {
            $ni = $noInvoice->get($r->product_id);
            $grossQty = (float) $r->gross_qty;
            $grossSale = (float) $r->gross_sale;
            $grossCost = (float) $r->gross_cost;
            $retQty = (float) $r->inv_ret_qty + (float) ($ni->ret_qty ?? 0);
            $retSale = (float) $r->inv_ret_sale + (float) ($ni->ret_sale ?? 0);
            $retCost = (float) $r->inv_ret_cost + (float) ($ni->ret_cost ?? 0);
            $netSale = $grossSale - $retSale;
            $netCost = $grossCost - $retCost;

            return [
                'product' => $r->product_name,
                'qty' => round($grossQty - $retQty, 2), // الكمية المباعة صافية بعد خصم المرتجعات
                'returns' => round($retSale, 2),        // المرتجعات
                'sale_total' => round($netSale, 2),     // صافي البيع بعد المرتجعات
                'avg_price' => $grossQty > 0 ? round($grossSale / $grossQty, 2) : 0.0,
                'profit' => round($netSale - $netCost, 2),
            ];
        })->sortByDesc('sale_total')->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-product',
                [__('المنتج'), __('الكمية المباعة'), __('المرتجعات'), __('إجمالي البيع'), __('متوسط سعر القطعة'), __('الربح الإجمالي')],
                $rows->map(fn ($r) => [$r['product'], number_format($r['qty'], 2, '.', ''), number_format($r['returns'], 2, '.', ''), number_format($r['sale_total'], 2, '.', ''), number_format($r['avg_price'], 2, '.', ''), number_format($r['profit'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_product', [
            'rows' => $rows,
            'totalQty' => $rows->sum('qty'),
            'totalReturns' => $rows->sum('returns'),
            'totalSales' => $rows->sum('sale_total'),
            'totalProfit' => $rows->sum('profit'),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب موظف المبيعات: عدد الطلبات وإجمالي المبيعات من غير التوصيل (subtotal). */
    public function salesByEmployee(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        $rows = Order::query()
            ->whereNotNull('assigned_to')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->join('users', 'users.id', '=', 'orders.assigned_to')
            ->groupBy('orders.assigned_to', 'users.name')
            ->selectRaw('users.name as emp_name, COUNT(*) as orders_count, SUM(orders.subtotal) as sales_ex_shipping')
            ->orderByDesc('sales_ex_shipping')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->emp_name,
                'orders_count' => (int) $r->orders_count,
                'sales' => (float) $r->sales_ex_shipping,
            ]);

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-employee', [__('الموظف'), __('عدد الطلبات'), __('إجمالي المبيعات (من غير توصيل)')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['sales'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_employee', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => $rows->sum('sales'),
        ] + $this->viewMeta($range));
    }

    /** كشف حساب العملاء: المستحق على كل عميل كما في نهاية الفترة (رصيد حسابه في دفتر الأستاذ). */
    public function receivablesCustomers(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);
        $balances = $this->postedAccountBalances($range->to->toDateString());

        $rows = Customer::query()->orderBy('name')->get(['id', 'name', 'gl_account_id'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'due' => round((float) ($balances[$c->gl_account_id] ?? 0), 2),
            ])
            ->sortByDesc('due')
            ->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('receivables-customers', [__('العميل'), __('المبلغ المستحق')],
                $rows->map(fn ($r) => [$r['name'], number_format($r['due'], 2, '.', '')]));
        }

        return view('admin.reports.business.receivables_customers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ] + $this->viewMeta($range, asOf: true));
    }

    /** كشف حساب الموردين: المستحق لكل مورد كما في نهاية الفترة. */
    public function receivablesSuppliers(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);
        $asOf = $range->to->toDateString();

        $invoiced = PurchaseInvoice::where('status', 'posted')->whereDate('invoice_date', '<=', $asOf)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as t')->pluck('t', 'supplier_id');

        $paid = FinancialVoucher::where('kind', 'payment')->where('status', 'posted')->whereDate('voucher_date', '<=', $asOf)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as a')->pluck('a', 'supplier_id');

        $rows = Supplier::query()->orderBy('name')->get(['id', 'name', 'opening_balance'])
            ->map(fn ($s) => [
                'name' => $s->name,
                'due' => round((float) $s->opening_balance + ((float) ($invoiced[$s->id] ?? 0) - (float) ($paid[$s->id] ?? 0)), 2),
            ])
            ->sortByDesc('due')
            ->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('receivables-suppliers', [__('المورد'), __('المبلغ المستحق')],
                $rows->map(fn ($r) => [$r['name'], number_format($r['due'], 2, '.', '')]));
        }

        return view('admin.reports.business.receivables_suppliers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ] + $this->viewMeta($range, asOf: true));
    }

    /** رصيد كل حساب من القيود المُرحّلة حتى تاريخ (مدين − دائن) مفهرسًا بـ account_id. */
    private function postedAccountBalances(string $asOf): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as aid, SUM(journal_lines.debit - journal_lines.credit) as bal')
            ->pluck('bal', 'aid');
    }

    /** النطاق الزمني من الطلب (preset + from/to)، افتراضيًا هذا الشهر. */
    private function range(Request $request): DateRange
    {
        return DateRange::resolve($request->query('range'), $request->query('from'), $request->query('to'));
    }

    /** بيانات مشتركة للواجهة: النطاق واسم الشركة (للترويسة). */
    private function viewMeta(DateRange $range, bool $asOf = false): array
    {
        return [
            'range' => $range,
            'asOf' => $asOf,
            'company' => (string) Settings::get('store.name', 'Pluto Brand'),
        ];
    }

    /**
     * تنزيل CSV متوافق مع Excel العربي (بادئة BOM). الصفوف مصفوفات قيم مرتّبة كالترويسة.
     *
     * @param  array<int, string>  $head
     * @param  iterable<int, array<int, string|int|float>>  $rows
     */
    private function csv(string $name, array $head, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
