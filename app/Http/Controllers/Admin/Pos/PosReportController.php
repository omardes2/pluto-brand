<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Services\PosReportService;
use Illuminate\Http\Request;

class PosReportController extends Controller
{
    public function __construct(private readonly PosReportService $reports) {}

    /** تقارير نقطة البيع — أرشفة يومية (مبيعات نقدية/بطاقة، مصروفات، رصيد نهائي). */
    public function index(Request $request)
    {
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $from = $request->date('from')?->toDateString() ?? now()->subDays(29)->toDateString();

        return view('admin.pos.reports', [
            'from' => $from,
            'to' => $to,
            'summary' => $this->reports->dailySummary($from, $to),
        ]);
    }
}
