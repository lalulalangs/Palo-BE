<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi input untuk pembuatan order.
 *
 * ===================================================================
 *  APA YANG SEBAIKNYA DAN TIDAK SEBAIKNYA DIVALIDASI DI SINI
 * ===================================================================
 * FormRequest HANYA memeriksa bentuk & tipe data. FormRequest TIDAK BOLEH
 * memeriksa aturan bisnis, karena aturannya sudah milik domain layer.
 *
 * Contoh yang SALAH dilakukan di sini:
 *     'shipping_cost' => 'required|numeric|min:0' // <- bentuknya saja.
 *                                                    //    Client tetap bisa
 *                                                    //    mengirim 0, dan kita
 *                                                    //    akan memakainya.
 *
 * Contoh yang BENAR:
 *   - Di sini: "shipping_cost harus integer >= 0" (bentuk datanya).
 *   - Di OrderPricingService: "ongkir yang dipakai adalah hasil quote
 *     terverifikasi server, bukan angka yang dikirim" (aturannya).
 *
 * Aturan yang di-domain: harga, stok, voucher, ongkir, transisi status.
 * Aturan di sini: required, string, integer, max length, format email.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Order hanya untuk user yang sudah login. Sanctum middleware
        // sudah menyaring ini, tapi authorize() adalah lapis kedua.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ---- Alamat ----
            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string', 'max:120'],
            'shipping_address.phone' => ['required', 'string', 'max:32'],
            'shipping_address.province' => ['required', 'string', 'max:100'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            // city_code dipakai API kurir, jadi wajib ada walau alamatnya
            // diinput bebas.
            'shipping_address.city_code' => ['required', 'string', 'max:20'],
            'shipping_address.street' => ['required', 'string', 'max:500'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:10'],
            'shipping_address.district' => ['nullable', 'string', 'max:100'],
            'shipping_address.subdistrict' => ['nullable', 'string', 'max:100'],
            'shipping_address.notes' => ['nullable', 'string', 'max:500'],

            // ---- Item ----
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku_id' => ['required', 'integer', 'exists:skus,id'],
            // Batas atas per item: mencegah order 9999 pcs yang jelas
            // tidak realistis dan bisa jadi abuse rate limit.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            // Harga yang SEDIKAT buyer lihat di keranjang. Dipakai HANYA
            // untuk mengabari perubahan harga (PRD §3.6 AC) — TIDAK PERNAH
            // dipakai menghitung total. Total selalu dari `skus.price`.
            'items.*.price_seen' => ['nullable', 'integer', 'min:0'],

            // ---- Pengiriman ----
            'shipping_courier' => ['nullable', 'string', 'max:50'],
            'shipping_service' => ['nullable', 'string', 'max:50'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'shipping_etd' => ['nullable', 'string', 'max:50'],

            // ---- Pembayaran ----
            'payment_method' => ['required', 'string', Rule::in([
                'virtual_account',
                'ewallet',
                'qris',
                'credit_card',
            ])],

            // ---- Voucher ----
            'voucher_code' => ['nullable', 'string', 'max:50'],

            // Buyer sudah melihat dan menyetujui perubahan harga (PRD §3.6).
            // Tanpa flag ini, checkout akan selalu berhenti di 409 dan buyer
            // tidak akan pernah bisa menyelesaikan pesanan.
            'acknowledge_price_change' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Pesan validasi Bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_address.required' => 'Alamat pengiriman wajib diisi.',
            'shipping_address.recipient_name.required' => 'Nama penerima wajib diisi.',
            'shipping_address.phone.required' => 'Nomor telepon wajib diisi.',
            'shipping_address.city_code.required' => 'Kota wajib dipilih dari daftar.',
            'shipping_address.street.required' => 'Alamat lengkap wajib diisi.',
            'items.required' => 'Keranjang tidak boleh kosong.',
            'items.min' => 'Keranjang tidak boleh kosong.',
            'items.*.sku_id.exists' => 'Salah satu produk di keranjang sudah tidak tersedia.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
            'payment_method.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method.in' => 'Metode pembayaran tidak didukung.',
            'voucher_code.max' => 'Kode voucher terlalu panjang.',
        ];
    }

    /**
     * Data yang siap dipakai controller — sudah bersih dari field kosong.
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'shipping_address',
            'items',
            'shipping_courier',
            'shipping_service',
            'shipping_cost',
            'shipping_etd',
            'payment_method',
            'voucher_code',
            'acknowledge_price_change',
        ]);
    }
}
