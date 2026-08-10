<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $employee->exists ? __('تعديل موظف') : __('موظف جديد') }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $employee->exists ? route('admin.employees.update', $employee) : route('admin.employees.store') }}" class="space-y-4">
                @csrf @if ($employee->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $employee->name) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الهاتف')" name="phone"><input type="text" name="phone" value="{{ old('phone', $employee->phone) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('المسمّى الوظيفي')" name="job_title"><input type="text" name="job_title" value="{{ old('job_title', $employee->job_title) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الراتب الشهري')" name="monthly_salary"><input type="number" step="0.01" min="0" name="monthly_salary" value="{{ old('monthly_salary', $employee->exists ? $employee->monthly_salary : '0') }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('تاريخ التعيين')" name="hire_date"><input type="date" name="hire_date" value="{{ old('hire_date', optional($employee->hire_date)->format('Y-m-d')) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الفرع')" name="branch_id">
                        <select name="branch_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— بلا —') }}</option>
                            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $employee->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('ربط بحساب مستخدم (اختياري)')" name="user_id">
                        <select name="user_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— بلا حساب دخول —') }}</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) old('user_id', $employee->user_id) === $u->id)>{{ $u->name }} @if($u->email)({{ $u->email }})@endif</option>@endforeach
                        </select>
                    </x-admin.field>
                    <label class="flex items-center gap-2 text-sm pb-2 pt-6">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $employee->exists ? $employee->is_active : true)) class="rounded border-gray-300 text-emerald-600" />
                        {{ __('نشط') }}
                    </label>
                </div>

                <x-admin.field :label="__('ملاحظات')" name="notes"><textarea name="notes" rows="2" class="w-full rounded-md border-gray-300">{{ old('notes', $employee->notes) }}</textarea></x-admin.field>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.employees.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
