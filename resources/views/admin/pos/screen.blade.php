<x-pos-layout :title="__('نقطة البيع')">
@php
    $expenseCategories = array_values(array_filter(array_map('trim',
        explode(',', (string) \App\Modules\Foundation\Services\Settings::get('pos.expense_categories', 'أخرى')))));
@endphp
@push('styles')
<style>
  :root{
    --bg:#eef2f0;--surface:#fff;--surface-2:#f4f7f5;--border:#dbe4df;--text:#0e1a16;--muted:#64756e;--faint:#8b9a93;
    --accent:#059669;--accent-strong:#047857;--accent-soft:#dcf1e8;--accent-contrast:#fff;
    --chrome:#0f1b17;--chrome-text:#dbe7e1;--chrome-muted:#7f948b;--danger:#dc2626;--danger-soft:#fbe6e6;
    --warning:#b45309;--warning-soft:#f8ecd6;--shadow:0 1px 2px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.06);
  }
  :root[data-theme="dark"]{
    --bg:#0b100e;--surface:#131d18;--surface-2:#182420;--border:#27332d;--text:#e8f1ec;--muted:#93a89d;--faint:#6f847a;
    --accent:#10b981;--accent-strong:#34d399;--accent-soft:#0f2b21;--accent-contrast:#04140d;
    --chrome:#080d0b;--chrome-text:#cfdcd5;--chrome-muted:#748a80;--danger:#f87171;--danger-soft:#2a1615;
    --warning:#fbbf24;--warning-soft:#2a2113;--shadow:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:'Tajawal','Noto Sans Arabic','Segoe UI',system-ui,sans-serif}
  .tnum{font-variant-numeric:tabular-nums}
  [x-cloak]{display:none!important}
  .pos{display:grid;grid-template-rows:auto 1fr;height:100vh;height:100dvh;overflow:hidden}
  .topbar{background:var(--chrome);color:var(--chrome-text);display:flex;align-items:center;gap:14px;padding:0 18px;height:60px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
  .brand .mark{width:34px;height:34px;border-radius:9px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800}
  .brand small{display:block;font-size:11px;font-weight:600;color:var(--chrome-muted);letter-spacing:1.5px}
  .sep{width:1px;height:26px;background:rgba(255,255,255,.1)}
  .meta{display:flex;align-items:center;gap:7px;color:var(--chrome-muted);font-size:13px;font-weight:600}
  .meta b{color:var(--chrome-text);font-weight:700}
  .meta .dot{width:8px;height:8px;border-radius:50%;background:var(--accent)}
  .spacer{flex:1}
  .topbtn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--chrome-text);height:38px;
    padding:0 14px;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;display:flex;align-items:center;gap:7px;text-decoration:none}
  .topbtn:hover{background:rgba(255,255,255,.13);border-color:var(--accent);color:#fff}
  .icon-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:var(--chrome-text);width:38px;height:38px;
    border-radius:10px;cursor:pointer;display:grid;place-items:center}
  .cashier{display:flex;align-items:center;gap:9px}
  .cashier .avatar{width:36px;height:36px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800}
  .cashier .who{font-size:13px;line-height:1.25}
  .cashier .who b{display:block;color:var(--chrome-text)}
  .cashier .who span{color:var(--chrome-muted);font-size:11.5px}
  .workspace{display:grid;grid-template-columns:1fr 400px;min-height:0}
  @media (max-width:920px){.workspace{grid-template-columns:1fr}}
  .catalog{display:flex;flex-direction:column;min-height:0;padding:16px 16px 0}
  .searchrow{display:flex;gap:10px;margin-bottom:12px}
  .field{position:relative;flex:1}
  .field svg{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--faint)}
  .field input{width:100%;height:46px;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:12px;
    padding:0 42px 0 14px;font-size:15px;font-family:inherit;outline:none}
  .field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
  .field.barcode{flex:0 0 240px}.field.barcode svg{color:var(--accent)}
  .chips{display:flex;gap:8px;overflow-x:auto;padding-bottom:12px}
  .chip{white-space:nowrap;border:1px solid var(--border);background:var(--surface);color:var(--muted);padding:8px 15px;
    border-radius:999px;font-size:13.5px;font-weight:600;cursor:pointer}
  .chip.active{background:var(--accent);border-color:var(--accent);color:var(--accent-contrast)}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:12px;overflow-y:auto;padding:4px 4px 18px;align-content:start;min-height:0}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:12px;cursor:pointer;
    display:flex;flex-direction:column;gap:9px;position:relative;text-align:right;transition:.15s}
  .card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:var(--shadow)}
  .card.out{opacity:.5;cursor:not-allowed}
  .thumb{position:relative;height:96px;border-radius:10px;overflow:hidden;display:grid;place-items:center;font-size:26px;background:var(--surface-2);border:1px solid var(--border);color:var(--faint)}
  .thumb .nm-ov{position:absolute;inset-inline:0;bottom:0;padding:16px 8px 7px;text-align:right;
    font-weight:800;font-size:12.5px;line-height:1.3;color:#1a1a1a;text-shadow:0 1px 2px rgba(255,255,255,.9);
    background:linear-gradient(to top,rgba(255,255,255,.96),rgba(255,255,255,.86) 55%,rgba(255,255,255,0));
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .card .foot{display:flex;align-items:baseline;justify-content:space-between;gap:6px}
  .price{font-weight:800;font-size:15px;color:var(--accent-strong)}
  .price .cur{font-size:11px;font-weight:700;color:var(--muted)}
  .price.old{font-size:12px;font-weight:600;color:var(--faint);text-decoration:line-through}
  .stk{font-size:11px;font-weight:700;color:var(--muted);background:var(--surface-2);border:1px solid var(--border);padding:2px 7px;border-radius:6px}
  .stk.low{color:var(--warning);background:var(--warning-soft);border-color:transparent}
  .tag{position:absolute;top:10px;inset-inline-start:10px;background:var(--danger);color:#fff;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:6px}
  .ticket{background:var(--surface);border-inline-start:1px solid var(--border);display:flex;flex-direction:column;min-height:0}
  .tabs{display:flex;gap:6px;align-items:center;padding:10px 12px 0;overflow-x:auto;border-bottom:1px solid var(--border);background:var(--surface-2)}
  .tab{display:flex;align-items:center;gap:7px;white-space:nowrap;border:1px solid var(--border);border-bottom:none;background:var(--surface-2);
    color:var(--muted);border-radius:10px 10px 0 0;padding:8px 12px;font-weight:700;font-size:13px;font-family:inherit;cursor:pointer;position:relative}
  .tab.active{background:var(--surface);color:var(--text);box-shadow:0 -2px 0 var(--accent) inset}
  .tab .tcount{background:var(--accent);color:#fff;border-radius:999px;font-size:11px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 5px;line-height:1}
  .tab .tclose{color:var(--faint);font-size:12px;padding:1px 4px;border-radius:5px}
  .tab .tclose:hover{color:var(--danger);background:var(--danger-soft)}
  .tab.add{color:var(--accent-strong);background:var(--surface);font-size:17px;padding:7px 13px;flex:0 0 auto}
  .tab.add:hover{border-color:var(--accent);background:var(--accent-soft)}
  .ticket-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:12px}
  .ticket-top{display:flex;align-items:center;justify-content:space-between}
  .ticket-top .tno{font-weight:800;font-size:14px}
  .ticket-top .tno span{color:var(--muted);font-weight:600;font-size:12px}
  .customer{display:flex;align-items:center;gap:10px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:9px 12px}
  .customer .ci{width:34px;height:34px;border-radius:50%;background:var(--accent-soft);color:var(--accent-strong);display:grid;place-items:center;flex-shrink:0}
  .customer .cd{flex:1;line-height:1.3}.customer .cd b{font-size:13.5px;display:block}.customer .cd span{font-size:12px;color:var(--muted)}
  .lines{flex:1;overflow-y:auto;padding:8px 10px;min-height:0;display:flex;flex-direction:column;gap:6px}
  .empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--faint);text-align:center;padding:30px}
  .line{display:grid;grid-template-columns:1fr auto;gap:8px;padding:10px;border-radius:12px}
  .line:hover{background:var(--surface-2)}
  .line .l-nm{font-weight:700;font-size:13.5px;line-height:1.35}
  .line .l-unit{font-size:12px;color:var(--muted);margin-top:2px}
  .line .l-right{display:flex;flex-direction:column;align-items:flex-start;gap:8px}
  .line .l-total{font-weight:800;font-size:14px}
  .qty{display:flex;align-items:center;border:1px solid var(--border);border-radius:9px;overflow:hidden}
  .qty button{width:28px;height:28px;border:none;background:var(--surface);color:var(--text);cursor:pointer;font-size:16px;font-weight:700}
  .qty button:hover{background:var(--accent-soft);color:var(--accent-strong)}
  .qty .q{min-width:30px;text-align:center;font-weight:800;font-size:13.5px}
  .rm{border:none;background:none;color:var(--faint);cursor:pointer;padding:2px;border-radius:6px}
  .rm:hover{color:var(--danger);background:var(--danger-soft)}
  .summary{border-top:1px solid var(--border);padding:14px 16px 16px;display:flex;flex-direction:column;gap:11px}
  .disc{display:flex;gap:8px;align-items:center}
  .disc input{flex:1;height:40px;border:1px solid var(--border);background:var(--surface-2);color:var(--text);border-radius:10px;padding:0 12px;font-family:inherit;outline:none}
  .disc label{font-size:13px;font-weight:700;color:var(--muted)}
  .totals{display:flex;flex-direction:column;gap:6px;font-size:14px}
  .trow{display:flex;justify-content:space-between;color:var(--muted)}
  .trow b{color:var(--text);font-weight:700}
  .trow.grand{font-size:20px;padding-top:8px;margin-top:2px;border-top:1px dashed var(--border)}
  .trow.grand span{font-weight:800;color:var(--text)}.trow.grand b{color:var(--accent-strong);font-weight:800}
  .pay-methods{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
  .pm.disabled{opacity:.4;cursor:not-allowed}
  .pm{border:1.5px solid var(--border);background:var(--surface);color:var(--muted);border-radius:11px;padding:10px 4px;cursor:pointer;
    font-weight:700;font-size:13px;display:flex;flex-direction:column;align-items:center;gap:5px}
  .pm.active{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-strong)}
  .cash-in input{width:100%;height:44px;border:1px solid var(--border);background:var(--surface-2);color:var(--text);border-radius:11px;
    padding:0 12px;font-size:16px;font-weight:700;font-family:inherit;outline:none;text-align:center}
  .quick{display:flex;gap:6px;margin-top:8px}
  .quick button{flex:1;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:9px;height:34px;font-weight:700;font-size:12.5px;cursor:pointer}
  .quick button:hover{border-color:var(--accent);background:var(--accent-soft)}
  .change{display:flex;justify-content:space-between;align-items:center;background:var(--accent-soft);border-radius:11px;padding:10px 14px;font-weight:800}
  .change span,.change b{color:var(--accent-strong)}.change b{font-size:18px}
  .actions{display:flex;gap:8px}
  .btn{border:none;border-radius:12px;font-family:inherit;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
  .btn-pay{flex:1;height:54px;background:var(--accent);color:var(--accent-contrast);font-size:17px}
  .btn-pay:hover:not(:disabled){background:var(--accent-strong)}
  .btn-pay:disabled{opacity:.4;cursor:not-allowed}
  .btn-ghost{height:54px;padding:0 16px;background:var(--surface-2);color:var(--muted);border:1px solid var(--border);font-size:14px}
  .btn-ghost:hover{color:var(--danger);border-color:var(--danger)}
  .overlay{position:fixed;inset:0;background:rgba(8,12,10,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:20px}
  .receipt{background:var(--surface);border-radius:18px;width:340px;max-height:92vh;overflow:auto;box-shadow:var(--shadow)}
  .r-head{background:var(--accent);color:#fff;padding:20px;text-align:center;border-radius:18px 18px 0 0}
  .r-head .ok{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.2);display:grid;place-items:center;margin:0 auto 10px}
  .r-head b{font-size:18px;display:block}.r-head span{font-size:13px;opacity:.9}
  .r-body{padding:18px 20px;font-size:13px}
  .r-store{text-align:center;padding-bottom:12px;border-bottom:1px dashed var(--border);margin-bottom:12px}
  .r-store b{font-size:16px}.r-store span{display:block;color:var(--muted);font-size:12px}
  .r-line{display:flex;justify-content:space-between;padding:4px 0;color:var(--muted)}.r-line .n{color:var(--text);font-weight:600;flex:1}
  .r-tot{border-top:1px dashed var(--border);margin-top:10px;padding-top:10px}
  .r-tot .grand{display:flex;justify-content:space-between;font-size:17px;font-weight:800;margin-top:4px}.r-tot .grand b{color:var(--accent-strong)}
  .r-actions{display:flex;gap:8px;padding:0 20px 20px}
  .r-actions button{flex:1;height:46px;border-radius:11px;font-weight:800;font-family:inherit;cursor:pointer;border:none}
  .r-print{background:var(--surface-2);color:var(--text);border:1px solid var(--border)!important}
  .r-new{background:var(--accent);color:var(--accent-contrast)}
  .toast{position:fixed;top:72px;left:50%;transform:translateX(-50%);background:var(--chrome);color:var(--chrome-text);
    border:1px solid rgba(255,255,255,.12);padding:12px 18px;border-radius:12px;font-size:13.5px;font-weight:600;z-index:60;box-shadow:var(--shadow)}
  .expl{font-size:13px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px}
  .expi{width:100%;height:42px;border:1px solid var(--border);background:var(--surface-2);color:var(--text);border-radius:10px;padding:0 12px;font-family:inherit;outline:none;font-size:14px}
  .expi:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
</style>
@endpush

<div class="pos" x-data="pos()" x-cloak>
  <div class="topbar">
    <div class="brand"><span class="mark">P</span><span>{{ config('app.name') }}<small>{{ __('نقطة البيع · POS') }}</small></span></div>
    <div class="sep"></div>
    <div class="meta"><span class="dot"></span> {{ __('فاتورة') }} <b x-text="(active+1)"></b> / <span x-text="tickets.length"></span></div>
    <div class="spacer"></div>
    <button class="topbtn" x-on:click="addTicket()">＋ {{ __('فاتورة جديدة') }}</button>
    <a class="topbtn" href="{{ route('admin.pos.barcodes') }}" target="_blank" rel="noopener">🏷 {{ __('طباعة باركود') }}</a>
    <button class="topbtn" x-on:click="returnMode()">↩ {{ __('إرجاع / تبديل') }}</button>
    <button class="topbtn" x-on:click="openExpense()">🧾 {{ __('مصروف') }}</button>
    <a class="topbtn" href="{{ route('admin.pos.shift.close_form') }}">{{ __('إغلاق الوردية') }} ({{ $shift->number }})</a>
    <button class="icon-btn" x-on:click="toggleTheme()" title="{{ __('تبديل السمة') }}">◐</button>
    <div class="sep"></div>
    <div class="cashier"><div class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
      <div class="who"><b>{{ auth()->user()->name }}</b><span>{{ __('الكاشير') }}</span></div></div>
  </div>

  <div class="workspace">
    <div class="catalog">
      <div class="searchrow">
        <div class="field">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/></svg>
          <input x-model="q" x-on:input.debounce.350ms="loadProducts()" placeholder="{{ __('ابحث بالاسم أو الكود…') }}" autocomplete="off">
        </div>
        <div class="field barcode">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5v14M7 5v14M11 5v14M14 5v14M18 5v14M21 5v14" stroke-linecap="round"/></svg>
          <input x-model="barcodeInput" x-on:keydown.enter.prevent="scan()" placeholder="{{ __('امسح الباركود…') }}" autocomplete="off">
        </div>
      </div>
      <div class="chips">
        <button class="chip" :class="cat===null && 'active'" x-on:click="setCat(null)">{{ __('الكل') }}</button>
        <template x-for="c in categories" :key="c.id">
          <button class="chip" :class="cat===c.id && 'active'" x-on:click="setCat(c.id)" x-text="c.name"></button>
        </template>
      </div>
      <div class="grid">
        <template x-for="p in products" :key="p.product_id">
          <div class="card" x-on:click="pick(p)">
            <span class="tag" x-show="p.has_promo">{{ __('عرض') }}</span>
            <div class="thumb">
              <template x-if="p.image"><img :src="p.image" alt="" style="width:100%;height:100%;object-fit:cover"></template>
              <template x-if="!p.image"><span>🛒</span></template>
              <div class="nm-ov" x-text="p.name"></div>
            </div>
            <div class="foot">
              <div>
                <span class="price tnum"><span x-text="p.price.toFixed(2)"></span><span class="cur"> ₪</span></span>
                <div class="price old tnum" x-show="p.has_promo"><span x-text="p.retail.toFixed(2)"></span> ₪</div>
              </div>
              <span class="stk" :class="p.stock<=8 && 'low'" x-text="p.stock"></span>
            </div>
            <div x-show="p.variants.length>1" style="font-size:11px;color:var(--faint);margin-top:3px" x-text="p.axes.map(a=>a.name).join(' · ')"></div>
          </div>
        </template>
        <div x-show="products.length===0" style="grid-column:1/-1;color:var(--faint);text-align:center;padding:40px">{{ __('لا توجد نتائج') }}</div>
      </div>
    </div>

    <div class="ticket">
      <div class="tabs">
        <template x-for="(tk,i) in tickets" :key="tk.id">
          <button class="tab" :class="i===active && 'active'" x-on:click="active=i">
            <span x-text="'{{ __('فاتورة') }} '+(i+1)"></span>
            <span class="tcount" x-show="tk.cart.length" x-text="tk.cart.length"></span>
            <span class="tclose" x-show="tickets.length>1" x-on:click.stop="closeTicket(i)">✕</span>
          </button>
        </template>
        <button class="tab add" x-on:click="addTicket()" title="{{ __('فاتورة جديدة') }}">＋</button>
      </div>
      <div class="ticket-head">
        <div class="ticket-top"><div class="tno">{{ __('فاتورة') }} <span x-text="(active+1)"></span></div></div>
        <div class="customer" x-on:click="openCustomer()" style="cursor:pointer">
          <div class="ci">👤</div>
          <div class="cd"><b x-text="custName"></b><span x-text="customerId ? '{{ __('عميل مسجّل — اضغط للتغيير') }}' : '{{ __('عميل نقدي — اضغط لاختيار عميل') }}'"></span></div>
          <button x-show="customerId" class="chg" x-on:click.stop="resetCustomer()" style="border:none;background:none;cursor:pointer">{{ __('إزالة') }}</button>
        </div>
      </div>

      <div class="lines">
        <template x-if="cart.length===0">
          <div class="empty">🧾<div>{{ __('الفاتورة فارغة') }}<br><span style="font-size:13px">{{ __('اختر منتجًا أو امسح الباركود للبدء') }}</span></div></div>
        </template>
        <template x-for="(l,idx) in cart" :key="l.variant_id">
          <div class="line">
            <div><div class="l-nm" x-text="l.name"></div>
              <div class="l-unit tnum"><span x-text="l.price.toFixed(2)"></span> ₪ × <span x-text="l.qty"></span></div></div>
            <div class="l-right">
              <div class="l-total tnum"><span x-text="(l.price*l.qty).toFixed(2)"></span> ₪</div>
              <div style="display:flex;align-items:center;gap:6px">
                <div class="qty"><button x-on:click="inc(idx)">+</button><span class="q tnum" x-text="l.qty"></span><button x-on:click="dec(idx)">−</button></div>
                <button class="rm" x-on:click="remove(idx)" title="{{ __('حذف') }}">🗑</button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <div class="summary">
        @can('pos.discount')
          <div class="disc"><label>{{ __('خصم') }}</label><input x-model.number="discount" type="number" min="0" inputmode="decimal"><label>₪</label></div>
        @endcan
        <div class="totals">
          <div class="trow"><span>{{ __('الإجمالي الفرعي') }}</span><b class="tnum" x-text="money(subtotal)"></b></div>
          <div class="trow" x-show="discountVal>0"><span>{{ __('الخصم') }}</span><b class="tnum" x-text="'− '+money(discountVal)"></b></div>
          <div class="trow grand"><span>{{ __('الإجمالي') }}</span><b class="tnum" x-text="money(total)"></b></div>
        </div>
        <div class="pay-methods">
          <div class="pm" :class="method==='cash' && 'active'" x-on:click="method='cash'">💵 {{ __('نقدي') }}</div>
          <div class="pm" :class="{'active': method==='credit', 'disabled': !customerId}" x-on:click="customerId ? method='credit' : toast('{{ __('اختر عميلًا أولًا للبيع الآجل') }}')">📒 {{ __('آجل') }}</div>
        </div>
        <div x-show="method==='cash'">
          <div class="cash-in"><input x-model.number="tendered" type="number" min="0" placeholder="{{ __('المبلغ المدفوع') }}" inputmode="decimal"></div>
          <div class="quick">
            <button x-on:click="tendered=(tendered||0)+50">50</button>
            <button x-on:click="tendered=(tendered||0)+100">100</button>
            <button x-on:click="tendered=(tendered||0)+200">200</button>
            <button x-on:click="tendered=total">{{ __('بالضبط') }}</button>
          </div>
        </div>
        <div class="change" x-show="method==='cash' && tendered!==null && tendered!==''">
          <span>{{ __('الباقي للعميل') }}</span><b class="tnum" x-text="money(change)"></b>
        </div>
        <div class="actions">
          <button class="btn btn-pay" :disabled="cart.length===0 || busy" x-on:click="pay()">
            <span x-show="!busy"><span x-text="method==='credit' ? '{{ __('بيع آجل (ذمم)') }}' : '{{ __('تحصيل ودفع') }}'"></span> · <span class="tnum" x-text="money(total)"></span></span>
            <span x-show="busy">{{ __('جارٍ…') }}</span>
          </button>
          <button class="btn btn-ghost" x-on:click="clearCart()" title="{{ __('إلغاء الفاتورة') }}">✕</button>
        </div>
      </div>
    </div>
  </div>

  <div class="overlay" x-show="receipt" x-cloak style="display:none" :style="receipt && 'display:flex'">
    <div class="receipt" x-show="receipt">
      <div class="r-head"><div class="ok">✓</div><b>{{ __('تمّ الدفع بنجاح') }}</b><span x-text="{cash:'{{ __('نقدي') }}',card:'{{ __('بطاقة') }}',credit:'{{ __('آجل / ذمم') }}'}[receipt?.method] || '{{ __('نقدي') }}'"></span></div>
      <div class="r-body">
        <div class="r-store"><b>{{ config('app.name') }}</b><span>{{ $shift->branch?->name }} · {{ __('فاتورة') }} <span x-text="receipt?.number"></span></span></div>
        <template x-for="l in (receipt?.lines||[])" :key="l.name">
          <div class="r-line"><span class="n" x-text="l.name+' ×'+l.qty"></span><span class="tnum" x-text="(l.price*l.qty).toFixed(2)"></span></div>
        </template>
        <div class="r-tot">
          <div class="r-line"><span>{{ __('الفرعي') }}</span><span class="tnum" x-text="money(receipt?.subtotal)"></span></div>
          <div class="r-line"><span>{{ __('الخصم') }}</span><span class="tnum" x-text="'− '+money(receipt?.discount_total)"></span></div>
          <div class="grand"><span>{{ __('الإجمالي') }}</span><b class="tnum" x-text="money(receipt?.total)"></b></div>
          <div class="r-line" style="margin-top:8px"><span>{{ __('المدفوع') }}</span><span class="tnum" x-text="money(receipt?.paid)"></span></div>
          <div class="r-line"><span>{{ __('الباقي') }}</span><span class="tnum" x-text="money(receipt?.change)"></span></div>
        </div>
        <div style="font-size:11px;color:var(--faint);text-align:center;padding-top:8px">{{ \App\Modules\Foundation\Services\Settings::get('pos.receipt_footer', '') }}</div>
      </div>
      <div class="r-actions">
        <button class="r-print" x-on:click="printReceipt()">🖨 {{ __('طباعة') }}</button>
        <button class="r-new" x-on:click="newSale()">{{ __('بيع جديد') }}</button>
      </div>
    </div>
  </div>

  {{-- نافذة اختيار العميل --}}
  <div class="overlay" x-show="showCustomer" x-cloak style="display:none" :style="showCustomer && 'display:flex'">
    <div class="receipt" style="width:400px;max-width:94vw">
      <div class="r-head" style="background:var(--chrome)"><div class="ok">👤</div><b>{{ __('اختيار عميل') }}</b><span>{{ __('للبيع الآجل (ذمم) أو ربط الفاتورة') }}</span></div>
      <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px">
        <input class="expi" x-model="cust.q" x-on:input.debounce.300ms="custSearch()" placeholder="{{ __('ابحث بالاسم أو الهاتف…') }}">
        <div style="max-height:240px;overflow:auto;display:flex;flex-direction:column;gap:6px">
          <template x-for="c in cust.results" :key="c.id">
            <div x-on:click="pickCustomer(c)" style="border:1px solid var(--border);border-radius:10px;padding:10px;cursor:pointer;display:flex;justify-content:space-between;align-items:center">
              <b x-text="c.name"></b><span class="tnum" style="color:var(--muted);font-size:12px" x-text="c.phone||''"></span>
            </div>
          </template>
          <div x-show="cust.q && !cust.results.length" style="color:var(--faint);text-align:center;padding:12px">{{ __('لا نتائج') }}</div>
        </div>
      </div>
      <div class="r-actions">
        <button class="r-print" x-on:click="resetCustomer(); showCustomer=false">{{ __('عميل نقدي') }}</button>
        <button class="r-new" x-on:click="showCustomer=false">{{ __('إغلاق') }}</button>
      </div>
    </div>
  </div>

  {{-- محدّد اللون/المقاس --}}
  <div class="overlay" x-show="sel.open" x-cloak style="display:none" :style="sel.open && 'display:flex'" x-on:click.self="sel.open=false">
    <div class="receipt" style="width:470px;max-width:94vw">
      <div class="r-head" style="background:var(--chrome)">
        <div class="ok">🛍</div><b x-text="sel.product?.name"></b>
        <span class="tnum" x-text="money(sel.product?.price||0)"></span>
      </div>
      <div style="padding:16px 20px;display:flex;flex-direction:column;gap:16px">
        {{-- اللون (المحور الأول) --}}
        <template x-if="selColorAxis()">
          <div>
            <div style="font-size:12px;color:var(--muted);font-weight:700;margin-bottom:8px" x-text="selColorAxis().name"></div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
              <template x-for="cv in selColorValues()" :key="cv.value_id">
                <button type="button" x-on:click="sel.colorVal=cv.value_id"
                  :style="'display:flex;align-items:center;gap:6px;border:1.5px solid '+(sel.colorVal===cv.value_id?'var(--accent)':'var(--border)')+';border-radius:999px;padding:6px 12px;font-weight:700;font-size:13px;background:'+(sel.colorVal===cv.value_id?'var(--accent-soft)':'var(--surface)')+';cursor:pointer;color:inherit'">
                  <span x-show="cv.color_hex" :style="'width:14px;height:14px;border-radius:50%;border:1px solid rgba(0,0,0,.15);background:'+(cv.color_hex||'#ccc')"></span>
                  <span x-text="cv.label"></span>
                </button>
              </template>
            </div>
          </div>
        </template>
        {{-- المقاسات (المحور الثاني) أو المتغيّرات مباشرة — الرقم الصغير = المتوفّر --}}
        <div>
          <div style="font-size:12px;color:var(--muted);font-weight:700;margin-bottom:8px" x-text="selSizeAxis() ? selSizeAxis().name : '{{ __('الخيارات') }}'"></div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
            <template x-for="cell in selCells()" :key="cell.variant_id">
              <button type="button" :disabled="cell.stock<=0" x-on:click="addVariant(cell); sel.open=false"
                :style="'border:1.5px solid '+(cell.stock>0?'var(--accent)':'var(--border)')+';border-radius:10px;padding:8px 0;text-align:center;background:var(--surface);cursor:'+(cell.stock>0?'pointer':'not-allowed')+';opacity:'+(cell.stock>0?'1':'.4')+';color:inherit'">
                <div style="font-weight:800;font-size:15px" x-text="cell.cellLabel"></div>
                <div style="font-size:10px;color:var(--muted)" x-text="cell.stock"></div>
              </button>
            </template>
          </div>
        </div>
      </div>
      <div class="r-actions"><button class="r-new" x-on:click="sel.open=false">{{ __('إغلاق') }}</button></div>
    </div>
  </div>

  {{-- نافذة الإرجاع / التبديل --}}
  <div class="overlay" x-show="showReturn" x-cloak style="display:none" :style="showReturn && 'display:flex'">
    <div class="receipt" style="width:460px;max-width:94vw">
      <div class="r-head" style="background:var(--chrome)"><div class="ok">↩</div><b>{{ __('إرجاع / تبديل') }}</b><span>{{ __('استرداد نقدي من الدرج') }}</span></div>

      {{-- تبويبان --}}
      <div style="display:flex;gap:8px;padding:12px 20px 0">
        <button class="chip" :class="retTab==='invoice' && 'active'" x-on:click="retTab='invoice'">{{ __('بالفاتورة') }}</button>
        <button class="chip" :class="retTab==='noinvoice' && 'active'" x-on:click="retTab='noinvoice'">{{ __('بدون فاتورة') }}</button>
      </div>

      {{-- تبويب: بالفاتورة --}}
      <div x-show="retTab==='invoice'" style="padding:14px 20px;display:flex;flex-direction:column;gap:12px">
        <template x-if="!ret.order">
          <div style="display:flex;gap:8px">
            <input class="expi" x-model="ret.number" placeholder="{{ __('رقم الفاتورة (SO-…)') }}" x-on:keydown.enter.prevent="lookupReturn()">
            <button class="r-new" style="height:42px;padding:0 18px;border-radius:10px;font-weight:800" x-on:click="lookupReturn()">{{ __('بحث') }}</button>
          </div>
        </template>
        <template x-if="ret.order">
          <div style="display:flex;flex-direction:column;gap:10px">
            <div style="font-size:12.5px;color:var(--muted)">{{ __('العميل') }}: <b x-text="ret.order.customer_name"></b> · {{ __('الإجمالي') }}: <span class="tnum" x-text="money(ret.order.total)"></span></div>
            <template x-for="it in ret.items" :key="it.order_item_id">
              <div style="border:1px solid var(--border);border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:8px">
                <div style="display:flex;justify-content:space-between;gap:8px">
                  <div class="l-nm" x-text="it.name"></div>
                  <div style="font-size:12px;color:var(--muted)">{{ __('متاح') }}: <span x-text="it.returnable_qty"></span></div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                  <div class="qty"><button x-on:click="if(it.qty<it.returnable_qty)it.qty++">+</button><span class="q tnum" x-text="it.qty"></span><button x-on:click="if(it.qty>0)it.qty--">−</button></div>
                  <select class="expi" style="flex:1" x-model="it.condition">
                    <option value="sellable">{{ __('صالح للبيع') }}</option>
                    <option value="damaged">{{ __('تالف (لا يعود)') }}</option>
                  </select>
                  <div class="tnum" style="font-weight:800;min-width:74px;text-align:end" x-text="money(it.qty*it.unit_price)"></div>
                </div>
              </div>
            </template>
            <input class="expi" x-model="ret.note" placeholder="{{ __('ملاحظات (اختياري)') }}" maxlength="255">
            <div class="change"><span>{{ __('إجمالي الاسترداد') }}</span><b class="tnum" x-text="money(retRefund)"></b></div>
          </div>
        </template>
      </div>

      {{-- تبويب: بدون فاتورة --}}
      <div x-show="retTab==='noinvoice'" x-cloak style="padding:14px 20px;display:flex;flex-direction:column;gap:10px">
        <div style="position:relative">
          <input class="expi" x-model="ni.q" x-on:input.debounce.300ms="niSearch()" placeholder="{{ __('ابحث عن منتج لإضافته…') }}">
          <div x-show="ni.results.length" style="position:absolute;inset-inline:0;top:46px;z-index:5;background:var(--surface);border:1px solid var(--border);border-radius:10px;max-height:180px;overflow:auto;box-shadow:var(--shadow)">
            <template x-for="p in ni.results" :key="p.variant_id">
              <div x-on:click="niAdd(p)" style="padding:9px 12px;cursor:pointer;display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border)">
                <span x-text="p.name"></span><span class="tnum" style="color:var(--accent-strong);font-weight:700" x-text="p.price.toFixed(2)"></span>
              </div>
            </template>
          </div>
        </div>
        <template x-for="(l,idx) in ni.lines" :key="idx">
          <div style="border:1px solid var(--border);border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;justify-content:space-between;gap:8px">
              <div class="l-nm" x-text="l.name"></div>
              <button class="rm" x-on:click="ni.lines.splice(idx,1)">🗑</button>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
              <div class="qty"><button x-on:click="l.qty++">+</button><span class="q tnum" x-text="l.qty"></span><button x-on:click="if(l.qty>1)l.qty--">−</button></div>
              <input class="expi tnum" style="width:90px" type="number" min="0" step="0.01" x-model.number="l.unit_price" title="{{ __('السعر') }}">
              <select class="expi" style="flex:1" x-model="l.condition">
                <option value="sellable">{{ __('صالح للبيع') }}</option>
                <option value="damaged">{{ __('تالف (لا يعود)') }}</option>
              </select>
              <div class="tnum" style="font-weight:800;min-width:70px;text-align:end" x-text="money(l.qty*l.unit_price)"></div>
            </div>
          </div>
        </template>
        <input class="expi" x-model="ni.note" placeholder="{{ __('ملاحظات (اختياري)') }}" maxlength="255">
        <div class="change"><span>{{ __('إجمالي الاسترداد') }}</span><b class="tnum" x-text="money(niRefund)"></b></div>
      </div>

      <div class="r-actions">
        <button class="r-print" x-on:click="showReturn=false">{{ __('إلغاء') }}</button>
        <button x-show="retTab==='invoice'" class="r-new" :disabled="busy || !ret.order" x-on:click="submitReturn()">{{ __('تأكيد الإرجاع') }}</button>
        <button x-show="retTab==='noinvoice'" class="r-new" :disabled="busy || !ni.lines.length" x-on:click="submitNoInvoice()">{{ __('تأكيد الإرجاع') }}</button>
      </div>
    </div>
  </div>

  {{-- نافذة المصروف اليومي --}}
  <div class="overlay" x-show="showExpense" x-cloak style="display:none" :style="showExpense && 'display:flex'">
    <div class="receipt" style="width:360px">
      <div class="r-head" style="background:var(--chrome)"><div class="ok">🧾</div><b>{{ __('مصروف يومي') }}</b><span>{{ __('سحب نقدي من الدرج') }}</span></div>
      <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
        <div>
          <label class="expl">{{ __('سلفة لموظف؟ (اختياري)') }}</label>
          <select x-model="exp.employee_id" class="expi">
            <option value="">{{ __('— مصروف عادي —') }}</option>
            @foreach ($employees as $emp)<option value="{{ $emp->id }}">{{ $emp->name }}</option>@endforeach
          </select>
        </div>
        <div x-show="!exp.employee_id">
          <label class="expl">{{ __('نوع المصروف') }}</label>
          <select x-model="exp.category" class="expi">
            @foreach ($expenseCategories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
          </select>
        </div>
        <div x-show="exp.employee_id" x-cloak style="font-size:12px;color:var(--muted)">{{ __('سيُخصم المبلغ كسلفة من راتب الموظف ويظهر في كشف حسابه.') }}</div>
        <div><label class="expl">{{ __('المبلغ (₪)') }}</label><input type="number" min="0" step="0.01" x-model.number="exp.amount" class="expi tnum"></div>
        <div><label class="expl">{{ __('ملاحظات') }}</label><input type="text" x-model="exp.note" maxlength="255" class="expi"></div>
      </div>
      <div class="r-actions">
        <button class="r-print" x-on:click="showExpense=false">{{ __('إلغاء') }}</button>
        <button class="r-new" :disabled="busy" x-on:click="submitExpense()">{{ __('حفظ المصروف') }}</button>
      </div>
    </div>
  </div>

  <div class="toast" x-show="toastMsg" x-transition x-text="toastMsg" style="display:none"></div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('pos', () => ({
    products: @json($products),
    categories: @json($categories),
    tickets: [], active: 0, seqTicket: 0,
    q: '', barcodeInput: '', cat: null, busy: false,
    showExpense: false, exp: { category: '', amount: null, note: '' },
    expenseCategories: @json($expenseCategories),
    employees: @json($employees),
    showReturn: false, retTab: 'invoice',
    ret: { number: '', order: null, items: [], note: '' },
    ni: { q: '', results: [], lines: [], note: '' },
    defaultCustomerName: @json($defaultCustomer),
    showCustomer: false, cust: { q: '', results: [] },
    receipt: null, toastMsg: '',
    sel: { open:false, product:null, colorVal:null },
    urls: { products: '{{ route('admin.pos.products') }}', barcode: '{{ route('admin.pos.barcode') }}', sell: '{{ route('admin.pos.sell') }}', receiptBase: '{{ url('admin/pos/receipt') }}', expense: '{{ route('admin.pos.shift.expense') }}', returnLookup: '{{ route('admin.pos.return.lookup') }}', returnPost: '{{ route('admin.pos.return') }}', returnNoInvoice: '{{ route('admin.pos.return.no_invoice') }}', customers: '{{ route('admin.pos.customers') }}' },
    csrf: document.querySelector('meta[name=csrf-token]').content,

    // فواتير متعددة (تبويبات): كل فاتورة تحتفظ بسلتها وعميلها ودفعها ضمن نفس الوردية.
    init(){ this.addTicket(); },
    blankTicket(){ return { id: ++this.seqTicket, cart: [], discount: 0, tendered: null, method: 'cash', customerId: null, custName: this.defaultCustomerName }; },
    addTicket(){ this.tickets.push(this.blankTicket()); this.active = this.tickets.length - 1; },
    closeTicket(i){
      const tk = this.tickets[i];
      if(this.tickets.length>1 && tk && tk.cart.length && !confirm('{{ __('إغلاق هذه الفاتورة وحذف أصنافها؟') }}')) return;
      this.tickets.splice(i,1);
      if(!this.tickets.length) this.addTicket();
      else if(this.active >= this.tickets.length) this.active = this.tickets.length - 1;
    },
    // منافذ (proxies) للفاتورة النشطة — تُبقي بقية الكود يعمل كأنّ الحالة مفردة.
    get t(){ return this.tickets[this.active] || { cart:[], discount:0, tendered:null, method:'cash', customerId:null, custName:this.defaultCustomerName }; },
    get cart(){ return this.t.cart; }, set cart(v){ this.t.cart = v; },
    get discount(){ return this.t.discount; }, set discount(v){ this.t.discount = v; },
    get tendered(){ return this.t.tendered; }, set tendered(v){ this.t.tendered = v; },
    get method(){ return this.t.method; }, set method(v){ this.t.method = v; },
    get customerId(){ return this.t.customerId; }, set customerId(v){ this.t.customerId = v; },
    get custName(){ return this.t.custName; }, set custName(v){ this.t.custName = v; },

    get subtotal(){ return this.cart.reduce((s,l)=>s+l.price*l.qty,0); },
    get discountVal(){ return Math.min(Math.max(parseFloat(this.discount)||0,0), this.subtotal); },
    get total(){ return Math.max(0, Math.round((this.subtotal - this.discountVal)*100)/100); },
    get change(){ return this.method==='cash' && this.tendered!=null && this.tendered!=='' ? Math.max(0, this.tendered - this.total) : 0; },
    money(n){ return (parseFloat(n)||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' ₪'; },

    toggleTheme(){ const r=document.documentElement; r.setAttribute('data-theme', r.getAttribute('data-theme')==='dark'?'light':'dark'); },
    toast(m){ this.toastMsg=m; clearTimeout(this._t); this._t=setTimeout(()=>this.toastMsg='',2800); },

    openCustomer(){ this.cust={ q:'', results:[] }; this.showCustomer=true; },
    async custSearch(){
      const url=new URL(this.urls.customers, location.origin); if(this.cust.q) url.searchParams.set('q', this.cust.q);
      const r=await fetch(url,{headers:{'Accept':'application/json'}});
      if(r.ok) this.cust.results=(await r.json()).customers;
    },
    pickCustomer(c){ this.customerId=c.id; this.custName=c.name; this.showCustomer=false; },
    resetCustomer(){ this.customerId=null; this.custName=this.defaultCustomerName; if(this.method==='credit') this.method='cash'; },

    async loadProducts(){
      const url = new URL(this.urls.products, location.origin);
      if(this.q) url.searchParams.set('q', this.q);
      if(this.cat!==null) url.searchParams.set('category', this.cat);
      const r = await fetch(url, {headers:{'Accept':'application/json'}});
      if(r.ok){ this.products = (await r.json()).products; }
    },
    setCat(id){ this.cat=id; this.loadProducts(); },

    // اختيار منتج من الشبكة: البسيط (متغيّر واحد) يُضاف مباشرة؛ متعدّد الألوان/المقاسات يفتح المحدّد.
    pick(p){
      if(p.stock<=0){ this.toast('{{ __('الصنف غير متوفّر في المخزون') }}'); return; }
      if(!p.variants || p.variants.length<=1){ this.addVariant(p.variants ? p.variants[0] : p); return; }
      this.openSelector(p);
    },
    openSelector(p){
      this.sel = { open:true, product:p, colorVal:null };
      const cvals = this.selColorValues();
      if(cvals.length){ this.sel.colorVal = (cvals.find(c=>this.colorHasStock(c.value_id)) || cvals[0]).value_id; }
    },
    selColorAxis(){ const a=this.sel.product?.axes||[]; return a.length>1 ? a[0] : null; },
    selSizeAxis(){ const a=this.sel.product?.axes||[]; if(a.length>1) return a[1]; if(a.length===1) return a[0]; return null; },
    selColorValues(){
      const ax=this.selColorAxis(); if(!ax) return [];
      const seen={}, out=[];
      for(const v of this.sel.product.variants){ const val=v.values[ax.id]; if(val && !seen[val.value_id]){ seen[val.value_id]=1; out.push(val); } }
      return out;
    },
    colorHasStock(colorValueId){
      const ax=this.selColorAxis(); if(!ax) return true;
      return this.sel.product.variants.some(v=> v.values[ax.id] && v.values[ax.id].value_id===colorValueId && v.stock>0);
    },
    selCells(){
      const p=this.sel.product; if(!p) return [];
      const colorAx=this.selColorAxis(), sizeAx=this.selSizeAxis();
      let vs=p.variants;
      if(colorAx && this.sel.colorVal!=null){ vs=vs.filter(v=> v.values[colorAx.id] && v.values[colorAx.id].value_id===this.sel.colorVal); }
      return vs.map(v=>({ ...v, cellLabel: sizeAx && v.values[sizeAx.id] ? v.values[sizeAx.id].label : v.name }));
    },
    addVariant(v){
      if(!v) return;
      if(v.stock<=0){ this.toast('{{ __('الصنف غير متوفّر في المخزون') }}'); return; }
      const ex=this.cart.find(x=>x.variant_id===v.variant_id);
      if(ex) ex.qty++; else this.cart.push({variant_id:v.variant_id,name:v.name,price:v.price,qty:1});
    },
    inc(i){ this.cart[i].qty++; },
    dec(i){ if(--this.cart[i].qty<=0) this.cart.splice(i,1); },
    remove(i){ this.cart.splice(i,1); },
    clearCart(){ this.cart=[]; this.discount=0; this.tendered=null; },

    async scan(){
      const code=this.barcodeInput.trim(); if(!code) return;
      const url=new URL(this.urls.barcode, location.origin); url.searchParams.set('code', code);
      const r=await fetch(url,{headers:{'Accept':'application/json'}});
      if(r.ok){ this.addVariant((await r.json()).product); this.barcodeInput=''; }
      else { this.toast('{{ __('لا يوجد منتج بهذا الباركود') }}'); this.barcodeInput=''; }
    },

    async pay(){
      if(!this.cart.length || this.busy) return;
      if(this.method==='credit' && !this.customerId){ this.toast('{{ __('اختر عميلًا للبيع الآجل') }}'); return; }
      this.busy=true;
      try{
        const paid = this.method==='credit' ? 0 : (this.method==='cash' ? (this.tendered||this.total) : this.total);
        const body={ items:this.cart.map(l=>({variant_id:l.variant_id,qty:l.qty,unit_price:l.price})),
          discount:this.discountVal, payment_method:this.method, paid, customer_id:this.customerId };
        const r=await fetch(this.urls.sell,{method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf}, body:JSON.stringify(body)});
        const data=await r.json();
        if(!r.ok){ this.toast(data.message || '{{ __('تعذّر إتمام البيع') }}'); return; }
        this.receipt={ ...data, lines:this.cart.map(l=>({name:l.name,qty:l.qty,price:l.price})) };
      } catch(e){ this.toast('{{ __('خطأ في الاتصال') }}'); }
      finally{ this.busy=false; }
    },
    returnMode(){ this.retTab='invoice'; this.ret={ number:'', order:null, items:[], note:'' }; this.ni={ q:'', results:[], lines:[], note:'' }; this.showReturn=true; },
    async niSearch(){
      const q=this.ni.q.trim(); if(!q){ this.ni.results=[]; return; }
      const url=new URL(this.urls.products, location.origin); url.searchParams.set('q', q);
      const r=await fetch(url,{headers:{'Accept':'application/json'}});
      if(r.ok){
        // الكتالوج يُرجع منتجات مجمّعة — نُسطّحها لمتغيّرات لاختيار مقاس/لون محدّد للإرجاع.
        const prods=(await r.json()).products;
        this.ni.results = prods.flatMap(p=>(p.variants||[]).map(v=>({variant_id:v.variant_id,name:v.name,price:v.price}))).slice(0,8);
      }
    },
    niAdd(p){ if(!this.ni.lines.find(x=>x.variant_id===p.variant_id)) this.ni.lines.push({variant_id:p.variant_id,name:p.name,qty:1,unit_price:p.price,condition:'sellable'}); this.ni.q=''; this.ni.results=[]; },
    get niRefund(){ return this.ni.lines.reduce((s,l)=>s+(l.qty>0?l.qty*l.unit_price:0),0); },
    async submitNoInvoice(){
      if(this.busy || !this.ni.lines.length) return;
      this.busy=true;
      try{
        const lines=this.ni.lines.map(l=>({variant_id:l.variant_id,qty:l.qty,unit_price:l.unit_price,condition:l.condition}));
        const r=await fetch(this.urls.returnNoInvoice,{method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf},
          body:JSON.stringify({lines,note:this.ni.note})});
        const d=await r.json();
        if(!r.ok){ this.toast(d.message || '{{ __('تعذّر تنفيذ الإرجاع') }}'); return; }
        this.showReturn=false;
        this.toast('{{ __('تم الإرجاع — استُردّ') }} '+this.money(d.refund));
        this.loadProducts();
      } catch(e){ this.toast('{{ __('خطأ في الاتصال') }}'); }
      finally{ this.busy=false; }
    },
    async lookupReturn(){
      const n=this.ret.number.trim(); if(!n) return;
      const url=new URL(this.urls.returnLookup, location.origin); url.searchParams.set('number', n);
      const r=await fetch(url,{headers:{'Accept':'application/json'}});
      const d=await r.json();
      if(!r.ok){ this.toast(d.message || '{{ __('فاتورة غير موجودة') }}'); return; }
      this.ret.order=d.order;
      this.ret.items=d.items.map(i=>({ ...i, qty:0, condition:'sellable' }));
    },
    get retRefund(){ return this.ret.items.reduce((s,i)=>s+(i.qty>0?i.qty*i.unit_price:0),0); },
    async submitReturn(){
      if(this.busy || !this.ret.order) return;
      const lines=this.ret.items.filter(i=>i.qty>0).map(i=>({order_item_id:i.order_item_id,qty:i.qty,condition:i.condition}));
      if(!lines.length){ this.toast('{{ __('حدّد كمية صنف واحد على الأقل') }}'); return; }
      this.busy=true;
      try{
        const r=await fetch(this.urls.returnPost,{method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf},
          body:JSON.stringify({order_number:this.ret.order.number,lines,note:this.ret.note})});
        const d=await r.json();
        if(!r.ok){ this.toast(d.message || '{{ __('تعذّر تنفيذ الإرجاع') }}'); return; }
        this.showReturn=false;
        this.toast('{{ __('تم الإرجاع — استُردّ') }} '+this.money(d.refund));
        this.loadProducts();
      } catch(e){ this.toast('{{ __('خطأ في الاتصال') }}'); }
      finally{ this.busy=false; }
    },
    openExpense(){ this.exp={ category:(this.expenseCategories[0]||''), amount:null, note:'', employee_id:'' }; this.showExpense=true; },
    async submitExpense(){
      if(this.busy) return;
      if(!this.exp.amount || this.exp.amount<=0){ this.toast('{{ __('أدخل مبلغ المصروف') }}'); return; }
      this.busy=true;
      try{
        const r=await fetch(this.urls.expense,{method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf},
          body:JSON.stringify({category:this.exp.category,amount:this.exp.amount,note:this.exp.note,employee_id:this.exp.employee_id||null})});
        const data=await r.json();
        if(!r.ok){ this.toast(data.message || '{{ __('تعذّر حفظ المصروف') }}'); return; }
        this.showExpense=false;
        this.toast('{{ __('تم تسجيل المصروف') }}');
      } catch(e){ this.toast('{{ __('خطأ في الاتصال') }}'); }
      finally{ this.busy=false; }
    },
    printReceipt(){ if(this.receipt?.uuid) window.open(this.urls.receiptBase+'/'+this.receipt.uuid+'?print=1','_blank'); },
    newSale(){
      this.receipt=null;
      // الفاتورة المدفوعة انتهت: أغلقها وانتقل لغيرها، أو استبدلها بفاتورة جديدة إن كانت الوحيدة.
      if(this.tickets.length>1){ this.tickets.splice(this.active,1); if(this.active>=this.tickets.length) this.active=this.tickets.length-1; }
      else { this.tickets.splice(0,1,this.blankTicket()); this.active=0; }
      this.loadProducts();
    },
  }));
});
</script>
@endpush
</x-pos-layout>
