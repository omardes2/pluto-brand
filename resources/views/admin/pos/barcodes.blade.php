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
        .wrap{ max-width:1200px; margin:0 auto; padding:18px 16px 60px; }
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
            border-radius:8px; padding:8px 10px; min-width:180px; }
        .toolbar .qtybox{ display:flex; align-items:center; gap:6px; font-size:13px; color:var(--muted); }
        .toolbar .qtybox input{ width:64px; font-family:inherit; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:8px 10px; text-align:center; }
        .grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:12px; }
        .item{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:12px; display:flex; flex-direction:column; gap:8px;
            cursor:pointer; transition:.12s; position:relative; }
        .item:hover{ border-color:#cbd5e1; }
        .item.sel{ border-color:var(--brand); box-shadow:0 0 0 2px rgba(5,150,105,.15); }
        .item .top{ display:flex; align-items:center; gap:8px; }
        .item .pick{ width:18px; height:18px; accent-color:var(--brand); }
        .item .nm{ font-weight:700; font-size:13px; line-height:1.35; flex:1; }
        .item .opt{ color:var(--muted); font-weight:600; }
        .item .svgbox{ background:#fff; border:1px solid var(--line); border-radius:8px; padding:6px; }
        .item .svgbox svg{ display:block; width:100%; height:40px; }
        .item .num{ text-align:center; font-size:12.5px; letter-spacing:1.5px; color:#374151; font-variant-numeric:tabular-nums; }
        .item .foot{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .item .pr{ font-weight:800; color:var(--brand); font-size:14px; }
        .item .qtywrap{ display:flex; align-items:center; gap:5px; font-size:11.5px; color:var(--muted); }
        .item .qty{ width:56px; font-family:inherit; font-size:13px; border:1px solid var(--line); border-radius:7px; padding:5px 6px; text-align:center; }
        .empty{ text-align:center; color:var(--muted); padding:60px 20px; }

        /* منطقة الطباعة — تظهر عند الطباعة فقط */
        #printArea{ display:none; }
        .label{ width:50mm; height:25mm; padding:1.2mm 1.5mm; display:flex; flex-direction:column; align-items:center; justify-content:center;
            overflow:hidden; text-align:center; }
        .label .l-nm{ font-size:2.5mm; font-weight:700; line-height:1.15; max-height:5.6mm; overflow:hidden;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; width:100%; }
        .label .l-bc{ width:100%; }
        .label .l-bc svg{ display:block; width:46mm; height:10mm; margin:0.6mm auto 0; }
        .label .l-num{ font-size:2.6mm; letter-spacing:0.6mm; font-variant-numeric:tabular-nums; margin-top:0.4mm; }
        .label .l-pr{ font-size:3.4mm; font-weight:800; margin-top:0.4mm; }

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
            <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('بحث بالاسم أو الباركود أو الكود') }}">
            <select name="category">
                <option value="">{{ __('كل الفئات') }}</option>
                @foreach ($categories as $c)
                    <option value="{{ $c['id'] }}" @selected($category === $c['id'])>{{ $c['name'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">{{ __('بحث') }}</button>
            <div class="spacer"></div>
            <button type="button" class="btn" onclick="selectAll(true)">{{ __('تحديد الكل') }}</button>
            <button type="button" class="btn" onclick="selectAll(false)">{{ __('إلغاء التحديد') }}</button>
            <div class="qtybox">
                {{ __('كمية موحّدة') }}
                <input type="number" id="bulkQty" min="1" value="1">
                <button type="button" class="btn" onclick="applyBulkQty()">{{ __('تطبيق') }}</button>
            </div>
            <button type="button" class="btn brand" onclick="printSelected()">🖨 <span id="printLabel">{{ __('طباعة المحدد') }}</span></button>
        </form>

        @if (count($items) === 0)
            <div class="empty">{{ __('لا توجد أصناف مطابقة.') }}</div>
        @else
            <div class="grid">
                @foreach ($items as $it)
                    @php $label = $it['product'].($it['option'] ? ' — '.$it['option'] : ''); @endphp
                    <label class="item" data-i="{{ $loop->index }}">
                        <div class="top">
                            <input type="checkbox" class="pick" onchange="onPick(this)">
                            <div class="nm">{{ $it['product'] }}@if ($it['option'])<span class="opt"> — {{ $it['option'] }}</span>@endif</div>
                        </div>
                        <div class="svgbox">{!! Code128::svg($it['barcode'], 40, 1.4) !!}</div>
                        <div class="num">{{ $it['barcode'] }}</div>
                        <div class="foot">
                            <span class="pr">{{ $money($it['price']) }}</span>
                            <span class="qtywrap">{{ __('كمية') }}
                                <input type="number" class="qty" min="1" value="1" onclick="event.stopPropagation()">
                            </span>
                        </div>
                        {{-- قالب الملصق المطبوع (يُستنسخ عند الطباعة) --}}
                        <template class="lbl">
                            <div class="label">
                                <div class="l-nm">{{ $label }}</div>
                                <div class="l-bc">{!! Code128::svg($it['barcode'], 40, 1.4) !!}</div>
                                <div class="l-num">{{ $it['barcode'] }}</div>
                                <div class="l-pr">{{ $money($it['price']) }}</div>
                            </div>
                        </template>
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    <div id="printArea"></div>

    <script>
        function onPick(cb){
            const item = cb.closest('.item');
            item.classList.toggle('sel', cb.checked);
            updateCount();
        }
        function selectAll(state){
            document.querySelectorAll('.item .pick').forEach(cb => { cb.checked = state; onPick(cb); });
        }
        function applyBulkQty(){
            const v = Math.max(1, parseInt(document.getElementById('bulkQty').value || '1', 10));
            document.querySelectorAll('.item').forEach(item => {
                if (item.querySelector('.pick').checked) item.querySelector('.qty').value = v;
            });
        }
        function updateCount(){
            const n = document.querySelectorAll('.item .pick:checked').length;
            document.getElementById('printLabel').textContent = n ? '{{ __('طباعة المحدد') }} (' + n + ')' : '{{ __('طباعة المحدد') }}';
        }
        // اختيار البطاقة بالنقر على أي مكان (عدا الحقول)
        document.querySelectorAll('.item').forEach(item => {
            item.addEventListener('click', e => {
                if (e.target.closest('.qty') || e.target.closest('.pick')) return;
                const cb = item.querySelector('.pick');
                cb.checked = !cb.checked; onPick(cb);
            });
        });
        function printSelected(){
            const area = document.getElementById('printArea');
            area.innerHTML = '';
            const picked = document.querySelectorAll('.item .pick:checked');
            if (!picked.length){ alert('{{ __('اختر صنفًا واحدًا على الأقل للطباعة.') }}'); return; }
            picked.forEach(cb => {
                const item = cb.closest('.item');
                const qty = Math.max(1, parseInt(item.querySelector('.qty').value || '1', 10));
                const tpl = item.querySelector('template.lbl');
                for (let i = 0; i < qty; i++) area.appendChild(tpl.content.cloneNode(true));
            });
            window.print();
        }
        window.addEventListener('afterprint', () => { document.getElementById('printArea').innerHTML = ''; });
        updateCount();
    </script>
</body>
</html>
