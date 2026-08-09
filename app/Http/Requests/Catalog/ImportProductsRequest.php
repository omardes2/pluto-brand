<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('اختر ملف CSV.'),
            'file.mimes' => __('يجب أن يكون الملف بصيغة CSV (احفظ من Excel باسم «CSV UTF-8»).'),
            'file.max' => __('حجم الملف كبير جدًا (الحدّ 5 ميجابايت).'),
        ];
    }
}
