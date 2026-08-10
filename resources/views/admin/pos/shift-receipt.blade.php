@php
    use App\Modules\Foundation\Services\Settings;
    $cur = Settings::get('store.currency_symbol', '₪');
    $storeName = Settings::get('store.name', config('app.name'));
    $address = Settings::get('store.address', '');
    $phone = Settings::get('store.phone', '');
    $logo = Settings::get('store.logo', '');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
    $dt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d H:i') : '—';
    $credit = (float) $shift->credit_sales;
    $variance = (float) $shift->variance;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('تقرير وردية') }} {{ $shift->number }}</title>
    <style>
        :root{ --ink:#111; --muted:#555; --line:#bbb; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:#e9edec; }
        body{ font-family:'Tajawal','Segoe UI',Tahoma,'Noto Sans Arabic',sans-serif; color:var(--ink); }
        .sheet{ width:80mm; min-height:60mm; margin:16px auto; background:#fff; padding:10px 12px;
            box-shadow:0 4px 18px rgba(0,0,0,.15); font-size:12px; line-height:1.6; }
        .center{ text-align:center; }
        .muted{ color:var(--muted); }
        .logo{ max-width:120px; max-height:60px; margin:0 auto 6px; display:block; }
        .store{ font-size:16px; font-weight:800; }
        .title{ font-size:13px; font-weight:800; margin-top:2px; }
        .divider{ border-top:1px dashed var(--line); margin:8px 0; }
        .row{ display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
        .tnum{ font-variant-numeric:tabular-nums; white-space:nowrap; }
        .neg{ color:#b91c1c; }
        .grand{ font-size:15px; font-weight:800; border-top:1px dashed var(--line); padding-top:6px; margin-top:4px; }
        .sub{ font-weight:700; }
        .foot{ margin-top:10px; font-size:11px; }
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
            <div class="title">{{ __('تقرير إغلاق وردية') }}</div>
        </div>

        <div class="divider"></div>

        <div class="row"><span class="muted">{{ __('الوردية') }}</span><span class="tnum">{{ $shift->number }}</span></div>
        <div class="row"><span class="muted">{{ __('الكاشير') }}</span><span>{{ $cashierName }}</span></div>
        <div class="row"><span class="muted">{{ __('التاريخ') }}</span><span class="tnum">{{ $dt($shift->closed_at ?? now()) }}</span></div>
        <div class="row"><span class="muted">{{ __('الفتح') }}</span><span class="tnum">{{ $dt($shift->opened_at) }}</span></div>

        <div class="divider"></div>

        {{-- ملخّص مالي --}}
        <div class="row"><span class="muted">{{ __('مبلغ الافتتاح') }}</span><span class="tnum">{{ $money($shift->opening_float) }}</span></div>
        <div class="row sub"><span>{{ __('إجمالي المبيعات') }}</span><span class="tnum">{{ $money($shift->total_sales) }}</span></div>
        <div class="row"><span class="muted">{{ __('المرتجعات') }}</span><span class="tnum neg">− {{ $money($shift->total_refunds) }}</span></div>
        <div class="row"><span class="muted">{{ __('المصاريف') }}</span><span class="tnum neg">− {{ $money($expenses) }}</span></div>
        @if ($credit > 0)
            <div class="row"><span class="muted">{{ __('الذمم (آجل)') }}</span><span class="tnum">{{ $money($credit) }}</span></div>
        @endif

        <div class="divider"></div>

        {{-- تفصيل طرق الدفع --}}
        <div class="row"><span class="muted">{{ __('مبيعات نقدية') }}</span><span class="tnum">{{ $money($shift->cash_sales) }}</span></div>
        <div class="row"><span class="muted">{{ __('مبيعات بطاقة') }}</span><span class="tnum">{{ $money($shift->card_sales) }}</span></div>
        @if ($credit > 0)
            <div class="row"><span class="muted">{{ __('مبيعات آجل') }}</span><span class="tnum">{{ $money($credit) }}</span></div>
        @endif

        <div class="divider"></div>

        {{-- تسوية الدرج --}}
        <div class="row grand"><span>{{ __('الرصيد المتوقّع') }}</span><span class="tnum">{{ $money($shift->expected_cash) }}</span></div>
        <div class="row sub"><span>{{ __('النقد المعدود فعليًا') }}</span><span class="tnum">{{ $money($shift->counted_cash) }}</span></div>
        <div class="row"><span class="muted">{{ __('الفرق (عجز/فائض)') }}</span>
            <span class="tnum {{ $variance < 0 ? 'neg' : '' }}">{{ ($variance > 0 ? '+ ' : ($variance < 0 ? '− ' : '')).$money(abs($variance)) }}</span></div>

        @if ($shift->notes)
            <div class="divider"></div>
            <div class="muted">{{ __('ملاحظات') }}:</div>
            <div>{{ $shift->notes }}</div>
        @endif

        <div class="divider"></div>
        <div class="center foot muted">{{ __('طُبع في') }} {{ now()->format('Y-m-d H:i') }}</div>
    </div>

    <div class="actions">
        <button class="p" onclick="window.print()">🖨 {{ __('طباعة') }}</button>
        <a href="{{ route('admin.pos.shift.open_form') }}" style="text-decoration:none;color:inherit;border:1px solid var(--line);background:#fff;border-radius:8px;padding:8px 18px;margin:0 4px;font-weight:700;font-size:13px;display:inline-block;">{{ __('بدء وردية جديدة') }}</a>
    </div>

    @if ($autoPrint ?? false)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
