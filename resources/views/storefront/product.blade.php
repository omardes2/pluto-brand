@inject('sf', 'App\Modules\Store\Services\StorefrontService')
@php
    $variant = $product->defaultVariant;
    $price = $sf->sellingPrice($product);
    $regular = $sf->regularPrice($product);
    $onSale = $sf->onSale($product);
    $available = $sf->availableQty($product);
    $inStock = $available > 1e-9;
    $images = $product->images;
    $primary = $product->primaryImage ?: $images->first();
    $locale = app()->getLocale();
    $displayName = $locale === 'en' && filled($product->name_en) ? $product->name_en : $product->name;
    $desc = $locale === 'en' && filled($product->description_en) ? $product->description_en : $product->description;
    $shortDesc = $locale === 'en' && filled($product->short_description_en) ? $product->short_description_en : $product->short_description;
    $metaDesc = $product->meta_description ?: $shortDesc ?: \Illuminate\Support\Str::limit(strip_tags((string) $desc), 155);
@endphp

<x-storefront.layout
    :title="$product->meta_title ?: $displayName"
    :description="$metaDesc"
    :canonical="route('storefront.product', $product->slug)"
    :image="$primary?->url()"
    :page-event="['name' => 'ProductViewed', 'payload' => ['product' => $product->uuid, 'sku' => $product->sku]]">

    {{-- مسار التنقّل --}}
    <nav class="text-xs text-gray-500 mb-4 flex items-center gap-1.5 flex-wrap" aria-label="breadcrumb">
        <a href="{{ route('storefront.home') }}" class="hover:text-gray-900">{{ __('storefront.home') }}</a>
        <span>/</span>
        @if ($product->category)
            <a href="{{ route('storefront.category', $product->category->slug) }}" class="hover:text-gray-900">{{ $product->category->name }}</a>
            <span>/</span>
        @endif
        <span class="text-gray-700">{{ $displayName }}</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- المعرض --}}
        <div x-data="{ main: '{{ $primary?->url() }}' }">
            <div class="aspect-square bg-gray-100 rounded-2xl overflow-hidden flex items-center justify-center">
                @if ($primary)
                    <img :src="main" alt="{{ $primary->alt ?: $displayName }}" class="w-full h-full object-cover">
                @else
                    <svg class="h-24 w-24 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 3.75h16.5v16.5H3.75V3.75Z"/></svg>
                @endif
            </div>
            @if ($images->count() > 1)
                <div class="mt-3 grid grid-cols-5 gap-2">
                    @foreach ($images as $image)
                        <button type="button" @click="main = '{{ $image->url() }}'"
                                class="aspect-square rounded-lg overflow-hidden border-2 border-transparent focus:border-gray-900"
                                :class="main === '{{ $image->url() }}' ? 'border-gray-900' : 'border-gray-200'">
                            <img src="{{ $image->url() }}" alt="{{ $image->alt ?: $displayName }}" loading="lazy" class="w-full h-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- التفاصيل --}}
        <div>
            @if ($product->brand)
                <a href="{{ route('storefront.brand', $product->brand->slug) }}" class="text-sm text-gray-900 hover:underline">{{ $product->brand->name }}</a>
            @endif
            <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ $displayName }}</h1>

            @php
                $options = $sf->options($product);
                $hasOptions = count($options) > 0;
                $variantMap = $sf->variantMap($product);
                $defaultVariant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
                $defaultSel = [];
                foreach (($defaultVariant?->attributeValues ?? []) as $val) {
                    $defaultSel[(int) $val->attribute_id] = (int) $val->id;
                }
                // الباركود المعروض: باركود المتغيّر الافتراضي، وإلا باركود المنتج، وإلا رمز المنتج.
                $defaultBarcode = $defaultVariant?->barcode ?: ($product->barcode ?: $product->sku);
                $pageCfg = [
                    'options' => $options,
                    'map' => $variantMap,
                    'initial' => (object) $defaultSel,
                    'defaultPrice' => $price,
                    'defaultRegular' => $regular,
                    'defaultBarcode' => $defaultBarcode,
                ];
            @endphp

            @if ($hasOptions)
                {{-- منتج متعدّد الخيارات: باركود/تسعير/توافر/إضافة مدفوعة باختيار المقاس/اللون --}}
                <div x-data="productPage(@js($pageCfg))" x-cloak>
                    {{-- الباركود (يتغيّر حسب المتغيّر المختار) --}}
                    <div class="mt-2 text-sm text-gray-500 flex items-center gap-3">
                        <span>{{ __('storefront.barcode') }}: <span x-text="barcode"></span></span>
                    </div>

                    {{-- السعر --}}
                    <div class="mt-4 flex items-center gap-3">
                        <span class="text-3xl font-bold text-black"><span x-text="fmt(price)"></span> {{ __('storefront.currency') }}</span>
                        <template x-if="onSale">
                            <span class="text-lg text-gray-400 line-through"><span x-text="fmt(regular)"></span> {{ __('storefront.currency') }}</span>
                        </template>
                        <template x-if="onSale">
                            <span class="bg-gray-900 text-white text-xs font-bold px-2 py-1 rounded"><span x-text="discount"></span>% {{ __('storefront.off') }}</span>
                        </template>
                    </div>

                    {{-- التوفّر --}}
                    <div class="mt-3">
                        <template x-if="current && current.in_stock">
                            <span class="inline-flex items-center gap-1.5 text-sm text-black"><span class="h-2 w-2 rounded-full bg-gray-900"></span>{{ __('storefront.in_stock') }}</span>
                        </template>
                        <template x-if="!current || !current.in_stock">
                            <span class="inline-flex items-center gap-1.5 text-sm text-gray-500"><span class="h-2 w-2 rounded-full bg-gray-400"></span>{{ __('storefront.out_of_stock') }}</span>
                        </template>
                    </div>

                    @if ($shortDesc)
                        <p class="mt-4 text-gray-600 leading-relaxed">{{ $shortDesc }}</p>
                    @endif

                    {{-- محدّد الخيارات --}}
                    <div class="mt-5 space-y-4">
                        <template x-for="axis in options" :key="axis.id">
                            <div>
                                <div class="text-sm font-medium text-gray-700 mb-1.5" x-text="axis.name"></div>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="val in axis.values" :key="val.id">
                                        <button type="button" @click="select(axis.id, val.id)" :disabled="!valueAvailable(val.id)"
                                                class="border rounded-lg text-sm transition"
                                                :class="[
                                                    isSelected(axis.id, val.id) ? 'border-gray-900 ring-1 ring-gray-900' : 'border-gray-300 hover:border-gray-500',
                                                    valueAvailable(val.id) ? '' : 'opacity-40 cursor-not-allowed line-through',
                                                    axis.type === 'color' ? 'p-1' : 'px-3 py-1.5',
                                                ]">
                                            <template x-if="axis.type === 'color'">
                                                <span class="inline-block h-6 w-6 rounded-full border border-gray-200" :style="`background:${val.color_hex || '#ffffff'}`" :title="val.label"></span>
                                            </template>
                                            <template x-if="axis.type !== 'color'">
                                                <span x-text="val.label"></span>
                                            </template>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- الكمية + الإضافة --}}
                    <div class="mt-6 flex items-center gap-3">
                        <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="-">−</button>
                            <input type="text" x-model="qty" readonly class="w-12 text-center border-0 focus:ring-0 p-0" aria-label="{{ __('storefront.qty') }}">
                            <button type="button" @click="qty++" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="+">+</button>
                        </div>
                        <button type="button" @click="add()" :disabled="!canBuy"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-gray-900 hover:bg-black disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed text-white font-semibold px-6 py-3 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-1">
                            <span x-show="!done && canBuy">{{ __('storefront.add_to_cart') }}</span>
                            <span x-show="!done && incomplete" x-cloak>{{ __('storefront.select_options') }}</span>
                            <span x-show="!done && !incomplete && !canBuy" x-cloak>{{ __('storefront.out_of_stock') }}</span>
                            <span x-show="done" x-cloak>✓ {{ __('storefront.added') }}</span>
                        </button>
                    </div>
                </div>
            @else
                {{-- منتج بسيط: كما هو (متغيّر افتراضي واحد) --}}
                {{-- الباركود --}}
                <div class="mt-2 text-sm text-gray-500 flex items-center gap-3">
                    <span>{{ __('storefront.barcode') }}: {{ $defaultBarcode }}</span>
                </div>

                {{-- السعر --}}
                <div class="mt-4 flex items-center gap-3">
                    <span class="text-3xl font-bold text-black">{{ number_format($price, 2) }} {{ __('storefront.currency') }}</span>
                    @if ($onSale)
                        <span class="text-lg text-gray-400 line-through">{{ number_format($regular, 2) }} {{ __('storefront.currency') }}</span>
                        <span class="bg-gray-900 text-white text-xs font-bold px-2 py-1 rounded">
                            {{ (int) round((1 - $price / max($regular, 0.01)) * 100) }}% {{ __('storefront.off') }}
                        </span>
                    @endif
                </div>

                {{-- التوفّر --}}
                <div class="mt-3">
                    @if ($inStock)
                        <span class="inline-flex items-center gap-1.5 text-sm text-black">
                            <span class="h-2 w-2 rounded-full bg-gray-900"></span>{{ __('storefront.in_stock') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-sm text-gray-500">
                            <span class="h-2 w-2 rounded-full bg-gray-400"></span>{{ __('storefront.out_of_stock') }}
                        </span>
                    @endif
                </div>

                @if ($shortDesc)
                    <p class="mt-4 text-gray-600 leading-relaxed">{{ $shortDesc }}</p>
                @endif

                {{-- إضافة للسلة --}}
                @if ($variant && $inStock)
                    <div class="mt-6 flex items-center gap-3" x-data="{ qty: 1, done: false }">
                        <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="-">−</button>
                            <input type="text" x-model="qty" readonly class="w-12 text-center border-0 focus:ring-0 p-0" aria-label="{{ __('storefront.qty') }}">
                            <button type="button" @click="qty++" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="+">+</button>
                        </div>
                        <button type="button"
                                @click="await $store.cart.add('{{ $variant->uuid }}', qty); done = true; setTimeout(() => done = false, 1500)"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-gray-900 hover:bg-black text-white font-semibold px-6 py-3 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-1">
                            <span x-show="!done">{{ __('storefront.add_to_cart') }}</span>
                            <span x-show="done" x-cloak>✓ {{ __('storefront.added') }}</span>
                        </button>
                    </div>
                @else
                    <div class="mt-6">
                        <button type="button" disabled class="w-full rounded-lg bg-gray-200 text-gray-400 font-semibold px-6 py-3 cursor-not-allowed">
                            {{ __('storefront.out_of_stock') }}
                        </button>
                    </div>
                @endif
            @endif

            {{-- المفضّلة --}}
            <div class="mt-4">
                @auth
                    @php
                        $wishCustomer = \App\Modules\Crm\Models\Customer::where('user_id', auth()->id())->first();
                        $inWishlist = $wishCustomer && app(\App\Modules\Store\Services\WishlistService::class)->has($wishCustomer, $product);
                    @endphp
                    @if ($wishCustomer)
                        <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 text-sm {{ $inWishlist ? 'text-rose-600' : 'text-gray-500 hover:text-rose-600' }}">
                                <svg class="h-4 w-4" fill="{{ $inWishlist ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.6-10-9.2C.6 8.9 2 5.5 5.2 5.5c1.9 0 3.2 1.1 3.8 2.2.6-1.1 1.9-2.2 3.8-2.2 3.2 0 4.6 3.4 3.2 6.3C19.5 16.4 12 21 12 21Z"/></svg>
                                {{ $inWishlist ? __('account.in_wishlist') : __('account.add_to_wishlist') }}
                            </button>
                        </form>
                    @endif
                @else
                    <a href="{{ route('account.login') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-rose-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.6-10-9.2C.6 8.9 2 5.5 5.2 5.5c1.9 0 3.2 1.1 3.8 2.2.6-1.1 1.9-2.2 3.8-2.2 3.2 0 4.6 3.4 3.2 6.3C19.5 16.4 12 21 12 21Z"/></svg>
                        {{ __('account.add_to_wishlist') }}
                    </a>
                @endauth
            </div>

            {{-- المشاركة --}}
            <div class="mt-4" x-data="{ shared: false }">
                <button type="button"
                        @click="navigator.share ? navigator.share({ title: '{{ $displayName }}', url: window.location.href }) : (navigator.clipboard.writeText(window.location.href), shared = true, setTimeout(() => shared = false, 1500))"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-900">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7.2 10.5a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0Zm14.4-6a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0Zm0 12a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0ZM7.2 10.5l9.6-4.5m-9.6 6 9.6 4.5"/></svg>
                    <span x-show="!shared">{{ __('storefront.share') }}</span>
                    <span x-show="shared" x-cloak>✓</span>
                </button>
            </div>

            {{-- المقاسات/الألوان المتاحة (للمنتجات متعدّدة الخيارات) — عرض إعلامي --}}
            @if ($hasOptions)
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h2 class="text-sm font-bold text-gray-900 mb-2">{{ __('storefront.attributes') }}</h2>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        @foreach ($options as $axis)
                            <div class="flex justify-between gap-2 py-1 border-b border-gray-100">
                                <dt class="text-gray-500">{{ $axis['name'] }}</dt>
                                <dd class="text-gray-800 font-medium">{{ collect($axis['values'])->pluck('label')->implode('، ') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </div>

    {{-- الوصف الكامل --}}
    @if ($desc)
        <section class="mt-8 bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-3">{{ __('storefront.description') }}</h2>
            <div class="prose prose-sm max-w-none text-gray-600 leading-relaxed whitespace-pre-line">{{ $desc }}</div>
        </section>
    @endif

    {{-- أقسام التوصيات (Phase 6 / ADR-045) — مُتتبَّعة (ظهور/نقر) عبر JS المتجر --}}
    <x-storefront.section :title="__('storefront.frequently_bought')" :items="$frequentlyBoughtTogether" recoType="fbt" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.related')" :items="$related" recoType="related" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.cross_sell')" :items="$crossSell" recoType="cross_sell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.upsell')" :items="$upsell" recoType="upsell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.bundles')" :items="$bundles" recoType="complementary" placement="product" :source="$product->id" />

    {{-- بيانات مهيكلة: منتج --}}
    @push('structured-data')
        <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $displayName,
            'sku' => $product->sku,
            'description' => (string) $metaDesc,
            'image' => $primary ? [$primary->url()] : [],
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => \App\Modules\Foundation\Services\Settings::get('store.currency', 'ILS'),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => route('storefront.product', $product->slug),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush

    {{-- محدّد خيارات المنتج (مقاس/لون): يربط الاختيار بالمتغيّر الصحيح ثم بالسلة --}}
    <script>
        function productPage(cfg) {
            return {
                options: cfg.options,
                map: cfg.map,
                selection: cfg.initial || {},
                qty: 1,
                done: false,
                select(attrId, valueId) {
                    if (!this.valueAvailable(valueId)) return;
                    this.selection[attrId] = valueId;
                },
                isSelected(a, v) { return this.selection[a] === v; },
                get key() {
                    if (!this.options.length) return '';
                    const ids = this.options.map(o => this.selection[o.id]).filter(v => v != null);
                    if (ids.length < this.options.length) return null; // اختيار ناقص
                    return [...ids].sort((a, b) => a - b).join('-');
                },
                get incomplete() { return this.key === null; },
                get current() { const k = this.key; return k === null ? null : (this.map[k] || null); },
                get barcode() { return (this.current && this.current.barcode) ? this.current.barcode : cfg.defaultBarcode; },
                get price() { return this.current ? this.current.price : cfg.defaultPrice; },
                get regular() { return this.current ? this.current.regular : cfg.defaultRegular; },
                get onSale() { return !!this.current && this.current.price + 1e-9 < this.current.regular; },
                get discount() { return this.regular > 0 ? Math.round((1 - this.price / this.regular) * 100) : 0; },
                get canBuy() { return !!this.current && this.current.in_stock; },
                valueAvailable(valueId) {
                    // قيمة متاحة إن وُجد متغيّر متوفّر يحتويها.
                    return Object.values(this.map).some(v => v.in_stock && (v.value_ids || []).includes(valueId));
                },
                fmt(n) { return Number(n).toFixed(2); },
                async add() {
                    if (!this.canBuy) return;
                    await this.$store.cart.add(this.current.uuid, this.qty);
                    this.done = true;
                    setTimeout(() => this.done = false, 1500);
                },
            };
        }
    </script>
</x-storefront.layout>
