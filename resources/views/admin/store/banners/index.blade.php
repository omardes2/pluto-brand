<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ __('بنر الصفحة الرئيسية') }}</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('شرائح السلايدر')">
                @can('settings.system.manage')
                    <a href="{{ route('admin.banners.create') }}" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('شريحة جديدة') }}</a>
                @endcan
            </x-admin.header>

            <p class="text-sm text-gray-500 mb-4">
                {{ __('تظهر الشرائح المفعّلة في السلايدر أعلى الصفحة الرئيسية مرتّبة حسب «الترتيب». الصور بمقاس عريض (يُنصح 1600×600 بكسل).') }}
            </p>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b">
                        <tr>
                            <th class="py-2 px-3 font-medium">{{ __('الصورة') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('العنوان') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الرابط') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الترتيب') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($banners as $banner)
                            <tr>
                                <td class="py-2 px-3">
                                    @if ($banner->imageUrl())
                                        <img src="{{ $banner->imageUrl() }}" alt="" class="w-28 h-14 rounded-md object-cover bg-gray-100" />
                                    @else
                                        <span class="grid place-items-center w-28 h-14 rounded-md bg-gray-100 text-gray-300 text-xs">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-800">{{ $banner->title ?: '—' }}</td>
                                <td class="py-2 px-3 text-gray-500 truncate max-w-[16rem]">{{ $banner->button_url ?: '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $banner->sort_order }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $banner->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $banner->is_active ? __('مفعّلة') : __('معطّلة') }}
                                    </span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="flex items-center gap-3">
                                        @can('settings.system.manage')
                                            <a href="{{ route('admin.banners.edit', $banner) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                                            <form method="POST" action="{{ route('admin.banners.toggle', $banner) }}">
                                                @csrf
                                                <button class="text-gray-600 hover:underline">{{ $banner->is_active ? __('إخفاء') : __('إظهار') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">
                                                @csrf @method('DELETE')
                                                <button class="text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد شرائح بعد. أضف أول شريحة لتظهر في السلايدر.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $banners->links() }}</div>
        </div>
    </div>
</x-app-layout>
