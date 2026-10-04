<?php

namespace Tests\Feature;

use App\Domain\Shared\Models\AppSetting;
use App\Filament\Pages\HomepageContent;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Test untuk konten beranda.
 *
 * Tiga hal yang dijaga di sini:
 *
 *  1. **Bentuk respons `/api/v1/homepage` stabil.** Delapan section dengan
 *     key yang sudah pasti ada. Kalau satu key hilang, frontend yang
 *     mengakses `data.film.title` akan `undefined is not an object` — dan
 *     itu hanya ketahuan di browser, bukan di backend.
 *
 *  2. **Simpan dari panel admin menulis ke key yang benar.** Kalau key-nya
 *     bergeser, admin mengisi form dengan rapi dan perubahannya diam-diam
 *     tidak muncul di beranda.
 *
 *  3. **Placeholder tidak dibersihkan diam-diam.** Nilai `[... dari Admin]`
 *     harus tetap ada supaya owner tahu apa yang belum dikonfirmasi.
 */
class HomepageContentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        return User::create([
            'name' => 'Superadmin Uji',
            'email' => 'super-'.uniqid().'@uji.local',
            'password' => Hash::make('rahasia-uji-123'),
            'role' => 'superadmin',
        ]);
    }

    private function makeStaff(): User
    {
        return User::create([
            'name' => 'Staff Uji',
            'email' => 'staff-'.uniqid().'@uji.local',
            'password' => Hash::make('rahasia-uji-123'),
            'role' => 'staff',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Bentuk respons */
    /* ------------------------------------------------------------------ */

    /**
     * Delapan section harus selalu ada, masing-masing sebagai objek.
     *
     * `lookbook` satu-satunya yang berupa DAFTAR, dan itu disengaja: panelnya
     * memang bisa bertambah tanpa batas bawah.
     */
    public function test_respons_memiliki_delapan_section(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        foreach ([
            'benefit', 'film', 'lookbook', 'manifesto',
            'gear', 'promo', 'store', 'bulk',
        ] as $section) {
            $this->assertArrayHasKey(
                $section,
                $data,
                "Section `{$section}` hilang dari /api/v1/homepage.",
            );
        }
    }

    /**
     * Setiap section objek harus punya key yang sudah pasti ada, walau
     * nilainya `null`. Frontend boleh mengakses langsung tanpa cek.
     */
    public function test_key_section_terisi_pasti_ada_walau_kosong(): void
    {
        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $wajib = [
            'benefit' => ['title', 'body', 'voucher_code', 'voucher_note', 'cta_label'],
            'film' => [
                'video_path', 'poster_path', 'chapter', 'tag', 'caption', 'spec',
                'eyebrow', 'title', 'body', 'cta_primary_label', 'cta_primary_url', 'facts',
            ],
            'manifesto' => [
                'image_path', 'badge', 'eyebrow', 'title', 'body',
                'strip_left', 'strip_right', 'points',
            ],
            'gear' => ['eyebrow', 'title', 'cta_label'],
            'promo' => [
                'eyebrow', 'badges', 'title', 'body', 'deadline_note',
                'code_label', 'code', 'code_body',
            ],
            'store' => ['eyebrow', 'title', 'body', 'maps_label', 'address', 'opening_hours'],
            'bulk' => ['eyebrow', 'title', 'body', 'points', 'cta_label'],
        ];

        foreach ($wajib as $section => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $data[$section],
                    "`{$section}.{$key}` hilang — frontend akan gagal saat membacanya.",
                );
            }
        }

        $this->assertIsArray($data['lookbook']);
    }

    /**
     * Section `store` membaca alamat & jam dari key toko, bukan punya
     * duanya sendiri.
     */
    public function test_section_store_membaca_alamat_dari_key_toko(): void
    {
        AppSetting::put('store.address', 'Jalan Pariwisata Senaru');
        AppSetting::put('store.city', 'Senaru');

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $this->assertSame('Jalan Pariwisata Senaru', $data['store']['address']);
        $this->assertSame('Senaru', $data['store']['city']);

        // Jam buka tetap null selama OD-05 belum ditutup.
        $this->assertNull($data['store']['opening_hours']);
    }

    /**
     * Placeholder owner TIDAK boleh dibersihkan backend.
     *
     * Kalau dihapus, owner akan mengira kode vouchernya sudah benar
     * padahal masih kosong.
     */
    public function test_placeholder_tetap_utuh_di_respons(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $this->assertSame('[Kode Voucher dari Admin]', $data['benefit']['voucher_code']);
    }

    /**
     * String kosong harus jadi `null`, bukan `""`.
     *
     * `""` akan lolos ke `readString()` frontend dan merender kotak kosong
     * yang aneh; `null` bisa dipakai sebagai alasan untuk menyembunyikan.
     */
    public function test_nilai_kosong_jadi_null_bukan_string_kosong(): void
    {
        AppSetting::put('home.benefit', ['title' => '  ', 'voucher_code' => '']);

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $this->assertNull($data['benefit']['title']);
        $this->assertNull($data['benefit']['voucher_code']);
    }

    /**
     * Item lookbook yang kosong total harus dibuang, supaya frontend tidak
     * perlu merender panel kosong.
     */
    public function test_item_kosong_di_lookbook_dibuang(): void
    {
        AppSetting::put('home.lookbook', [
            ['title' => 'Panel isi', 'body' => 'Teks', 'image_path' => 'drive/x-1080.webp'],
            ['title' => '', 'body' => '', 'image_path' => ''],
        ]);

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $this->assertCount(1, $data['lookbook']);
        $this->assertSame('Panel isi', $data['lookbook'][0]['title']);
    }

    /* ------------------------------------------------------------------ */
    /* Panel admin */
    /* ------------------------------------------------------------------ */

    public function test_superadmin_bisa_membuka_halaman(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $this->actingAs($this->makeSuperAdmin())
            ->get(HomepageContent::getUrl())
            ->assertOk()
            ->assertSee('Pita Benefit');
    }

    public function test_staff_tidak_bisa_membuka_halaman(): void
    {
        $this->actingAs($this->makeStaff())
            ->get(HomepageContent::getUrl())
            ->assertForbidden();
    }

    /**
     * Menyimpan lewat form harus mendarat di key yang BENAR-BENAR dibaca
     * API, dan nilainya harus muncul di respons.
     */
    public function test_simpan_dan_langsung_muncul_di_api(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $su = $this->makeSuperAdmin();
        $this->actingAs($su);

        Livewire::test(HomepageContent::class)
            ->fillForm([
                'benefit' => [
                    'title' => 'GRATIS ONGKIR SELURUH INDONESIA',
                    'body' => 'Sebutkan kode saat checkout.',
                    'voucher_code' => 'JAKTO',
                    'voucher_note' => null,
                    'cta_label' => 'Konsultasi WhatsApp',
                ],
            ])
            ->callAction('submit')
            ->assertHasNoFormErrors();

        $data = $this->getJson('/api/v1/homepage')->assertOk()->json('data');

        $this->assertSame('GRATIS ONGKIR SELURUH INDONESIA', $data['benefit']['title']);
        $this->assertSame('JAKTO', $data['benefit']['voucher_code']);
    }

    /**
     * Repeater facts harus disimpan sebagai daftar label/value.
     */
    public function test_repeater_facts_tersimpan_sebagai_daftar(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(HomepageContent::class)
            ->fillForm([
                'film' => [
                    'facts' => [
                        ['label' => 'LOKASI', 'value' => 'Senaru'],
                        ['label' => 'KAMERA', 'value' => 'Aria 4K'],
                    ],
                ],
            ])
            ->callAction('submit')
            ->assertHasNoFormErrors();

        $facts = $this->getJson('/api/v1/homepage')->assertOk()->json('data.film.facts');

        $this->assertCount(2, $facts);
        $this->assertSame('LOKASI', $facts[0]['label']);
        $this->assertSame('Aria 4K', $facts[1]['value']);
    }

    /**
     * Daftar lookbook wajib punya gambar — panel tanpa foto hanya
     * menghasilkan kotak gelap besar di beranda.
     */
    public function test_panel_lookbook_wajib_punya_gambar(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(HomepageContent::class)
            ->fillForm([
                'lookbook' => [
                    ['eyebrow' => 'A', 'title' => 'Panel tanpa foto', 'body' => 'x', 'image_path' => null],
                ],
            ])
            ->callAction('submit')
            ->assertHasFormErrors(['lookbook.0.image_path']);
    }
}
