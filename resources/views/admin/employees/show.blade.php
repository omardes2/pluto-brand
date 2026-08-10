@php
    use App\Modules\Hr\Models\EmployeeLedgerEntry;
    $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
@endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('كشف حساب الموظف') }} — {{ $employee->name }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        {{-- بطاقات الرصيد --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-white shadow-sm rounded-lg p-4">
                <div class="text-xs text-gray-500 mb-1">{{ __('الراتب الشهري') }}</div>
                <div class="text-lg font-bold text-gray-800 tabular-nums">{{ $money($employee->monthly_salary) }}</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-4">
                <div class="text-xs text-gray-500 mb-1">{{ __('إجمالي السلف') }}</div>
                <div class="text-lg font-bold text-amber-600 tabular-nums">{{ $money($summary['advances']) }}</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-4">
                <div class="text-xs text-gray-500 mb-1">{{ __('إجمالي المشتريات') }}</div>
                <div class="text-lg font-bold text-amber-600 tabular-nums">{{ $money($summary['purchases']) }}</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-4 {{ $summary['debt'] > 0 ? 'ring-2 ring-rose-200' : '' }}">
                <div class="text-xs text-gray-500 mb-1">{{ $summary['debt'] > 0 ? __('دين عليه') : __('رصيد له من راتبه') }}</div>
                <div class="text-lg font-bold tabular-nums {{ $summary['debt'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                    {{ $money($summary['debt'] > 0 ? $summary['debt'] : $summary['due_to_employee']) }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- تسجيل حركة يدوية --}}
            @can('hr.employees.ledger')
                <div class="bg-white shadow-sm rounded-lg p-5 lg:col-span-1 h-fit">
                    <h3 class="font-semibold text-gray-800 mb-3">{{ __('تسجيل حركة') }}</h3>
                    <form method="POST" action="{{ route('admin.employees.entries.store', $employee) }}" class="space-y-3" x-data="{ type: 'advance' }">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('النوع') }}</label>
                            <select name="type" x-model="type" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="advance">{{ __('سلفة') }}</option>
                                <option value="salary_payment">{{ __('دفع راتب') }}</option>
                                <option value="salary_accrual">{{ __('استحقاق راتب') }}</option>
                                <option value="adjustment">{{ __('تسوية') }}</option>
                            </select>
                        </div>
                        <div x-show="type === 'adjustment'" x-cloak>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('اتجاه التسوية') }}</label>
                            <select name="direction" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="debit">{{ __('خصم عليه (−)') }}</option>
                                <option value="credit">{{ __('إضافة له (+)') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('المبلغ') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('التاريخ') }}</label>
                            <input type="date" name="entry_date" value="{{ now()->toDateString() }}" class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('ملاحظة') }}</label>
                            <input type="text" name="note" maxlength="500" class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <button class="w-full px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تسجيل') }}</button>
                    </form>
                </div>
            @endcan

            {{-- سجل الحركات --}}
            <div class="bg-white shadow-sm rounded-lg p-5 lg:col-span-2">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('سجل الحركات') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('المبلغ') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('ملاحظة') }}</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @forelse ($entries as $entry)
                                @php $positive = (float) $entry->amount >= 0; @endphp
                                <tr>
                                    <td class="py-2 px-3 text-gray-500 tabular-nums">{{ $entry->entry_date->format('Y-m-d') }}</td>
                                    <td class="py-2 px-3 text-gray-700">{{ $entry->typeLabel() }}</td>
                                    <td class="py-2 px-3 font-semibold tabular-nums {{ $positive ? 'text-emerald-600' : 'text-rose-600' }}">
                                        {{ $positive ? '+' : '−' }}{{ $money(abs((float) $entry->amount)) }}
                                    </td>
                                    <td class="py-2 px-3 text-gray-500">{{ $entry->note ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا توجد حركات بعد.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $entries->links() }}</div>
            </div>
        </div>

        <div><a href="{{ route('admin.employees.index') }}" class="text-sm text-gray-500 hover:underline">← {{ __('رجوع للقائمة') }}</a></div>
    </div>
</x-app-layout>
