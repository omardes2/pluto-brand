<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('الموظفون') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('الموظفون')">
                <div class="flex gap-2">
                    @can('hr.employees.ledger')
                        <form method="POST" action="{{ route('admin.employees.salaries.run') }}" onsubmit="return confirm('{{ __('احتساب راتب هذا الشهر لكل الموظفين النشطين؟') }}')">
                            @csrf
                            <button class="inline-flex px-4 py-2 bg-sky-600 text-white text-sm rounded-md hover:bg-sky-700">{{ __('احتساب رواتب الشهر') }}</button>
                        </form>
                    @endcan
                    @can('hr.employees.create')
                        <a href="{{ route('admin.employees.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('موظف جديد') }}</a>
                    @endcan
                </div>
            </x-admin.header>

            {{-- بحث وفلاتر --}}
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-4">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('اسم / هاتف / مسمّى') }}" class="rounded-md border-gray-300 text-sm sm:col-span-2" />
                <select name="status" class="rounded-md border-gray-300 text-sm">
                    <option value="">{{ __('كل الحالات') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('نشط') }}</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('غير نشط') }}</option>
                </select>
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الهاتف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المسمّى') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الراتب الشهري') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('حساب الدخول') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($employees as $e)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $e->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $e->phone ?: '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $e->job_title ?: '—' }}</td>
                                <td class="py-2 px-3 text-gray-700 tabular-nums">{{ number_format((float) $e->monthly_salary, 2) }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $e->user?->name ?: '—' }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $e->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $e->is_active ? __('نشط') : __('غير نشط') }}</span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.employees.show', $e) }}" class="text-sky-600 hover:underline">{{ __('كشف الحساب') }}</a>
                                        @can('hr.employees.update')
                                            <a href="{{ route('admin.employees.edit', $e) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                                            <form method="POST" action="{{ route('admin.employees.toggle', $e) }}">@csrf<button class="text-amber-600 hover:underline">{{ $e->is_active ? __('تعطيل') : __('تفعيل') }}</button></form>
                                        @endcan
                                        @can('hr.employees.delete')
                                            <form method="POST" action="{{ route('admin.employees.destroy', $e) }}" onsubmit="return confirm('{{ __('حذف الموظف؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا يوجد موظفون.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $employees->links() }}</div>
        </div>
    </div>
</x-app-layout>
