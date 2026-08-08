<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
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

    /** @return array{0:string,1:string} */
    private function range(Request $request): array
    {
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $from = $request->date('from')?->toDateString() ?? now()->subDays(29)->toDateString();

        return [$from, $to];
    }
}
