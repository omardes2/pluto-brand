<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ $banner->exists ? __('تعديل شريحة') : __('شريحة جديدة') }}</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}" class="space-y-5">
                @csrf
                @if ($banner->exists) @method('PUT') @endif

                {{-- صورة السلايدر (سطح المكتب) --}}
                <x-admin.field :label="__('صورة الشريحة')" name="image" :hint="__('PNG / JPG / WEBP — يُنصح 1600×600 بكسل، بحد أقصى 4 ميغابايت')">
                    <div class="flex items-center gap-4">
                        <span class="grid place-items-center w-40 h-20 rounded-lg bg-gray-100 overflow-hidden shrink-0">
                            @if ($banner->imageUrl())
                                <img src="{{ $banner->imageUrl() }}" alt="" class="w-full h-full object-cover" id="img-preview" />
                            @else
                                <svg class="w-8 h-8 text-gray-300" id="img-preview-placeholder" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                            @endif
                        </span>
                        <input type="file" name="image" accept="image/*"
                               onchange="const p=document.getElementById('img-preview')||document.getElementById('img-preview-placeholder'); if(this.files[0]){const u=URL.createObjectURL(this.files[0]); if(p.tagName==='IMG'){p.src=u}else{const img=new Image(); img.id='img-preview'; img.src=u; img.className='w-full h-full object-cover'; p.replaceWith(img);}}"
                               class="text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-emerald-700 hover:file:bg-emerald-100" />
                    </div>
                </x-admin.field>

                {{-- صورة الموبايل (اختياري) — لتفادي اقتصاص صورة سطح المكتب على الشاشات الصغيرة --}}
                <x-admin.field :label="__('صورة الموبايل (اختياري)')" name="mobile_image" :hint="__('صورة أطول تناسب الجوال (مثلاً 800×800). إن تُركت فارغة تُستخدم صورة سطح المكتب.')">
                    <div class="flex items-center gap-4">
                        <span class="grid place-items-center w-20 h-20 rounded-lg bg-gray-100 overflow-hidden shrink-0">
                            @if ($banner->mobile_image)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($banner->mobile_image) }}" alt="" class="w-full h-full object-cover" id="mimg-preview" />
                            @else
                                <svg class="w-7 h-7 text-gray-300" id="mimg-preview-placeholder" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/></svg>
                            @endif
                        </span>
                        <input type="file" name="mobile_image" accept="image/*"
                               onchange="const p=document.getElementById('mimg-preview')||document.getElementById('mimg-preview-placeholder'); if(this.files[0]){const u=URL.createObjectURL(this.files[0]); if(p.tagName==='IMG'){p.src=u}else{const img=new Image(); img.id='mimg-preview'; img.src=u; img.className='w-full h-full object-cover'; p.replaceWith(img);}}"
                               class="text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-emerald-700 hover:file:bg-emerald-100" />
                    </div>
                </x-admin.field>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-admin.field :label="__('العنوان (عربي)')" name="title">
                        <input type="text" name="title" value="{{ old('title', $banner->title) }}"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('العنوان (إنجليزي)')" name="title_en">
                        <input type="text" name="title_en" value="{{ old('title_en', $banner->title_en) }}" dir="ltr"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الوصف (عربي)')" name="subtitle">
                        <input type="text" name="subtitle" value="{{ old('subtitle', $banner->subtitle) }}"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('الوصف (إنجليزي)')" name="subtitle_en">
                        <input type="text" name="subtitle_en" value="{{ old('subtitle_en', $banner->subtitle_en) }}" dir="ltr"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-admin.field :label="__('نص الزر (عربي)')" name="button_label">
                        <input type="text" name="button_label" value="{{ old('button_label', $banner->button_label) }}"
                            placeholder="{{ __('تسوّق الآن') }}"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('نص الزر (إنجليزي)')" name="button_label_en">
                        <input type="text" name="button_label_en" value="{{ old('button_label_en', $banner->button_label_en) }}" dir="ltr"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('رابط الزر')" name="button_url" :hint="__('مثال: /shop أو /c/رجالي')">
                        <input type="text" name="button_url" value="{{ old('button_url', $banner->button_url) }}" dir="ltr"
                            placeholder="/shop"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                    <x-admin.field :label="__('الترتيب')" name="sort_order" :hint="__('الأصغر يظهر أولًا')">
                        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $banner->sort_order ?? 0) }}"
                            class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>

                    <label class="flex items-center gap-2 text-sm pb-2">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active ?? true)) class="rounded border-gray-300 text-emerald-600" />
                        {{ __('مفعّلة (تظهر في السلايدر)') }}
                    </label>
                </div>

                <div class="flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.banners.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
