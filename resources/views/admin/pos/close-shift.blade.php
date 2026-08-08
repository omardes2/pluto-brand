<x-pos-layout :title="__('إغلاق وردية')">
<div class="min-h-screen flex items-center justify-center p-4" style="background:#eef2f0">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-sm p-8"
       x-data="{ expected: {{ (float) $expected }}, counted: {{ (float) $expected }},
                 get variance(){ return Math.round((this.counted - this.expected)*100)/100; } }">
    <div class="mb-6">
      <div class="font-extrabold text-lg text-gray-800">{{ __('إغلاق الوردية') }} {{ $shift->number }}</div>
      <div class="text-xs text-gray-500 font-semibold">{{ __('الكاشير') }}: {{ auth()->user()->name }}</div>
    </div>

    @if ($errors->any())
      <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-2 gap-3 mb-5 text-sm">
      <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
        <div class="text-gray-500 mb-1">{{ __('الرصيد الافتتاحي') }}</div>
        <div class="font-extrabold tabular-nums">{{ number_format((float) $shift->opening_float, 2) }} ₪</div>
      </div>
      <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
        <div class="text-gray-500 mb-1">{{ __('مبيعات نقدية') }}</div>
        <div class="font-extrabold tabular-nums">{{ number_format((float) $shift->cash_sales, 2) }} ₪</div>
      </div>
      <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
        <div class="text-gray-500 mb-1">{{ __('مبيعات بطاقة') }}</div>
        <div class="font-extrabold tabular-nums">{{ number_format((float) $shift->card_sales, 2) }} ₪</div>
      </div>
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3">
        <div class="text-emerald-700 mb-1">{{ __('النقد المتوقّع في الدرج') }}</div>
        <div class="font-extrabold tabular-nums text-emerald-700">{{ number_format((float) $expected, 2) }} ₪</div>
      </div>
    </div>

    <form method="POST" action="{{ route('admin.pos.shift.close') }}" class="space-y-4">
      @csrf
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('النقد المعدود فعليًا (₪)') }}</label>
        <input type="number" name="counted_cash" min="0" step="0.01" required x-model.number="counted"
               class="w-full h-12 text-center text-lg font-extrabold rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 tabular-nums">
      </div>
      <div class="flex justify-between items-center rounded-lg px-4 py-3 text-sm font-bold"
           :class="variance===0 ? 'bg-emerald-50 text-emerald-700' : (variance>0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700')">
        <span x-text="variance===0 ? '{{ __('مطابق') }}' : (variance>0 ? '{{ __('فائض') }}' : '{{ __('عجز') }}')"></span>
        <span class="tabular-nums" x-text="variance.toFixed(2)+' ₪'"></span>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('ملاحظات (اختياري)') }}</label>
        <input type="text" name="notes" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
      </div>
      <div class="flex gap-3">
        <a href="{{ route('admin.pos.screen') }}" class="h-12 px-5 grid place-items-center rounded-xl bg-gray-100 text-gray-600 font-bold text-sm">{{ __('رجوع') }}</a>
        <button type="submit" class="flex-1 h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold transition">{{ __('إغلاق الوردية') }}</button>
      </div>
    </form>
  </div>
</div>
</x-pos-layout>
