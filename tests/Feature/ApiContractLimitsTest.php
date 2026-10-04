<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test yang mengunci BATAS angka yang tertulis di `docs/KONTRAK-API.md`.
 *
 * ====================================================================
 *  KENAPA FILE INI ADA
 * ====================================================================
 * Dokumentasi menulis "per_page maks 50", sementara kodenya menolak 50
 * dengan `max:48`. Salah satu dari keduanya pasti salah, dan tidak ada test
 * yang menangkapnya.
 *
 * Aturan umumnya: kalau sebuah angka disebut di kontrak, angka itu harus
 * punya penjaga. Tanpa penjaga, dokumen dan kode melenceng diam-diam sampai
 * ada frontend yang mengirim `per_page=50` dan mendapat 422 di produksi.
 *
 * File ini sengaja TIDAK menguji fitur katalog. Yang dijaga hanya
 * kesesuaian angka batas antara kontrak dan implementasi.
 *
 * CATATAN: angka 48 berasal dari implementasi, bukan dari keputusan yang
 * terdokumentasi — tidak ada alasan yang tercatat di kode aslinya. Test
 * ini mengunci nilai yang ada supaya tidak berubah diam-diam; kalau owner
 * ingin angka lain, ubah di `CatalogController` DAN di dokumen, lalu test
 * ini akan menunjuk ke tempat yang perlu diselaraskan.
 */
class ApiContractLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_di_atas_batas_ditolak(): void
    {
        $this->getJson('/api/v1/products?per_page=49')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_per_page_pada_batas_diterima(): void
    {
        $this->getJson('/api/v1/products?per_page=48')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['per_page']]);
    }

    public function test_per_page_kosong_memakai_default(): void
    {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 24);
    }

    /**
     * `per_page` harus positif, bukan sekadar "tidak melebihi 48".
     *
     * Tanpa aturan `min:1`, `per_page=0` menghasilkan `LIMIT 0`: respons
     * 200 dengan `data: []` sementara `meta.total` tetap berisi angka asli.
     * Itu terlihat seperti katalog kosong, bukan permintaan tidak valid —
     * dan debug-nya jauh lebih susah daripada 422 yang jelas.
     */
    public function test_per_page_harus_positif(): void
    {
        $this->getJson('/api/v1/products?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * Batas item pada checkout tamu WhatsApp (PRD §3.11).
     *
     * Ini batas berbeda dari `per_page`: yang dibatasi adalah jumlah baris
     * yang boleh dikirim SEKALI request, bukan ukuran halaman. Jadi 50 di
     * sini benar dan tidak boleh disamakan dengan batas katalog.
     *
     * Endpoint publik dipakai, bukan `shipping/quote` yang punya batas sama —
     * `quote` ada di belakang `auth:sanctum`, jadi selalu menjawab 401 dulu
     * dan aturan validasinya tidak pernah tercapai.
     */
    public function test_batas_item_checkout_tamu_50(): void
    {
        $terlaluBanyak = array_fill(0, 51, ['sku_id' => 1, 'quantity' => 1]);

        $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => $terlaluBanyak,
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_keranjang_kosong_ditolak_dengan_pesan(): void
    {
        // `min:1` dan `required` dua-duanya menutup kasus ini; yang dijaga di
        // sini adalah pesannya, karena "items.required" tanpa pesan custom
        // muncul sebagai "The items field is required." — tidak informatif
        // untuk pengguna yang menekan tombol di keranjang kosong.
        $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items')
            ->assertJsonFragment(['Keranjang tidak boleh kosong.']);
    }
}
