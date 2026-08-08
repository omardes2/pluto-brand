@php
    $cur = '₪'; $m = fn ($n) => number_format((float) $n, 2).' '.$cur;
    $typeLabels = [
        'opening' => __('رصيد افتتاحي'), 'cash_sale' => __('بيع نقدي'), 'card_sale' => __('بيع بطاقة'),
        'credit_sale' => __('بيع آجل (ذمم)'),
        'refund' => __('استرداد'), 'pay_in' => __('إيداع'), 'pay_out' => __('مصروف / سحب'),
    ];

    // بطاقات حركة الصندوق — تُدرَج بطاقة «المبيعات الآجلة (ذمم)» فقط إن وُجدت آجلات في الوردية.
    $cashTiles = [
        ['الرصيد الافتتاحي', $shift->opening_float, 'text-gray-800'],
        ['المبيعات النقدية', $shift->cash_sales, 'text-emerald-700'],
        ['المصروفات', $totals['expenses'], 'text-red-700'],
        ['المرتجعات النقدية', $shift->total_refunds, 'text-red-700'],
    ];
    if ((float) $shift->credit_sales > 0) {
        $cashTiles[] = ['المبيعات الآجلة (ذمم)', $shift->credit_sales, 'text-amber-700'];
    }
    $cashTiles = array_merge($cashTiles, [
        ['النقد المتوقّع', $shift->expected_cash, 'text-gray-800'],
        ['النقد المعدود', $shift->status === 'closed' ? $shift->counted_cash : null, 'text-gray-800'],
        ['الفرق (عجز/فائض)', $shift->status === 'closed' ? $shift->variance : null, (float) $shift->variance < 0 ? 'text-red-700' : 'text-amber-700'],
        ['صافي النقد (بعد خصم الافتتاحي)', $totals['net_cash'], 'text-emerald-700'],
    ]);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('تفاصيل الوردية') }} {{ $shift->number }}</h2>
            <a href="{{ route('admin.pos.shifts') }}" class="text-sm text-gray-500 hover:underline">{{ __('← كل الورديات') }}</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- رأس --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-gray-500">{{ __('الكاشير') }}</div><div class="font-bold">{{ $shift->cashier?->name ?? '—' }}</div></div>
            <div><div class="text-gray-500">{{ __('المخزن') }}</div><div class="font-bold">{{ $shift->warehouse?->name ?? '—' }}</div></div>
            <div><div class="text-gray-500">{{ __('الفتح') }}</div><div class="font-bold tabular-nums">{{ $shift->opened_at?->format('Y-m-d H:i') }}</div></div>
            <div><div class="text-gray-500">{{ __('الإغلاق') }}</div><div class="font-bold tabular-nums">{{ $shift->closed_at?->format('Y-m-d H:i') ?? __('مفتوحة') }}</div></div>
        </div>

        {{-- أرصدة الصندوق --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('حركة الصندوق النقدي') }}</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                @foreach ($cashTiles as [$label, $val, $color])
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <div class="text-xs text-gray-500 mb-1">{{ __($label) }}</div>
                        <div class="font-extrabold tabular-nums {{ $color }}">{{ is_null($val) ? '—' : $m($val) }}</div>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-3">{{ __('«صافي النقد» = النقد المتوقّع − الرصيد الافتتاحي (ما يُسلَّم فعليًا بعد إبقاء رصيد الدرج).') }}</p>
        </div>

        {{-- الأصناف المباعة والأرباح --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">{{ __('الأصناف المباعة (صافي بعد المرتجعات)') }}</h3>
                <div class="text-sm">
                    <span class="text-gray-500">{{ __('ربح الوردية') }}:</span>
                    <span class="font-extrabold tabular-nums {{ $totals['profit'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $m($totals['profit']) }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('الصنف') }}</th>
                            <th class="text-end">{{ __('الكمية') }}</th>
                            <th class="text-end">{{ __('المبيعات') }}</th>
                            <th class="text-end">{{ __('التكلفة') }}</th>
                            <th class="text-end">{{ __('الربح') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $it)
                            <tr class="border-b border-gray-100">
                                <td class="px-4 py-2.5 font-semibold">{{ $it['name'] }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums">{{ rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums">{{ $m($it['revenue']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-gray-500">{{ $m($it['cost']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-bold {{ $it['profit'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $m($it['profit']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-gray-400 py-8">{{ __('لا مبيعات في هذه الوردية') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if (count($items))
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 font-extrabold">
                                <td class="px-4 py-2.5">{{ __('الإجمالي') }}</td>
                                <td></td>
                                <td class="px-4 py-2.5 text-end tabular-nums">{{ $m($totals['revenue']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-gray-500">{{ $m($totals['cost']) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-emerald-700">{{ $m($totals['profit']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- حركات الدرج --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('حركات الدرج') }}</h3>
            <div class="overflow-x-auto">
                <table class="admin-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('النوع') }}</th>
                            <th class="text-start">{{ __('التصنيف/المرجع') }}</th>
                            <th class="text-end">{{ __('المبلغ') }}</th>
                            <th class="text-start">{{ __('ملاحظة') }}</th>
                            <th class="text-start">{{ __('الوقت') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($movements as $mv)
                            <tr class="border-b border-gray-100">
                                <td class="px-4 py-2.5">{{ $typeLabels[$mv->type] ?? $mv->type }}</td>
                                <td class="px-4 py-2.5 text-gray-500">{{ $mv->category ?? $mv->reference ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums {{ in_array($mv->type, ['refund', 'pay_out']) ? 'text-red-700' : ($mv->type === 'credit_sale' ? 'text-amber-700' : 'text-gray-800') }}">{{ $m($mv->amount) }}</td>
                                <td class="px-4 py-2.5 text-gray-500">{{ $mv->note ?? '—' }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-gray-400">{{ $mv->created_at?->format('H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
