<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test untuk `GET /api/v1/banners`.
 *
 * Endpoint ini menggerakkan carousel hero beranda, jadi kegagalan di sini
 * terlihat langsung oleh pengunjung — banner kosong, atau banner yang
 * seharusnya disembunyikan malah tayang.
 *
 * Tiga kelompok aturan yang dijaga di sini:
 *   1. Banner yang dijadwalkan (aktif/nonaktif/mulai/berakhir) disaring benar.
 *   2. Field editorial gaya lookbook terkirim apa adanya.
 *   3. `image_alt` selalu ada — tidak boleh kosong, karena alt text kosong
 *      berarti gambar tidak terbaca mesin pencari maupun pembaca layar.
 */
class BannersApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Banner dengan isi minimal yang tetap valid.
     *
     * Hanya `title` yang wajib, sesuai definisi tabel. Sisanya sengaja
     * dikosongkan supaya test bisa memastikan normalisasi tidak mengarang
     * nilai untuk field yang memang belum diisi admin.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeBanner(array $attributes = []): Banner
    {
        return Banner::create(array_merge([
            'title' => 'Banner Uji',
            'disk' => 'public',
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    /**
     * PRD §3.1 AC: "Given Admin menonaktifkan sebuah banner, When pengunjung
     * membuka homepage, Then banner tersebut tidak muncul."
     */
    public function test_banner_nonaktif_tidak_pernah_ditampilkan(): void
    {
        $this->makeBanner(['title' => 'Aktif', 'sort_order' => 0]);
        $this->makeBanner(['title' => 'Nonaktif', 'is_active' => false, 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/banners');

        $response->assertOk();

        $judul = array_column($response->json('data'), 'title');
        $this->assertSame(['Aktif'], $judul);
    }

    /**
     * Banner yang belum mulai tayang tidak boleh muncul, walau `is_active`
     * bernilai `true` — itu sebabnya ada kolom `starts_at`.
     */
    public function test_banner_yang_belum_mulai_tayang_disembunyikan(): void
    {
        $this->makeBanner([
            'title' => 'Terjadwal',
            'starts_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * Banner yang sudah lewat masa tayang juga harus hilang, kalau tidak
     * promo lama akan terus tampil.
     */
    public function test_banner_yang_sudah_berakhir_disembunyikan(): void
    {
        $this->makeBanner([
            'title' => 'Berakhir',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * Banner tanpa batas waktu (kosongkan `starts_at`/`ends_at`) tayang terus.
     */
    public function test_banner_tanpa_batas_waktu_selalu_tayang(): void
    {
        $this->makeBanner(['title' => 'Selalu Tayang', 'starts_at' => null, 'ends_at' => null]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Selalu Tayang');
    }

    /**
     * Carousel beranda bergantung pada urutan ini: slide pertama yang tampil
     * adalah banner dengan `sort_order` terkecil.
     */
    public function test_banner_diurutkan_dari_sort_order(): void
    {
        $this->makeBanner(['title' => 'Ketiga', 'sort_order' => 20]);
        $this->makeBanner(['title' => 'Pertama', 'sort_order' => 10]);
        $this->makeBanner(['title' => 'Kedua', 'sort_order' => 15]);

        $judul = array_column($this->getJson('/api/v1/banners')->json('data'), 'title');

        $this->assertSame(['Pertama', 'Kedua', 'Ketiga'], $judul);
    }

    /**
     * Field editorial gaya lookbook harus terkirim apa adanya.
     *
     * Kolom ini manages di panel admin; kalau salah nama field di sini,
     * beranda kehilangan label sudut dan tombol kedua tanpa error apa pun —
     * gejalanya cuma "kelihatan kosong".
     */
    public function test_field_editorial_banner_terkirim_lengkap(): void
    {
        $this->makeBanner([
            'title' => 'Stories from the Land of Rinjani',
            'stamp_left' => 'FORM. 01 — RINJANI SERIES',
            'stamp_right' => 'SENARU / 601 MDPL',
            'eyebrow' => 'Koleksi Busana & Cenderamata',
            'cta_label' => 'Lihat Katalog',
            'cta_url' => '/produk',
            'cta2_label' => 'Cerita Senaru',
            'cta2_url' => '/cerita',
            'image_path' => 'banners/banner-01-1672.webp',
            'image_alt' => 'Tiga orang mengenakan koleksi busana.',
            'theme' => 'light',
            'overlay_opacity' => 45,
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data.0.stamp_left', 'FORM. 01 — RINJANI SERIES')
            ->assertJsonPath('data.0.stamp_right', 'SENARU / 601 MDPL')
            ->assertJsonPath('data.0.eyebrow', 'Koleksi Busana & Cenderamata')
            ->assertJsonPath('data.0.cta_label', 'Lihat Katalog')
            ->assertJsonPath('data.0.cta2_label', 'Cerita Senaru')
            ->assertJsonPath('data.0.cta2_url', '/cerita')
            ->assertJsonPath('data.0.image', 'banners/banner-01-1672.webp')
            ->assertJsonPath('data.0.theme', 'light')
            ->assertJsonPath('data.0.overlay_opacity', 45);
    }

    /**
     * `image_alt` kosong harus diisi judul, bukan dikirim `null`.
     *
     * Alasannya: kolomnya nullable, tapi gambar tanpa `alt` tidak terbaca
     * pembaca layar. Judul sudah minimal tersedia karena kolomnya NOT NULL,
     * jadi memakainya sebagai cadangan jauh lebih baik daripada `null`.
     */
    public function test_image_alt_kosong_diisi_dengan_judul(): void
    {
        $this->makeBanner(['title' => 'Judul Banner', 'image_alt' => null]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data.0.image_alt', 'Judul Banner');
    }

    /**
     * Banner yang belum diisi field editorial harus tetap terkirim utuh, dengan
     * `null` di field yang kosong — bukan dihapus dari respons.
     *
     * Kalau field hilang (key tidak ada), frontend yang membedakan
     * `null` vs `undefined` bisa salah fallback. Bentuk respons harus stabil.
     */
    public function test_banner_kosong_tetap_mengirim_semua_key(): void
    {
        $this->makeBanner(['title' => 'Minimal']);

        $slide = $this->getJson('/api/v1/banners')->assertOk()->json('data.0');

        foreach ([
            'id', 'title', 'subtitle', 'body',
            'stamp_left', 'stamp_right', 'eyebrow',
            'cta_label', 'cta_url', 'cta2_label', 'cta2_url',
            'image', 'image_alt', 'theme', 'overlay_opacity',
        ] as $key) {
            $this->assertArrayHasKey($key, $slide, "Key `{$key}` hilang dari respons banner.");
        }

        // Field yang belum diisi harus `null`, bukan string kosong.
        // String kosong akan lolos ke `readString()` frontend dan ter-render
        // sebagai kotak kosong yang aneh.
        $this->assertNull($slide['stamp_left']);
        $this->assertNull($slide['eyebrow']);
        $this->assertNull($slide['image']);
    }

    /**
     * `theme` fallback ke `light` dan `overlay_opacity` ke 45.
     *
     * Nilai bawaan ini dipilih supaya banner yang foto gelapnya gelap tetap
     * terbaca walau admin belum menyetel apa pun.
     */
    public function test_nilai_bawaan_tema_dan_scrim_selalu_ada(): void
    {
        $this->makeBanner(['title' => 'Tanpa Setelan']);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data.0.theme', 'light')
            ->assertJsonPath('data.0.overlay_opacity', 45);
    }

    /**
     * Katalog kosong harus menghasilkan array kosong, bukan `null`.
     *
     * Frontend melakukan `(banners ?? []).map(...)`; kalau backend mengirim
     * `null`, hasilnya tetap aman, tapi respons yang `null` menyiratkan
     * "kegagalan" bukan "belum ada banner".
     */
    public function test_katalog_banner_kosong_menghasilkan_array_kosong(): void
    {
        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
