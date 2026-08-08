<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزن') }} — {{ __('تعديل صنف') }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-admin.flash />

        <a href="{{ route('admin.inventory.stocks') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            {{ __('عودة للمخزن') }}
        </a>

        <form method="POST" action="{{ route('admin.inventory.products.update', $product) }}"
              class="bg-white shadow-sm sm:rounded-lg p-6 space-y-5">
            @csrf @method('PUT')

            <div>
                <h3 class="text-lg font-bold text-gray-900">{{ $product->name }}</h3>
                <p class="text-sm text-gray-400">{{ __('تعديل بيانات الصنف الأساسية والأسعار والكمية.') }}</p>
            </div>

            <x-admin.field :label="__('اسم المنتج')" name="name" required>
                <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-admin.field :label="__('سعر الشراء').' ('.$currency.')'" name="cost_price">
                    <input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price', $product->cost_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('سعر الجملة').' ('.$currency.')'" name="wholesale_price">
                    <input type="number" step="0.01" min="0" name="wholesale_price" value="{{ old('wholesale_price', $product->wholesale_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('سعر البيع').' ('.$currency.')'" name="retail_price">
                    <input type="number" step="0.01" min="0" name="retail_price" value="{{ old('retail_price', $product->retail_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-admin.field :label="__('الكمية المتوفرة')" name="quantity">
                    <input type="number" step="0.01" min="0" name="quantity" value="{{ old('quantity', rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.')) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('الفئة')" name="category_id">
                    <select name="category_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('— بدون فئة —') }}</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id) == $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field :label="__('باركود المنتج')" name="barcode">
                    <input type="text" name="barcode" value="{{ old('barcode', $variant?->barcode) }}" inputmode="numeric"
                           placeholder="{{ __('مثال: 26000008') }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 tabular-nums" />
                </x-admin.field>
            </div>

            @unless ($variant)
                <p class="text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-lg p-3">{{ __('لا يوجد متغيّر افتراضي لهذا الصنف — لن تُحفَظ الكمية والباركود.') }}</p>
            @endunless

            <div class="flex items-center justify-end gap-2 pt-2">
                <a href="{{ route('admin.inventory.stocks') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
                <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
