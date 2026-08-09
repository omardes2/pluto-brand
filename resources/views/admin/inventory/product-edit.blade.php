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

            @if ($hasVariants ?? false)
                <div class="text-sm text-sky-800 bg-sky-50 border border-sky-200 rounded-lg p-3 flex items-start gap-2">
                    <svg class="w-5 h-5 shrink-0 text-sky-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                    <span>
                        {{ __('هذا الصنف له مقاسات/ألوان — عدّل كمية كل مقاس ولون من الجدول أدناه.') }}
                        <a href="{{ route('admin.products.edit', $product) }}" class="font-semibold underline hover:no-underline">{{ __('أو من كرت الصنف') }}</a>.
                    </span>
                </div>
            @endif

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
                @if ($hasVariants ?? false)
                    {{-- صنف بمقاسات/ألوان: عرض الإجمالي (للقراءة) — التعديل من جدول الكميات أدناه. --}}
                    <x-admin.field :label="__('إجمالي الكمية المتوفرة')" name="quantity_total">
                        <input type="number" value="{{ rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.') }}"
                               readonly disabled
                               class="w-full rounded-lg border-gray-200 bg-gray-50 text-gray-500 cursor-not-allowed" />
                    </x-admin.field>
                @else
                    <x-admin.field :label="__('الكمية المتوفرة')" name="quantity">
                        <input type="number" step="0.01" min="0" name="quantity" value="{{ old('quantity', rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.')) }}"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                @endif
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

            {{-- كميّات المقاسات/الألوان — قابلة للتعديل مباشرة --}}
            @php($variantRows = ($hasVariants ?? false) ? ($variants ?? collect()) : collect())
            @if ($variantRows->isNotEmpty())
                <div class="border-t border-gray-100 pt-5">
                    <h4 class="text-sm font-bold text-gray-800 mb-1">{{ __('كميّات المقاسات والألوان') }}</h4>
                    <p class="text-xs text-gray-400 mb-3">{{ __('عدّل كمية كل مقاس/لون. تُسجَّل التغييرات في سجل المخزن.') }}</p>
                    <div class="overflow-x-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="py-2 px-3 text-start font-medium">{{ __('المقاس / اللون') }}</th>
                                    <th class="py-2 px-3 text-start font-medium">{{ __('الباركود') }}</th>
                                    <th class="py-2 px-3 text-start font-medium w-32">{{ __('الكمية') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($variantRows as $v)
                                    @php($label = $v->optionLabel() ?: ($v->sku ?: __('أساسي')))
                                    <tr>
                                        <td class="py-2 px-3 text-gray-800">{{ $label }}</td>
                                        <td class="py-2 px-3 text-gray-400 tabular-nums">{{ $v->barcode ?: '—' }}</td>
                                        <td class="py-2 px-3">
                                            <input type="number" step="0.01" min="0"
                                                   name="variant_qty[{{ $v->id }}]"
                                                   value="{{ old('variant_qty.'.$v->id, rtrim(rtrim(number_format((float) ($stockByVariant[$v->id] ?? 0), 2, '.', ''), '0'), '.')) }}"
                                                   class="w-28 rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 tabular-nums" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-2">
                <a href="{{ route('admin.inventory.stocks') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
                <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
