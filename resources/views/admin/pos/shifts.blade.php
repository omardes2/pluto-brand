@php $cur = '₪'; $m = fn ($n) => number_format((float) $n, 2).' '.$cur; @endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('ورديات نقطة البيع') }}</h2></x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            <form method="GET" class="flex flex-wrap gap-3 items-end mb-6">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('من') }}</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('إلى') }}</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('عرض') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('الوردية') }}</th>
                            <th class="text-start">{{ __('الكاشير') }}</th>
                            <th class="text-start">{{ __('الفتح') }}</th>
                            <th class="text-start">{{ __('الإغلاق') }}</th>
                            <th class="text-end">{{ __('المبيعات') }}</th>
                            <th class="text-end">{{ __('المصروفات') }}</th>
                            <th class="text-end">{{ __('الفرق') }}</th>
                            <th class="text-center">{{ __('الحالة') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shifts as $s)
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="px-4 py-2.5 font-semibold tabular-nums">{{ $s->number }}</td>
                                <td class="px-4 py-2.5">{{ $s->cashier?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-gray-500">{{ $s->opened_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-gray-500">{{ $s->closed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold">{{ $m($s->total_sales) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-red-700">{{ $m($s->expenses ?? 0) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums {{ (float) $s->variance < 0 ? 'text-red-700' : ((float) $s->variance > 0 ? 'text-amber-700' : 'text-gray-500') }}">
                                    {{ $s->status === 'closed' ? $m($s->variance) : '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold {{ $s->status === 'open' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $s->status === 'open' ? __('مفتوحة') : __('مغلقة') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-end">
                                    <a href="{{ route('admin.pos.shifts.show', $s) }}" class="text-emerald-700 hover:underline font-semibold">{{ __('تفاصيل') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-gray-400 py-8">{{ __('لا توجد ورديات في هذه الفترة') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
