<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Checkout\CodWhatsAppService;
use Illuminate\Foundation\Http\FormRequest;

class GuestCodCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

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
            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string', 'max:100'],
            'shipping_address.phone' => ['required', 'string', 'max:30'],
            'shipping_address.street' => ['required', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.province' => ['required', 'string', 'max:100'],
            'shipping_address.district' => ['nullable', 'string', 'max:100'],
            'shipping_address.subdistrict' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.notes' => ['nullable', 'string', 'max:255'],

            'items' => ['nullable', 'array', 'max:50'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:100'],
            'items.*.price_seen' => ['nullable', 'numeric', 'min:0'],

            // Ongkir sengaja tidak divalidasi: guest COD adalah serah-terima
            // lead ke CS via WhatsApp, dan nilai dari client tidak dipakai.
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $codService = app(CodWhatsAppService::class);
            if (! $codService->isCodEnabled()) {
                $validator->errors()->add('payment_method', 'Metode pembayaran COD sedang dinonaktifkan.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_address.required' => 'Alamat pengiriman wajib diisi.',
            'shipping_address.recipient_name.required' => 'Nama penerima wajib diisi.',
            'shipping_address.phone.required' => 'Nomor telepon penerima wajib diisi.',
            'shipping_address.street.required' => 'Alamat jalan wajib diisi.',
            'shipping_address.city.required' => 'Kota tujuan wajib diisi.',
            'shipping_address.province.required' => 'Provinsi tujuan wajib diisi.',
            'items.max' => 'Maksimal 50 item per transaksi checkout.',
            'items.*.sku_id.required_with' => 'SKU produk wajib dipilih.',
            'items.*.sku_id.exists' => 'SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1.',
            'items.*.quantity.max' => 'Jumlah barang maksimal 100.',
            'note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
