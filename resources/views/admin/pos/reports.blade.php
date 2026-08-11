@php $t = $summary['totals']; $cur = '₪'; $m = fn ($n) => number_format((float) $n, 2).' '.$cur; @endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('تقارير نقطة البيع') }}</h2></x-slot>

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

            {{-- بطاقات الإجماليات --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                @foreach ([
                    ['إجمالي المبيعات', $t['total_sales'], 'text-gray-800'],
                    ['المرتجعات', $t['refunds'] ?? 0, 'text-red-700'],
                    ['صافي المبيعات', $t['net_sales'] ?? $t['total_sales'], 'text-emerald-700'],
                    ['المدفوعات النقدية', $t['cash'], 'text-emerald-700'],
                    ['المدفوعات بالبطاقة', $t['card'], 'text-sky-700'],
                    ['الذمم (آجل)', $t['credit'], 'text-amber-700'],
                    ['المصروفات', $t['expenses'], 'text-red-700'],
                    ['الفواتير', $t['orders'], 'text-gray-800'],
                    ['الرصيد النهائي (صافي النقد)', $t['net'], 'text-emerald-700'],
                ] as [$label, $val, $color])
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <div class="text-xs font-semibold text-gray-500 mb-1">{{ __($label) }}</div>
                        <div class="text-lg font-extrabold tabular-nums {{ $color }}">
                            {{ $label === 'الفواتير' ? number_format((float) $val, 0) : $m($val) }}
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- الأرشيف اليومي --}}
            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('التاريخ') }}</th>
                            <th class="text-end">{{ __('الفواتير') }}</th>
                            <th class="text-end">{{ __('إجمالي المبيعات') }}</th>
                            <th class="text-end">{{ __('المرتجعات') }}</th>
                            <th class="text-end">{{ __('صافي المبيعات') }}</th>
                            <th class="text-end">{{ __('نقدي') }}</th>
                            <th class="text-end">{{ __('بطاقة') }}</th>
                            <th class="text-end">{{ __('الذمم') }}</th>
                            <th class="text-end">{{ __('المصروفات') }}</th>
                            <th class="text-end">{{ __('الرصيد النهائي') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($summary['days'] as $day)
                            <tr class="border-b border-gray-100">
                                <td class="px-4 py-2.5 font-semibold tabular-nums">{{ $day['date'] }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums">{{ number_format((float) $day['orders'], 0) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold">{{ $m($day['total_sales']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-red-700">{{ ($day['refunds'] ?? 0) > 0 ? '− '.$m($day['refunds']) : '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold text-emerald-700">{{ $m($day['net_sales'] ?? $day['total_sales']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-emerald-700">{{ $m($day['cash']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-sky-700">{{ $m($day['card']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-amber-700">{{ $m($day['credit']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-red-700">{{ $m($day['expenses']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold">{{ $m($day['net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-gray-400 py-8">{{ __('لا توجد بيانات في هذه الفترة') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
