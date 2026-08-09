<x-app-layout :title="__('استيراد المنتجات')">
    <x-admin.header
        :title="__('استيراد المنتجات من Excel/CSV')"
        :description="__('ارفع ملفًا لإضافة أو تحديث عدّة منتجات دفعة واحدة.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), __('استيراد') => null]">
        <a href="{{ route('admin.products.index') }}" class="btn-secondary btn-sm">{{ __('رجوع') }}</a>
    </x-admin.header>

    <x-admin.flash />

    <div class="py-6 max-w-3xl mx-auto space-y-6">
        {{-- التعليمات --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-2">{{ __('طريقة الاستيراد') }}</h3>
            <ol class="list-decimal ps-5 text-sm text-gray-600 space-y-1">
                <li>{{ __('في Excel: احفظ الملف باسم «CSV UTF-8 (محدّد بفواصل)».') }}</li>
                <li>{{ __('يجب أن يحتوي الصف الأول على عناوين الأعمدة التالية (أي ترتيب):') }}</li>
            </ol>
            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                @foreach (['اسم الصنف', 'سعر البيع', 'الكمية', 'سعر الشراء', 'الباركود', 'التصنيف'] as $col)
                    <span class="inline-block rounded bg-gray-100 text-gray-700 px-2 py-1 font-medium">{{ $col }}</span>
                @endforeach
            </div>
            <ul class="list-disc ps-5 text-sm text-gray-500 mt-3 space-y-1">
                <li>{{ __('«اسم الصنف» إلزامي. الصفوف بلا اسم تُتجاوَز.') }}</li>
                <li>{{ __('«التصنيف» يُنشأ تلقائيًا إن لم يكن موجودًا. الفارغ يُصنّف «غير مصنّف».') }}</li>
                <li>{{ __('إن تكرّر «الباركود» لمنتج موجود، يُحدَّث سعره وتكلفته ومخزونه بدل إنشاء منتج جديد.') }}</li>
                <li>{{ __('«الكمية» تضبط المخزون على المستودع الافتراضي. رمز المنتج (SKU) يُولَّد تلقائيًا.') }}</li>
            </ul>
            <a href="{{ route('admin.products.import.template') }}" class="inline-block mt-4 text-sm text-emerald-600 hover:underline">{{ __('تحميل قالب CSV جاهز') }}</a>
        </div>

        {{-- نموذج الرفع --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="POST" action="{{ route('admin.products.import') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <x-admin.field :label="__('ملف CSV')" name="file">
                    <input type="file" name="file" accept=".csv,text/csv,text/plain" required
                           class="w-full rounded-md border-gray-300 text-sm file:me-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-gray-700" />
                </x-admin.field>
                <button class="btn-primary btn-sm">{{ __('استيراد') }}</button>
            </form>
        </div>

        {{-- نتائج آخر استيراد --}}
        @if (session('import_summary'))
            @php $s = session('import_summary'); @endphp
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('نتيجة الاستيراد') }}</h3>
                <div class="grid grid-cols-3 gap-3 text-center mb-4">
                    <div class="rounded-lg bg-emerald-50 p-3"><div class="text-2xl font-bold text-emerald-700">{{ $s['created'] }}</div><div class="text-xs text-gray-500">{{ __('جديد') }}</div></div>
                    <div class="rounded-lg bg-blue-50 p-3"><div class="text-2xl font-bold text-blue-700">{{ $s['updated'] }}</div><div class="text-xs text-gray-500">{{ __('محدّث') }}</div></div>
                    <div class="rounded-lg bg-gray-50 p-3"><div class="text-2xl font-bold text-gray-600">{{ $s['skipped'] }}</div><div class="text-xs text-gray-500">{{ __('متجاوَز') }}</div></div>
                </div>
                @if (! empty($s['errors']))
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                        <p class="text-sm font-medium text-rose-700 mb-1">{{ __('صفوف بها أخطاء (:count):', ['count' => count($s['errors'])]) }}</p>
                        <ul class="text-xs text-rose-600 space-y-0.5 max-h-40 overflow-y-auto">
                            @foreach ($s['errors'] as $err)
                                <li>{{ __('السطر') }} {{ $err['row'] }}: {{ $err['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
