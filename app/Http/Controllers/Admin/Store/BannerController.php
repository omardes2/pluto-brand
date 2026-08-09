<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\BannerRequest;
use App\Modules\Store\Models\StoreBanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

/**
 * إدارة شرائح بنر الصفحة الرئيسية (سلايدر). رفع صور + نصوص + رابط زر + ترتيب + تفعيل.
 * الصلاحيات عبر middleware على المسارات (settings.system.view/manage).
 */
class BannerController extends Controller
{
    public function index(): View
    {
        $banners = StoreBanner::orderBy('sort_order')->orderByDesc('id')->paginate(20);

        return view('admin.store.banners.index', compact('banners'));
    }

    public function create(): View
    {
        return view('admin.store.banners.form', ['banner' => new StoreBanner]);
    }

    public function store(BannerRequest $request): RedirectResponse
    {
        StoreBanner::create($this->payload($request));

        return redirect()->route('admin.banners.index')->with('success', __('تمت إضافة الشريحة.'));
    }

    public function edit(StoreBanner $banner): View
    {
        return view('admin.store.banners.form', compact('banner'));
    }

    public function update(BannerRequest $request, StoreBanner $banner): RedirectResponse
    {
        $banner->update($this->payload($request, $banner));

        return redirect()->route('admin.banners.index')->with('success', __('تم تحديث الشريحة.'));
    }

    public function toggle(StoreBanner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('success', __('تم تحديث حالة الشريحة.'));
    }

    public function destroy(StoreBanner $banner): RedirectResponse
    {
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('success', __('تم حذف الشريحة.'));
    }

    /**
     * يبني بيانات الحفظ، ويرفع الصور (سطح المكتب/الموبايل) مع حذف القديمة عند الاستبدال.
     *
     * @return array<string, mixed>
     */
    private function payload(BannerRequest $request, ?StoreBanner $banner = null): array
    {
        $data = collect($request->validated())->except(['image', 'mobile_image'])->toArray();
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($request->input('sort_order') ?? $banner?->sort_order ?? 0);

        if ($request->hasFile('image')) {
            if ($banner?->image) {
                Storage::disk('public')->delete($banner->image);
            }
            $data['image'] = $request->file('image')->store('banners', 'public');
        }

        if ($request->hasFile('mobile_image')) {
            if ($banner?->mobile_image) {
                Storage::disk('public')->delete($banner->mobile_image);
            }
            $data['mobile_image'] = $request->file('mobile_image')->store('banners', 'public');
        }

        return $data;
    }
}
