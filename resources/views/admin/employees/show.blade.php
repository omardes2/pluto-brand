@php
    use App\Modules\Hr\Models\EmployeeLedgerEntry;
    $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
@endphp
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('كشف حساب الموظف') }} — {{ $employee->name }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{ editing: null }">
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
                            @can('hr.employees.ledger')<th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>@endcan
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
                                    @can('hr.employees.ledger')
                                        <td class="py-2 px-3 whitespace-nowrap">
                                            <button type="button" class="text-emerald-600 hover:underline text-xs"
                                                    @click="editing = {{ \Illuminate\Support\Js::from([
                                                        'url' => route('admin.employees.entries.update', [$employee, $entry]),
                                                        'type' => $entry->type,
                                                        'amount' => number_format(abs((float) $entry->amount), 2, '.', ''),
                                                        'direction' => ((float) $entry->amount >= 0 ? 'credit' : 'debit'),
                                                        'entry_date' => $entry->entry_date->format('Y-m-d'),
                                                        'note' => $entry->note ?? '',
                                                    ]) }}">{{ __('تعديل') }}</button>
                                            <form method="POST" action="{{ route('admin.employees.entries.destroy', [$employee, $entry]) }}"
                                                  class="inline" onsubmit="return confirm('{{ __('حذف هذه الحركة؟ سيتأثّر الرصيد.') }}')">
                                                @csrf @method('DELETE')
                                                <button class="text-rose-500 hover:underline text-xs ms-2">{{ __('حذف') }}</button>
                                            </form>
                                        </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr><td colspan="{{ auth()->user()?->can('hr.employees.ledger') ? 5 : 4 }}" class="py-6 text-center text-gray-400">{{ __('لا توجد حركات بعد.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $entries->links() }}</div>
            </div>
        </div>

        {{-- نافذة تعديل حركة --}}
        @can('hr.employees.ledger')
            <div x-show="editing" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
                 @click.self="editing = null" @keydown.escape.window="editing = null">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-gray-800">{{ __('تعديل الحركة') }}</h3>
                        <button type="button" @click="editing = null" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                    </div>
                    <form method="POST" :action="editing?.url" class="space-y-3">
                        @csrf @method('PUT')
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('النوع') }}</label>
                            <select name="type" x-model="editing.type" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="advance">{{ __('سلفة') }}</option>
                                <option value="salary_payment">{{ __('دفع راتب') }}</option>
                                <option value="salary_accrual">{{ __('استحقاق راتب') }}</option>
                                <option value="adjustment">{{ __('تسوية') }}</option>
                            </select>
                        </div>
                        <div x-show="editing?.type === 'adjustment'" x-cloak>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('اتجاه التسوية') }}</label>
                            <select name="direction" x-model="editing.direction" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="debit">{{ __('خصم عليه (−)') }}</option>
                                <option value="credit">{{ __('إضافة له (+)') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('المبلغ') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" x-model="editing.amount" required class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('التاريخ') }}</label>
                            <input type="date" name="entry_date" x-model="editing.entry_date" class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">{{ __('ملاحظة') }}</label>
                            <input type="text" name="note" maxlength="500" x-model="editing.note" class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button class="flex-1 px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                            <button type="button" @click="editing = null" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('إلغاء') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

        <div><a href="{{ route('admin.employees.index') }}" class="text-sm text-gray-500 hover:underline">← {{ __('رجوع للقائمة') }}</a></div>
    </div>
</x-app-layout>
