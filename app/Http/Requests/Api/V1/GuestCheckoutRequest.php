<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class GuestCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('guest_name') && is_string($this->guest_name)) {
            $merge['guest_name'] = trim($this->guest_name);
        }

        if ($this->has('guest_phone') && is_string($this->guest_phone)) {
            $merge['guest_phone'] = trim($this->guest_phone);
        }

        if ($this->has('note') && is_string($this->note)) {
            $merge['note'] = trim($this->note);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:100'],
            'items.*.price_seen' => ['nullable', 'numeric', 'min:0'],
            'guest_name' => ['nullable', 'string', 'max:100'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.max' => 'Maksimal 50 item per transaksi checkout.',
            'items.*.sku_id.required_with' => 'SKU produk wajib dipilih.',
            'items.*.sku_id.exists' => 'SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1.',
            'items.*.quantity.max' => 'Jumlah barang maksimal 100.',
            'guest_name.max' => 'Nama tamu maksimal 100 karakter.',
            'guest_phone.max' => 'Nomor HP maksimal 30 karakter.',
            'note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
