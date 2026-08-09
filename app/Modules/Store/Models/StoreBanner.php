<?php

namespace App\Modules\Store\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Store\StoreBannerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * شريحة بنر الصفحة الرئيسية (سلايدر) — صورة خلفية + نصوص + رابط زر، تُدار من اللوحة.
 * القيم النصّية ثنائية اللغة (عربي أساسي + إنجليزي اختياري) وتُختار حسب لغة العرض.
 */
class StoreBanner extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'image', 'mobile_image', 'title', 'title_en', 'subtitle', 'subtitle_en',
        'button_label', 'button_label_en', 'button_url', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** رابط صورة البنر (على قرص public) أو null. */
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /** رابط صورة الموبايل إن وُجدت، وإلا صورة سطح المكتب. */
    public function mobileImageUrl(): ?string
    {
        return $this->mobile_image
            ? Storage::disk('public')->url($this->mobile_image)
            : $this->imageUrl();
    }

    /** العنوان حسب لغة العرض (يتراجع للعربي). */
    public function titleFor(string $locale): ?string
    {
        return $locale === 'en' ? ($this->title_en ?: $this->title) : $this->title;
    }

    public function subtitleFor(string $locale): ?string
    {
        return $locale === 'en' ? ($this->subtitle_en ?: $this->subtitle) : $this->subtitle;
    }

    public function buttonLabelFor(string $locale): ?string
    {
        return $locale === 'en' ? ($this->button_label_en ?: $this->button_label) : $this->button_label;
    }

    /** إبطال كاش سلايدر المتجر عند أي تغيير (Production Readiness). */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('storefront:banners');
        static::saved($flush);
        static::deleted($flush);
    }

    protected static function newFactory(): Factory
    {
        return StoreBannerFactory::new();
    }
}
