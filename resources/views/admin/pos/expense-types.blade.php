<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('أنواع المصروفات') }}</h2>
            <a href="{{ route('admin.pos.expenses') }}" class="text-sm text-gray-500 hover:underline">{{ __('← كشف المصروفات') }}</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6" x-data="{ types: @js($types), nu: '' }">
            <x-admin.flash />
            <p class="text-sm text-gray-500 mb-4">{{ __('هذه الأنواع تظهر للكاشير عند إدخال مصروف. أضِف أو احذف كما تريد ثم احفظ.') }}</p>

            <form method="POST" action="{{ route('admin.pos.expense_types.save') }}">
                @csrf
                <input type="hidden" name="types" :value="types.join(',')">

                <div class="flex gap-2 mb-5">
                    <input x-model="nu" x-on:keydown.enter.prevent="if(nu.trim() && !types.includes(nu.trim())){types.push(nu.trim())}; nu=''"
                           placeholder="{{ __('أضف نوع مصروف…') }}"
                           class="flex-1 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <button type="button" x-on:click="if(nu.trim() && !types.includes(nu.trim())){types.push(nu.trim())}; nu=''"
                            class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('إضافة') }}</button>
                </div>

                <div class="flex flex-wrap gap-2 mb-6 min-h-[40px]">
                    <template x-for="(t,i) in types" :key="i">
                        <span class="inline-flex items-center gap-2 bg-gray-100 border border-gray-200 rounded-full px-3 py-1.5 text-sm">
                            <span x-text="t"></span>
                            <button type="button" x-on:click="types.splice(i,1)" class="text-gray-400 hover:text-red-600 font-bold leading-none">✕</button>
                        </span>
                    </template>
                    <span x-show="!types.length" class="text-gray-400 text-sm">{{ __('لا أنواع بعد') }}</span>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-emerald-600 text-white font-bold text-sm rounded-lg hover:bg-emerald-700">{{ __('حفظ') }}</button>
            </form>
        </div>
    </div>
</x-app-layout>
