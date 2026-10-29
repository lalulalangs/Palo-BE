<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Checkout\CodWhatsAppService;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('voucher_code') && is_string($this->voucher_code)) {
            $merge['voucher_code'] = strtoupper(trim($this->voucher_code));
        }

        if ($this->has('acknowledge_price_change')) {
            $merge['acknowledge_price_change'] = filter_var($this->acknowledge_price_change, FILTER_VALIDATE_BOOLEAN);
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
            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string', 'max:100'],
            'shipping_address.phone' => ['required', 'string', 'max:30'],
            'shipping_address.street' => ['required', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.city_code' => ['nullable', 'string', 'max:50'],
            // Subdistrict ID RajaOngkir: cara yang benar menghitung ongkir.
            // Bila kosong, server mencari dari `city` (satu rilis transisi).
            'shipping_address.destination_id' => ['nullable', 'integer', 'min:1'],
            'shipping_address.province' => ['required', 'string', 'max:100'],
            'shipping_address.province_code' => ['nullable', 'string', 'max:50'],
            'shipping_address.district' => ['nullable', 'string', 'max:100'],
            'shipping_address.subdistrict' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.notes' => ['nullable', 'string', 'max:255'],

            'items' => ['nullable', 'array', 'max:50'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:100'],
            'items.*.price_seen' => ['nullable', 'numeric', 'min:0'],

            'shipping_courier' => ['required', 'string', 'max:50'],
            'shipping_service' => ['required', 'string', 'max:50'],
            // shipping_cost sengaja TIDAK dipercaya dari client (remediasi audit
            // S-02): tarif resmi dihitung ulang server-side di RegisteredCheckoutService.
            'shipping_etd' => ['nullable', 'string', 'max:50'],

            // `online` = pembayaran lewat DOKU Checkout TANPA mengunci kanal:
            // pembeli memilih QRIS/VA/e-wallet/kartu di halaman DOKU. Nilai
            // kanal spesifik (qris, virtual_account, ...) tetap diterima untuk
            // klien lama, tetapi mengunci halaman DOKU ke kanal itu saja.
            'payment_method' => ['required', 'string', 'in:qris,virtual_account,ewallet,bank_transfer,credit_card,online,cod'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
            'acknowledge_price_change' => ['nullable', 'boolean'],
            'shipping_address_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('payment_method') === 'cod') {
                $codService = app(CodWhatsAppService::class);
                if (! $codService->isCodEnabled()) {
                    $validator->errors()->add('payment_method', 'Metode pembayaran COD sedang dinonaktifkan.');
                }
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
            'items.*.sku_id.required_with' => 'SKU produk wajib dipilih.',
            'items.*.sku_id.exists' => 'SKU produk tidak ditemukan.',
            'items.*.quantity.min' => 'Jumlah barang minimal 1.',
            'shipping_courier.required' => 'Kurir pengiriman wajib dipilih.',
            'shipping_service.required' => 'Layanan kurir wajib dipilih.',
            'payment_method.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method.in' => 'Metode pembayaran tidak valid.',
        ];
    }
}
