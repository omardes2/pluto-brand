<x-storefront.layout :page-event="['name' => 'HomeViewed', 'payload' => []]">
    @php $locale = app()->getLocale(); @endphp

    @if ($banners->isNotEmpty())
        {{-- سلايدر الصفحة الرئيسية (يُدار من اللوحة) — متجاوب على الموبايل --}}
        <section
            x-data="{
                active: 0,
                count: {{ $banners->count() }},
                timer: null,
                start() { if (this.count > 1) { this.stop(); this.timer = setInterval(() => this.next(), 6000); } },
                stop() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
                next() { this.active = (this.active + 1) % this.count; },
                prev() { this.active = (this.active - 1 + this.count) % this.count; },
                go(i) { this.active = i; },
            }"
            x-init="start()"
            @mouseenter="stop()" @mouseleave="start()"
            class="relative mb-6 overflow-hidden rounded-2xl bg-gray-900"
            role="region" aria-label="{{ __('storefront.site_name') }}"
        >
            <div class="relative h-52 sm:h-72 md:h-80 lg:h-[26rem]">
                @foreach ($banners as $i => $banner)
                    @php
                        $bTitle = $banner->titleFor($locale);
                        $bSubtitle = $banner->subtitleFor($locale);
                        $bButton = $banner->buttonLabelFor($locale);
                    @endphp
                    <div x-show="active === {{ $i }}" x-transition.opacity.duration.500ms
                         class="absolute inset-0" @if (! $loop->first) x-cloak @endif>
                        {{-- الصورة: صورة موبايل مخصّصة (أو نفس الصورة) على الجوّال، والعريضة على الأكبر --}}
                        @if ($banner->imageUrl())
                            <img src="{{ $banner->mobileImageUrl() }}" alt="{{ $bTitle ?: __('storefront.site_name') }}"
                                 loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                                 class="sm:hidden absolute inset-0 w-full h-full object-cover" />
                            <img src="{{ $banner->imageUrl() }}" alt="{{ $bTitle ?: __('storefront.site_name') }}"
                                 loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                                 class="hidden sm:block absolute inset-0 w-full h-full object-cover" />
                        @endif

                        {{-- تعتيم متدرّج لقراءة النص فوق أي صورة --}}
                        @if ($bTitle || $bSubtitle || $bButton)
                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent"></div>
                            <div class="absolute inset-0 flex items-end sm:items-center">
                                <div class="p-5 sm:p-10 max-w-lg text-white">
                                    @if ($bTitle)
                                        <h2 class="text-xl sm:text-3xl lg:text-4xl font-bold mb-1 sm:mb-2 drop-shadow">{{ $bTitle }}</h2>
                                    @endif
                                    @if ($bSubtitle)
                                        <p class="text-sm sm:text-base text-gray-100 mb-3 sm:mb-5 line-clamp-2 drop-shadow">{{ $bSubtitle }}</p>
                                    @endif
                                    @if ($bButton && $banner->button_url)
                                        <a href="{{ $banner->button_url }}"
                                           class="inline-flex items-center rounded-lg bg-white text-black font-semibold px-4 py-2 sm:px-5 sm:py-2.5 text-sm sm:text-base hover:bg-gray-100">
                                            {{ $bButton }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @elseif ($banner->button_url)
                            {{-- بلا نصوص: الصورة كلّها رابط --}}
                            <a href="{{ $banner->button_url }}" class="absolute inset-0" aria-label="{{ __('storefront.site_name') }}"></a>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($banners->count() > 1)
                {{-- أسهم التنقّل (مخفية على أصغر الشاشات لتوفير المساحة) --}}
                <button type="button" @click="prev()" aria-label="{{ __('storefront.prev') }}"
                        class="hidden sm:grid place-items-center absolute top-1/2 -translate-y-1/2 start-3 w-10 h-10 rounded-full bg-white/80 text-gray-900 hover:bg-white shadow">
                    <svg class="w-5 h-5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>
                <button type="button" @click="next()" aria-label="{{ __('storefront.next') }}"
                        class="hidden sm:grid place-items-center absolute top-1/2 -translate-y-1/2 end-3 w-10 h-10 rounded-full bg-white/80 text-gray-900 hover:bg-white shadow">
                    <svg class="w-5 h-5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </button>

                {{-- نقاط المؤشّر --}}
                <div class="absolute bottom-3 inset-x-0 flex items-center justify-center gap-2">
                    @foreach ($banners as $i => $banner)
                        <button type="button" @click="go({{ $i }})" aria-label="{{ __('storefront.go_to_slide') }} {{ $i + 1 }}"
                                class="h-2 rounded-full transition-all"
                                :class="active === {{ $i }} ? 'w-6 bg-white' : 'w-2 bg-white/50 hover:bg-white/80'"></button>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        {{-- لا توجد شرائح مرفوعة — بطل افتراضي --}}
        <section class="rounded-2xl bg-gradient-to-bl from-black via-gray-900 to-gray-800 text-white p-6 sm:p-10 mb-6">
            <div class="max-w-2xl">
                <h1 class="text-2xl sm:text-4xl font-bold mb-2">{{ __('storefront.site_name') }}</h1>
                <p class="text-gray-100 mb-5">{{ __('storefront.tagline') }}</p>
                <a href="{{ route('storefront.shop') }}"
                   class="inline-flex items-center rounded-lg bg-white text-black font-semibold px-5 py-2.5 hover:bg-gray-100">
                    {{ __('storefront.shop') }}
                </a>
            </div>
        </section>
    @endif

    {{-- تسوّق حسب الفئة --}}
    @if ($categories->isNotEmpty())
        <section class="py-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-900">{{ __('storefront.shop_by_category') }}</h2>
                <a href="{{ route('storefront.categories') }}" class="text-sm text-gray-900 hover:underline">{{ __('storefront.view_all') }}</a>
            </div>
            <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                @foreach ($categories as $category)
                    <a href="{{ route('storefront.category', $category->slug) }}"
                       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-white border border-gray-200 hover:border-gray-400 hover:shadow-sm text-center">
                        @if ($category->iconUrl())
                            <img src="{{ $category->iconUrl() }}" alt="{{ $category->name }}" loading="lazy"
                                 class="h-10 w-10 rounded-full object-cover bg-gray-100" />
                        @else
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-900 font-bold">
                                {{ mb_substr($category->name, 0, 1) }}
                            </span>
                        @endif
                        <span class="text-xs text-gray-700 line-clamp-1">{{ $category->name }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- مميّز (كتالوج) --}}
    <x-storefront.section :title="__('storefront.featured')" :items="$featured" :view-all="route('storefront.shop')" />

    {{-- وصل حديثًا (كتالوج) --}}
    <x-storefront.section :title="__('storefront.new_arrivals')" :items="$newArrivals" :view-all="route('storefront.shop', ['sort' => 'newest'])" />

    {{-- نقطة امتداد: الأكثر مبيعًا / مقترح لك (تُملأ من محرّك النمو مستقبلًا — ADR-032) --}}

    {{-- العلامات التجارية --}}
    @if ($brands->isNotEmpty())
        <section class="py-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-900">{{ __('storefront.shop_by_brand') }}</h2>
                <a href="{{ route('storefront.brands') }}" class="text-sm text-gray-900 hover:underline">{{ __('storefront.view_all') }}</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($brands as $brand)
                    <a href="{{ route('storefront.brand', $brand->slug) }}"
                       class="px-4 py-2 rounded-full bg-white border border-gray-200 text-sm text-gray-700 hover:border-gray-400">
                        {{ $brand->name }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-storefront.layout>
