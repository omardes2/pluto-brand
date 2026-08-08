@php $cur = '₪'; $m = fn ($n) => number_format((float) $n, 2).' '.$cur; @endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('كشف المصروفات') }}</h2></x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
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
                <a href="{{ route('admin.pos.expense_types') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('⚙ إدارة أنواع المصروفات') }}</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
                <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                    <div class="text-xs font-semibold text-red-700 mb-1">{{ __('إجمالي المصروفات') }}</div>
                    <div class="text-2xl font-extrabold tabular-nums text-red-700">{{ $m($data['total']) }}</div>
                </div>
                <div class="md:col-span-2 rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="text-xs font-semibold text-gray-500 mb-2">{{ __('حسب النوع') }}</div>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($data['byType'] as $type => $amount)
                            <span class="inline-flex items-center gap-2 bg-white border border-gray-200 rounded-full px-3 py-1 text-sm">
                                <b>{{ $type }}</b><span class="tabular-nums text-red-700">{{ $m($amount) }}</span>
                            </span>
                        @empty
                            <span class="text-gray-400 text-sm">{{ __('لا مصروفات') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('التاريخ') }}</th>
                            <th class="text-start">{{ __('النوع') }}</th>
                            <th class="text-end">{{ __('المبلغ') }}</th>
                            <th class="text-start">{{ __('ملاحظة') }}</th>
                            <th class="text-start">{{ __('الكاشير') }}</th>
                            <th class="text-start">{{ __('الوردية') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['rows'] as $r)
                            <tr class="border-b border-gray-100">
                                <td class="px-4 py-2.5 tabular-nums text-gray-500">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2.5 font-semibold">{{ $r->category ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-red-700 font-bold">{{ $m($r->amount) }}</td>
                                <td class="px-4 py-2.5 text-gray-500">{{ $r->note ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $r->createdBy?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-gray-500">{{ $r->shift?->number ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-gray-400 py-8">{{ __('لا مصروفات في هذه الفترة') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
