<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * التحقّق من شريحة البنر (إنشاء/تعديل). الصورة مطلوبة عند الإنشاء فقط
 * (تبقى الصورة الحالية عند التعديل بدون رفع جديد).
 */
class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر middleware الصلاحيات على المسار
    }

    public function rules(): array
    {
        $imageRule = $this->isMethod('post') ? ['required'] : ['nullable'];

        return [
            'image' => array_merge($imageRule, ['image', 'mimes:png,jpg,jpeg,webp', 'max:4096']),
            'mobile_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'title' => ['nullable', 'string', 'max:150'],
            'title_en' => ['nullable', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:250'],
            'subtitle_en' => ['nullable', 'string', 'max:250'],
            'button_label' => ['nullable', 'string', 'max:60'],
            'button_label_en' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', 'string', 'max:250'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
