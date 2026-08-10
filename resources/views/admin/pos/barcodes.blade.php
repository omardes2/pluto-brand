@php
    use App\Modules\Foundation\Services\Settings;
    use App\Modules\Pos\Support\Code128;
    $cur = Settings::get('store.currency_symbol', '₪');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('طباعة باركود') }}</title>
    <style>
        :root{ --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --brand:#059669; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:var(--bg); }
        body{ font-family:'Tajawal','Segoe UI',Tahoma,'Noto Sans Arabic',sans-serif; color:var(--ink); }
        .wrap{ max-width:1000px; margin:0 auto; padding:18px 16px 60px; }
        .head{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .head h1{ font-size:20px; margin:0; font-weight:800; }
        .head .count{ color:var(--muted); font-size:13px; }
        .spacer{ flex:1; }
        .btn{ font-family:inherit; font-weight:700; border:1px solid var(--line); background:#fff; color:var(--ink);
            border-radius:9px; padding:8px 14px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .btn:hover{ border-color:#cbd5e1; }
        .btn.brand{ background:var(--brand); color:#fff; border-color:var(--brand); }
        .btn.brand:hover{ filter:brightness(1.05); }
        .toolbar{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid var(--line);
            border-radius:12px; padding:12px 14px; margin-bottom:16px; position:sticky; top:0; z-index:5; }
        .toolbar input[type=text], .toolbar select{ font-family:inherit; font-size:13px; border:1px solid var(--line);
            border-radius:8px; padding:8px 10px; min-width:200px; }
        .toolbar .qtybox{ display:flex; align-items:center; gap:6px; font-size:13px; color:var(--muted); }
        .toolbar .qtybox input{ width:64px; font-family:inherit; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:8px 10px; text-align:center; }

        .card{ background:#fff; border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        table.tbl{ width:100%; border-collapse:collapse; font-size:13.5px; }
        table.tbl thead th{ background:#f9fafb; text-align:right; font-weight:700; color:#374151; padding:11px 12px;
            border-bottom:1px solid var(--line); position:sticky; top:64px; z-index:2; font-size:12.5px; }
        table.tbl tbody td{ padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        table.tbl tbody tr{ cursor:pointer; }
        table.tbl tbody tr:hover{ background:#f9fafb; }
        table.tbl tbody tr.sel{ background:#ecfdf5; }
        .pick{ width:18px; height:18px; accent-color:var(--brand); }
        td.nm{ font-weight:700; }
        td.num{ font-variant-numeric:tabular-nums; letter-spacing:1px; color:#374151; white-space:nowrap; }
        td.svgbox svg{ display:block; height:34px; width:auto; max-width:230px; }
        td.pr{ font-weight:800; color:var(--brand); white-space:nowrap; }
        td.stk{ text-align:center; }
        td.stk .badge{ display:inline-block; min-width:34px; font-weight:700; font-size:12.5px; font-variant-numeric:tabular-nums;
            color:#374151; background:#f3f4f6; border:1px solid var(--line); border-radius:6px; padding:2px 8px; }
        td.stk .badge.low{ color:#b45309; background:#fffbeb; border-color:#fde68a; }
        td.stk .badge.out{ color:#b91c1c; background:#fef2f2; border-color:#fecaca; }
        .qty{ width:60px; font-family:inherit; font-size:13px; border:1px solid var(--line); border-radius:7px; padding:6px; text-align:center; }
        .empty{ text-align:center; color:var(--muted); padding:60px 20px; }

        /* منطقة الطباعة — تظهر عند الطباعة فقط */
        #printArea{ display:none; }
        .label{ width:50mm; height:25mm; padding:1mm 1.5mm; display:flex; flex-direction:column; align-items:center; justify-content:flex-start;
            overflow:hidden; text-align:center; }
        .label .l-nm{ font-size:2.3mm; font-weight:700; line-height:1.3; max-height:6mm; overflow:hidden; padding-top:0.3mm;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; width:100%; }
        .label .l-bc{ width:100%; }
        .label .l-bc svg{ display:block; width:46mm; height:9mm; margin:0.4mm auto 0; }
        .label .l-num{ font-size:2.4mm; letter-spacing:0.5mm; font-variant-numeric:tabular-nums; margin-top:0.4mm; }
        .label .l-pr{ font-size:3.3mm; font-weight:800; margin-top:0.4mm; }

        @media print{
            html,body{ background:#fff; }
            .no-print{ display:none !important; }
            #printArea{ display:block !important; }
            @page{ size:50mm 25mm; margin:0; }
            .label{ page-break-after:always; break-after:page; }
            .label:last-child{ page-break-after:auto; break-after:auto; }
        }
    </style>
</head>
<body>
    <div class="wrap no-print">
        <div class="head">
            <h1>🏷 {{ __('طباعة باركود') }}</h1>
            <span class="count">{{ __('عدد الأصناف') }}: {{ count($items) }}</span>
            <div class="spacer"></div>
            <a class="btn" href="{{ route('admin.pos.screen') }}">← {{ __('رجوع لنقطة البيع') }}</a>
        </div>

        <form method="GET" class="toolbar">
            <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('بحث برقم الباركود أو اسم الصنف') }}" autofocus>
            <select name="category">
                <option value="">{{ __('كل الفئات') }}</option>
                @foreach ($categories as $c)
                    <option value="{{ $c['id'] }}" @selected($category === $c['id'])>{{ $c['name'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">{{ __('بحث') }}</button>
            <div class="spacer"></div>
            <div class="qtybox">
                {{ __('كمية موحّدة') }}
                <input type="number" id="bulkQty" min="1" value="1">
                <button type="button" class="btn" onclick="applyBulkQty()">{{ __('تطبيق') }}</button>
            </div>
            <button type="button" class="btn brand" onclick="printSelected()">🖨 <span id="printLabel">{{ __('طباعة المحدد') }}</span></button>
        </form>

        @if (count($items) === 0)
            <div class="card"><div class="empty">{{ __('لا توجد أصناف مطابقة.') }}</div></div>
        @else
            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:42px"><input type="checkbox" class="pick" onchange="selectAll(this.checked)" title="{{ __('تحديد الكل') }}"></th>
                            <th>{{ __('اسم الصنف') }}</th>
                            <th style="width:150px">{{ __('رقم الباركود') }}</th>
                            <th style="width:230px">{{ __('شكل الباركود') }}</th>
                            <th style="width:90px">{{ __('المتوفّر') }}</th>
                            <th style="width:110px">{{ __('السعر') }}</th>
                            <th style="width:90px">{{ __('كمية') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $it)
                            <tr class="item">
                                <td><input type="checkbox" class="pick" onchange="onPick(this)" onclick="event.stopPropagation()"></td>
                                <td class="nm">{{ $it['product'] }}</td>
                                <td class="num">{{ $it['barcode'] }}</td>
                                <td class="svgbox">{!! Code128::svg($it['barcode'], 40, 1.4) !!}</td>
                                <td class="stk"><span class="badge {{ $it['stock'] <= 0 ? 'out' : ($it['stock'] <= 5 ? 'low' : '') }}">{{ (int) $it['stock'] == $it['stock'] ? (int) $it['stock'] : number_format($it['stock'], 2) }}</span></td>
                                <td class="pr">{{ $money($it['price']) }}</td>
                                <td><input type="number" class="qty" min="1" value="1" onclick="event.stopPropagation()"></td>
                                {{-- قالب الملصق المطبوع (يُستنسخ عند الطباعة): اسم الصنف · الباركود · الرقم · السعر --}}
                                <template class="lbl">
                                    <div class="label">
                                        <div class="l-nm">{{ $it['product'] }}</div>
                                        <div class="l-bc">{!! Code128::svg($it['barcode'], 40, 1.4) !!}</div>
                                        <div class="l-num">{{ $it['barcode'] }}</div>
                                        <div class="l-pr">{{ $money($it['price']) }}</div>
                                    </div>
                                </template>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div id="printArea"></div>

    <script>
        function onPick(cb){
            cb.closest('tr').classList.toggle('sel', cb.checked);
            updateCount();
        }
        function selectAll(state){
            document.querySelectorAll('tbody .pick').forEach(cb => { cb.checked = state; onPick(cb); });
        }
        function applyBulkQty(){
            const v = Math.max(1, parseInt(document.getElementById('bulkQty').value || '1', 10));
            document.querySelectorAll('tbody tr.item').forEach(tr => {
                if (tr.querySelector('.pick').checked) tr.querySelector('.qty').value = v;
            });
        }
        function updateCount(){
            const n = document.querySelectorAll('tbody .pick:checked').length;
            document.getElementById('printLabel').textContent = n ? '{{ __('طباعة المحدد') }} (' + n + ')' : '{{ __('طباعة المحدد') }}';
        }
        // النقر على الصفّ يبدّل التحديد (عدا الحقول)
        document.querySelectorAll('tbody tr.item').forEach(tr => {
            tr.addEventListener('click', e => {
                if (e.target.closest('input')) return;
                const cb = tr.querySelector('.pick');
                cb.checked = !cb.checked; onPick(cb);
            });
        });
        function printSelected(){
            const area = document.getElementById('printArea');
            area.innerHTML = '';
            const picked = document.querySelectorAll('tbody .pick:checked');
            if (!picked.length){ alert('{{ __('اختر صنفًا واحدًا على الأقل للطباعة.') }}'); return; }
            picked.forEach(cb => {
                const tr = cb.closest('tr');
                const qty = Math.max(1, parseInt(tr.querySelector('.qty').value || '1', 10));
                const tpl = tr.querySelector('template.lbl');
                for (let i = 0; i < qty; i++) area.appendChild(tpl.content.cloneNode(true));
            });
            window.print();
        }
        window.addEventListener('afterprint', () => { document.getElementById('printArea').innerHTML = ''; });
        updateCount();
    </script>
</body>
</html>
