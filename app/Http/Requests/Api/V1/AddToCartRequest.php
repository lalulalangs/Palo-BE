<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'integer', 'exists:skus,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku_id.required' => 'SKU produk wajib dipilih.',
            'sku_id.exists' => 'SKU produk tidak ditemukan.',
            'quantity.required' => 'Jumlah barang wajib diisi.',
            'quantity.min' => 'Jumlah barang minimal 1.',
            'quantity.max' => 'Jumlah barang maksimal 100.',
        ];
    }
}
