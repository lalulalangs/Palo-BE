<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('destination_city') && is_string($this->destination_city)) {
            $this->merge(['destination_city' => trim($this->destination_city)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'destination_city' => ['required', 'string', 'max:100'],
            'items' => ['nullable', 'array'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_city.required' => 'Kota tujuan pengiriman wajib diisi.',
            'items.*.sku_id.required_with' => 'SKU produk wajib dipilih.',
            'items.*.sku_id.exists' => 'SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1.',
        ];
    }
}
