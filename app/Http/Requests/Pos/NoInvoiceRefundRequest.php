<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class NoInvoiceRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.refund') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.condition' => ['nullable', 'in:sellable,damaged'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
