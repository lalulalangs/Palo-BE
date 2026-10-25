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
            // `destination_id` (subdistrict RajaOngkir) adalah cara yang benar.
            // `destination_city` dipertahankan satu rilis untuk frontend lama:
            // server akan mencarikan ID-nya (hasil pencarian di-cache).
            'destination_id' => ['nullable', 'integer', 'min:1'],
            'destination_city' => ['required_without:destination_id', 'nullable', 'string', 'max:100'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_id.integer' => 'Tujuan pengiriman tidak valid.',
            'destination_city.required_without' => 'Kota tujuan atau ID destinasi wajib diisi.',
            'items.max' => 'Maksimal 50 item per permintaan ongkir.',
            'items.*.sku_id.required_with' => 'SKU produk wajib dipilih.',
            'items.*.sku_id.exists' => 'SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1.',
            'items.*.quantity.max' => 'Jumlah barang maksimal 100.',
        ];
    }
}
