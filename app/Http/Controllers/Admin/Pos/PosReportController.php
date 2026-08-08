<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosReportService;
use Illuminate\Http\Request;

class PosReportController extends Controller
{
    public function __construct(private readonly PosReportService $reports) {}

    /** تقارير نقطة البيع — أرشفة يومية (مبيعات نقدية/بطاقة، مصروفات، رصيد نهائي). */
    public function index(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('admin.pos.reports', [
            'from' => $from,
            'to' => $to,
            'summary' => $this->reports->dailySummary($from, $to),
        ]);
    }

    /** قائمة الورديات — كل وردية تُفتح في صفحة تفاصيلها. */
    public function shifts(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('admin.pos.shifts', [
            'from' => $from,
            'to' => $to,
            'shifts' => $this->reports->shifts($from, $to),
        ]);
    }

    /** تفاصيل وردية كاملة: أصناف مباعة، أرباح، حركات، أرصدة. */
    public function shiftDetail(PosShift $shift)
    {
        return view('admin.pos.shift-detail', $this->reports->shiftDetail($shift));
    }

    /** كشف المصروفات في مدى تاريخي. */
    public function expenses(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('admin.pos.expenses', [
            'from' => $from,
            'to' => $to,
            'data' => $this->reports->expenses($from, $to),
        ]);
    }

    /** إدارة أنواع المصروفات (إضافة/حذف). */
    public function expenseTypes()
    {
        return view('admin.pos.expense-types', ['types' => $this->expenseTypeList()]);
    }

    public function saveExpenseTypes(Request $request)
    {
        $validated = $request->validate(['types' => ['nullable', 'string', 'max:2000']]);
        $types = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($validated['types'] ?? '')))
        )));

        Settings::set('pos.expense_categories', implode(',', $types), 'pos');

        return redirect()->route('admin.pos.expense_types')->with('success', __('تم حفظ أنواع المصروفات.'));
    }

    /** @return array<int, string> */
    private function expenseTypeList(): array
    {
        return array_values(array_filter(array_map('trim',
            explode(',', (string) Settings::get('pos.expense_categories', '')))));
    }

    /** @return array{0:string,1:string} */
    private function range(Request $request): array
    {
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $from = $request->date('from')?->toDateString() ?? now()->subDays(29)->toDateString();

        return [$from, $to];
    }
}
