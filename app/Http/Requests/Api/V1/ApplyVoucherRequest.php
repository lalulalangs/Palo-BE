<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ApplyVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required_without:items', 'nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array', 'min:1', 'max:50'],
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
            'code.required' => 'Kode voucher wajib diisi.',
            'code.max' => 'Kode voucher maksimal 50 karakter.',
            'subtotal.required_without' => 'Subtotal belanja atau daftar item barang wajib disertakan.',
            'subtotal.numeric' => 'Subtotal belanja harus berupa angka numerik.',
            'subtotal.min' => 'Subtotal belanja tidak boleh bernilai negatif.',
            'items.min' => 'Daftar item barang tidak boleh kosong.',
            'items.*.sku_id.exists' => 'Item SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1 unit.',
        ];
    }
}
