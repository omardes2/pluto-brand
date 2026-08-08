<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Requests\Pos\SellRequest;
use App\Http\Requests\Pos\ShiftMovementRequest;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosCatalogService;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosController extends Controller
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosShiftService $shifts,
        private readonly PosCatalogService $catalog,
    ) {}

    /** الوردية المفتوحة للكاشير الحالي (إن وُجدت). */
    private function currentShift(): ?PosShift
    {
        return PosShift::where('user_id', Auth::id())
            ->where('status', PosShift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    /** شاشة الكاشير — تتطلّب وردية مفتوحة، وإلا تُحوّل لفتح وردية. */
    public function screen()
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return redirect()->route('admin.pos.shift.open_form');
        }

        return view('admin.pos.screen', [
            'shift' => $shift->load('warehouse', 'branch'),
            'categories' => $this->catalog->categories(),
            'products' => $this->catalog->search($shift->warehouse_id),
            'defaultCustomer' => (string) Settings::get('pos.default_customer_name', 'عميل نقدي'),
        ]);
    }

    public function openForm()
    {
        if ($this->currentShift()) {
            return redirect()->route('admin.pos.screen');
        }

        return view('admin.pos.open-shift', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function open(OpenShiftRequest $request): RedirectResponse
    {
        $this->shifts->open($request->user(), $request->validated());

        return redirect()->route('admin.pos.screen')->with('success', __('تم فتح الوردية.'));
    }

    public function closeForm()
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return redirect()->route('admin.pos.shift.open_form');
        }

        return view('admin.pos.close-shift', [
            'shift' => $shift,
            'expected' => $this->shifts->computeExpectedCash($shift),
        ]);
    }

    public function close(CloseShiftRequest $request): RedirectResponse
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return redirect()->route('admin.pos.shift.open_form');
        }

        $closed = $this->shifts->close($shift, (float) $request->validated()['counted_cash'], $request->validated()['notes'] ?? null);

        return redirect()->route('admin.pos.shift.open_form')->with('success', __('أُغلقت الوردية :n — الفرق :v.', [
            'n' => $closed->number,
            'v' => number_format((float) $closed->variance, 2),
        ]));
    }

    public function movement(ShiftMovementRequest $request): JsonResponse
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return response()->json(['message' => __('لا توجد وردية مفتوحة.')], 422);
        }

        $data = $request->validated();
        $this->shifts->addMovement($shift, $data['type'], (float) $data['amount'], $data['note'] ?? null);

        return response()->json(['expected_cash' => $this->shifts->computeExpectedCash($shift->fresh())]);
    }

    public function products(Request $request): JsonResponse
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return response()->json(['message' => __('لا توجد وردية مفتوحة.')], 422);
        }

        return response()->json([
            'products' => $this->catalog->search(
                $shift->warehouse_id,
                $request->query('q'),
                $request->filled('category') ? (int) $request->query('category') : null,
            ),
        ]);
    }

    public function barcode(Request $request): JsonResponse
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return response()->json(['message' => __('لا توجد وردية مفتوحة.')], 422);
        }

        $code = trim((string) $request->query('code'));
        $item = $code !== '' ? $this->catalog->findByBarcode($shift->warehouse_id, $code) : null;

        if (! $item) {
            return response()->json(['message' => __('لا يوجد منتج بهذا الباركود.')], 404);
        }

        return response()->json(['product' => $item]);
    }

    public function sell(SellRequest $request): JsonResponse
    {
        $shift = $this->currentShift();
        if (! $shift) {
            return response()->json(['message' => __('لا توجد وردية مفتوحة.')], 422);
        }

        $data = $request->validated();

        // تطبيق خصم على الفاتورة يتطلّب صلاحية pos.discount.
        $orderDiscount = (float) ($data['discount'] ?? 0);
        $lineDiscount = collect($data['items'])->sum(fn ($i) => (float) ($i['discount'] ?? 0));
        if (($orderDiscount > 0 || $lineDiscount > 0) && ! $request->user()->can('pos.discount')) {
            abort(403, __('لا تملك صلاحية تطبيق خصم.'));
        }

        $order = $this->sales->sell($shift, $data);

        $paid = isset($data['paid']) ? (float) $data['paid'] : (float) $order->total;
        $change = $data['payment_method'] === 'cash' ? max(0, round($paid - (float) $order->total, 2)) : 0.0;

        return response()->json([
            'ok' => true,
            'number' => $order->number,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'total' => (float) $order->total,
            'paid' => round($paid, 2),
            'change' => $change,
            'method' => $data['payment_method'],
        ]);
    }
}
