<x-pos-layout :title="__('فتح وردية')">
<div class="min-h-screen flex items-center justify-center bg-gray-100 p-4" style="background:#eef2f0">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-sm p-8">
    <div class="flex items-center gap-3 mb-6">
      <span class="w-11 h-11 rounded-xl bg-emerald-600 text-white grid place-items-center font-extrabold text-lg">P</span>
      <div>
        <div class="font-extrabold text-lg text-gray-800">{{ config('app.name') }}</div>
        <div class="text-xs text-gray-500 font-semibold tracking-wider">{{ __('فتح وردية نقطة البيع') }}</div>
      </div>
    </div>

    @if ($errors->any())
      <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
        {{ $errors->first() }}
      </div>
    @endif
    @if (session('success'))
      <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm p-3">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.pos.shift.open') }}" class="space-y-4">
      @csrf
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('المستودع') }}</label>
        <select name="warehouse_id" required class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
          @foreach ($warehouses as $w)
            <option value="{{ $w->id }}">{{ $w->name }} ({{ $w->code }})</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('الرصيد الافتتاحي للدرج (₪)') }}</label>
        <input type="number" name="opening_float" min="0" step="0.01" value="0"
               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 tabular-nums">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('ملاحظات (اختياري)') }}</label>
        <input type="text" name="notes" maxlength="500"
               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
      </div>
      <button type="submit" class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-base transition">
        {{ __('فتح الوردية وبدء البيع') }}
      </button>
    </form>
  </div>
</div>
</x-pos-layout>
