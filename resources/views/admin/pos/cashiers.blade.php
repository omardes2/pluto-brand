@php $t = $data['totals']; $cur = '₪'; $m = fn ($n) => number_format((float) $n, 2).' '.$cur; @endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('مبيعات الكاشيرين') }}</h2></x-slot>

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
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                @foreach ([
                    ['الفواتير', $t['orders'], 'text-gray-800', false],
                    ['إجمالي المبيعات', $t['total'], 'text-gray-800', true],
                    ['نقدي', $t['cash'], 'text-emerald-700', true],
                    ['بطاقة', $t['card'], 'text-sky-700', true],
                    ['الذمم (آجل)', $t['credit'], 'text-amber-700', true],
                ] as [$label, $val, $color, $money])
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <div class="text-xs font-semibold text-gray-500 mb-1">{{ __($label) }}</div>
                        <div class="text-lg font-extrabold tabular-nums {{ $color }}">
                            {{ $money ? $m($val) : number_format((float) $val, 0) }}
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('الكاشير') }}</th>
                            <th class="text-end">{{ __('الفواتير') }}</th>
                            <th class="text-end">{{ __('إجمالي المبيعات') }}</th>
                            <th class="text-end">{{ __('نقدي') }}</th>
                            <th class="text-end">{{ __('بطاقة') }}</th>
                            <th class="text-end">{{ __('الذمم') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['rows'] as $r)
                            <tr class="border-b border-gray-100">
                                <td class="px-4 py-2.5 font-semibold">{{ $r['cashier'] }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums">{{ number_format((float) $r['orders'], 0) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold">{{ $m($r['total']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-emerald-700">{{ $m($r['cash']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-sky-700">{{ $m($r['card']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-amber-700">{{ $m($r['credit']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-gray-400 py-8">{{ __('لا مبيعات في هذه الفترة') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
