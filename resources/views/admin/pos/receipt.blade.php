@php
    use App\Modules\Foundation\Services\Settings;
    $cur = Settings::get('store.currency_symbol', '₪');
    $storeName = Settings::get('store.name', config('app.name'));
    $address = Settings::get('store.address', '');
    $phone = Settings::get('store.phone', '');
    $taxNo = Settings::get('store.tax_number', '');
    $logo = Settings::get('store.logo', '');
    $footer = Settings::get('pos.receipt_footer', '');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('إيصال') }} {{ $order->number }}</title>
    <style>
        :root{ --ink:#111; --muted:#555; --line:#bbb; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:#e9edec; }
        body{ font-family:'Tajawal','Segoe UI',Tahoma,'Noto Sans Arabic',sans-serif; color:var(--ink); }
        .sheet{ width:80mm; min-height:60mm; margin:16px auto; background:#fff; padding:10px 12px;
            box-shadow:0 4px 18px rgba(0,0,0,.15); font-size:12px; line-height:1.5; }
        .center{ text-align:center; }
        .muted{ color:var(--muted); }
        .logo{ max-width:120px; max-height:60px; margin:0 auto 6px; display:block; }
        .store{ font-size:16px; font-weight:800; }
        .divider{ border-top:1px dashed var(--line); margin:8px 0; }
        .row{ display:flex; justify-content:space-between; gap:8px; }
        .row .n{ flex:1; }
        .tnum{ font-variant-numeric:tabular-nums; }
        table{ width:100%; border-collapse:collapse; }
        th{ text-align:start; font-size:11px; color:var(--muted); border-bottom:1px solid var(--line); padding:3px 0; }
        td{ padding:3px 0; vertical-align:top; }
        td.q,td.t{ text-align:end; white-space:nowrap; }
        .totals .row{ padding:2px 0; }
        .grand{ font-size:15px; font-weight:800; border-top:1px dashed var(--line); padding-top:6px; margin-top:4px; }
        .pay{ margin-top:6px; }
        .foot{ margin-top:10px; font-size:11px; }
        .barcode{ letter-spacing:2px; font-family:monospace; font-size:13px; }
        .actions{ text-align:center; margin:14px; }
        .actions button{ font-family:inherit; font-weight:700; border:1px solid var(--line); background:#fff; border-radius:8px;
            padding:8px 18px; margin:0 4px; cursor:pointer; font-size:13px; }
        .actions .p{ background:#059669; color:#fff; border-color:#059669; }
        @media print{
            html,body{ background:#fff; }
            .sheet{ width:auto; margin:0; box-shadow:none; padding:0 2mm; }
            .actions{ display:none; }
            @page{ margin:4mm; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="center">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ $storeName }}">
            @endif
            <div class="store">{{ $storeName }}</div>
            @if ($address)<div class="muted">{{ $address }}</div>@endif
            @if ($phone)<div class="muted">{{ __('هاتف') }}: {{ $phone }}</div>@endif
            @if ($taxNo)<div class="muted">{{ __('الرقم الضريبي') }}: {{ $taxNo }}</div>@endif
        </div>

        <div class="divider"></div>

        <div class="row"><span class="muted">{{ __('فاتورة') }}</span><span class="tnum">{{ $order->number }}</span></div>
        <div class="row"><span class="muted">{{ __('التاريخ') }}</span><span class="tnum">{{ $order->created_at?->format('Y-m-d H:i') }}</span></div>
        @if ($cashierName)<div class="row"><span class="muted">{{ __('الكاشير') }}</span><span>{{ $cashierName }}</span></div>@endif
        <div class="row"><span class="muted">{{ __('العميل') }}</span><span>{{ $order->customer_name }}</span></div>

        <div class="divider"></div>

        <table>
            <thead>
                <tr><th>{{ __('الصنف') }}</th><th class="q">{{ __('كمية') }}</th><th class="t">{{ __('الإجمالي') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    @php
                        $name = $item->variant?->product?->name ?? $item->variant?->sku ?? '—';
                        if (! empty($item->variant?->name)) { $name .= ' — '.$item->variant->name; }
                    @endphp
                    <tr>
                        <td>{{ $name }}<div class="muted tnum">{{ number_format((float) $item->qty, 0) }} × {{ number_format((float) $item->unit_price, 2) }}</div></td>
                        <td class="q tnum">{{ number_format((float) $item->qty, 0) }}</td>
                        <td class="t tnum">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <div class="totals">
            <div class="row"><span class="muted">{{ __('الإجمالي الفرعي') }}</span><span class="tnum">{{ $money($order->subtotal) }}</span></div>
            @if ((float) $order->discount_total > 0)
                <div class="row"><span class="muted">{{ __('الخصم') }}</span><span class="tnum">− {{ $money($order->discount_total) }}</span></div>
            @endif
            <div class="row grand"><span>{{ __('الإجمالي') }}</span><span class="tnum">{{ $money($order->total) }}</span></div>
        </div>

        <div class="pay">
            <div class="row"><span class="muted">{{ __('طريقة الدفع') }}</span><span>{{ $method === 'card' ? __('بطاقة') : __('نقدي') }}</span></div>
            <div class="row"><span class="muted">{{ __('المدفوع') }}</span><span class="tnum">{{ $money($order->amount_paid) }}</span></div>
        </div>

        @if ($footer)
            <div class="divider"></div>
            <div class="center foot muted">{{ $footer }}</div>
        @endif
    </div>

    <div class="actions">
        <button class="p" onclick="window.print()">🖨 {{ __('طباعة') }}</button>
        <button onclick="window.close()">{{ __('إغلاق') }}</button>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
