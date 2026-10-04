<?php

namespace Tests\Feature;

use App\Domain\Shared\Models\AdminActivityLog;
use App\Domain\Shared\Models\AppSetting;
use App\Filament\Pages\SocialMediaSettings;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Test untuk kanal media sosial.
 *
 * Dua hal yang diuji di sini, keduanya mudah rusak tanpa disadari:
 *
 *  1. **Bentuk API.** `null` berarti "admin belum mengisi" dan `string`
 *     berarti "sudah diisi". Frontform memakai perbedaan itu untuk
 *     MENYEMBUNYIKAN ikon. Kalau bentuknya berubah diam-diam, ikon sosial
 *     media akan muncul menuju tautan kosong — gejalanya "ada ikon tapi
 *     diklik tidak kemana", tanpa error apa pun.
 *
 *  2. **Otorisasi & normalisasi.** `whatsapp.cs_number` juga dibaca checkout
 *     guest (PRD §3.11). Kalau panel admin menulisnya tanpa normalisasi,
 *     nomor "0812-3456-7890" akan membuat seluruh tautan `wa.me` gagal.
 */
class SocialMediaTest extends TestCase
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
    /* API */
    /* ------------------------------------------------------------------ */

    /**
     * Sebelum admin mengisi apa pun, semua kanal harus `null`.
     *
     * Ini yang membuat footer tidak menampilkan ikon mati.
     */
    public function test_kanal_kosong_kirim_null_bukan_string_kosong(): void
    {
        $this->seed(DatabaseSeeder::class);

        $data = $this->getJson('/api/v1/brand')->assertOk()->json('data');

        $this->assertNull($data['instagram_url']);
        $this->assertNull($data['facebook_url']);
        $this->assertNull($data['tiktok_url']);
        $this->assertNull($data['whatsapp']);
    }

    /**
     * Setelah diisi, nilainya harus sampai ke API apa adanya.
     */
    public function test_tautan_terisi_terkirim_ke_api(): void
    {
        AppSetting::put('brand.instagram_url', 'https://instagram.com/palomountain');
        AppSetting::put('brand.facebook_url', 'https://facebook.com/palomountain');
        AppSetting::put('brand.tiktok_url', 'https://tiktok.com/@palomountain');
        AppSetting::put('whatsapp.cs_number', '6281234567890');

        $data = $this->getJson('/api/v1/brand')->assertOk()->json('data');

        $this->assertSame('https://instagram.com/palomountain', $data['instagram_url']);
        $this->assertSame('https://facebook.com/palomountain', $data['facebook_url']);
        $this->assertSame('https://tiktok.com/@palomountain', $data['tiktok_url']);
        $this->assertSame('6281234567890', $data['whatsapp']);
    }

    /**
     * Kunci yang harus ada di respons.
     *
     * Tanpa test ini, menghapus satu `AppSetting::get()` dari controller
     * hanya akan "!Undefined array key" di production log, sementara frontend
     * diam-diam berhenti menampilkan ikon.
     */
    public function test_respons_memiliki_seluruh_kunci_kanal(): void
    {
        $data = $this->getJson('/api/v1/brand')->assertOk()->json('data');

        foreach (['instagram_url', 'instagram_handle', 'facebook_url', 'tiktok_url', 'whatsapp'] as $key) {
            $this->assertArrayHasKey($key, $data, "Kunci `{$key}` hilang dari /api/v1/brand.");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Normalisasi nomor WhatsApp */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nomorWaProvider(): array
    {
        return [
            'format internasional' => ['6281234567890', '6281234567890'],
            'dengan tanda plus' => ['+62 812-3456-7890', '6281234567890'],
            'format lokal 08' => ['0812 3456 7890', '6281234567890'],
            'pakai tanda hubung' => ['0812-3456-7890', '6281234567890'],
            'pakai titik' => ['0812.3456.7890', '6281234567890'],
        ];
    }

    /**
     * `wa.me` hanya menerima angka saja, dan nomor Indonesia yang ditulis
     * lokal harus diubah ke awalan 62.
     */
    #[DataProvider('nomorWaProvider')]
    public function test_nomor_whatsapp_dinormalisasi(string $masuk, string $harapan): void
    {
        $this->assertSame($harapan, SocialMediaSettings::normalisasiNomorWa($masuk));
    }

    /**
     * Nomor non-Indonesia tidak boleh di-terjemahkan.
     *
     * Kalau `+1 202 555 0147` ikut jadi `62…`, semua buyer luar negeri akan
     * diarahkan ke nomor yang salah.
     */
    public function test_nomor_luar_indonesia_tidak_diubah(): void
    {
        $this->assertSame('12025550147', SocialMediaSettings::normalisasiNomorWa('+1 202-555-0147'));
    }

    /* ------------------------------------------------------------------ */
    /* Halaman admin */
    /* ------------------------------------------------------------------ */

    public function test_halaman_media_sosial_bisa_diakses_superadmin(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->get(SocialMediaSettings::getUrl())
            ->assertOk()
            ->assertSee('Tautan Media Sosial');
    }

    /**
     * Staff boleh membaca pengaturan tapi tidak boleh mengubahnya
     * (AppSettingPolicy::update).
     */
    public function test_staff_tidak_bisa_membuka_halaman_media_sosial(): void
    {
        $this->actingAs($this->makeStaff())
            ->get(SocialMediaSettings::getUrl())
            ->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        // Filament membalas REDIRECT ke form login, bukan 401. Makanya yang
        // diuji adalah arah redirect-nya: kalau tidak ada tujuan, berarti
        // halamannya justru terbuka untuk tamu.
        $response = $this->get(SocialMediaSettings::getUrl());

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    /**
     * Menyimpan lewat form harus menulis ke key yang BENAR-BENAR dibaca
     * aplikasi — bukan key turunan milik form saja.
     */
    public function test_simpan_menulis_ke_key_yang_dibaca_domain(): void
    {
        $su = $this->makeSuperAdmin();

        $this->actingAs($su);

        Livewire::test(SocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'https://instagram.com/palomountain',
                'facebook_url' => 'https://facebook.com/palomountain',
                'tiktok_url' => 'https://tiktok.com/@palomountain',
                'whatsapp_number' => '0812-3456-7890',
            ])
            ->callAction('submit')
            ->assertHasNoFormErrors();

        $this->assertSame('https://instagram.com/palomountain', AppSetting::get('brand.instagram_url'));
        $this->assertSame('https://facebook.com/palomountain', AppSetting::get('brand.facebook_url'));
        $this->assertSame('https://tiktok.com/@palomountain', AppSetting::get('brand.tiktok_url'));

        // PENTING: ini key yang sama dengan yang dibaca checkout guest, dan
        // sudah ternormalisasi ke format `wa.me`.
        $this->assertSame('6281234567890', AppSetting::get('whatsapp.cs_number'));
    }

    /**
     * Kanal yang dikosongkan harus menjadi `null`, bukan `""`.
     *
     * String kosong akan lolos ke `readString()` frontend, sehingga ikon tetap
     * dirender dengan `href=""` — yang kalau diklik memuat ulang halaman.
     */
    public function test_kanal_dikosongkan_jadi_null_bukan_string_kosong(): void
    {
        AppSetting::put('brand.facebook_url', 'https://facebook.com/lama');

        $su = $this->makeSuperAdmin();

        $this->actingAs($su);

        Livewire::test(SocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'https://instagram.com/palomountain',
                'facebook_url' => '',
                'tiktok_url' => null,
                'whatsapp_number' => '6281234567890',
            ])
            ->callAction('submit')
            ->assertHasNoFormErrors();

        $this->assertNull(AppSetting::get('brand.facebook_url'));
        $this->assertNull(AppSetting::get('brand.tiktok_url'));
    }

    /**
     * Menyimpan nilai yang sama sekali tidak boleh menambah baris audit.
     */
    public function test_simpan_nilai_sama_tidak_membuat_jejak_audit(): void
    {
        AppSetting::put('brand.instagram_url', 'https://instagram.com/palomountain');

        $su = $this->makeSuperAdmin();
        $sebelum = AdminActivityLog::count();

        $this->actingAs($su);

        Livewire::test(SocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'https://instagram.com/palomountain',
                'facebook_url' => null,
                'tiktok_url' => null,
                'whatsapp_number' => null,
            ])
            ->callAction('submit')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $sebelum,
            AdminActivityLog::count(),
            'Menyimpan nilai yang tidak berubah seharusnya tidak menambah jejak audit.',
        );
    }

    /**
     * URL WA wajib ditolak oleh validasi `->url()`, kalau tidak admin bisa
     * menyimpan tautan rusak tanpa ada yang memberi tahu.
     */
    public function test_url_kanal_harus_valid(): void
    {
        $su = $this->makeSuperAdmin();

        $this->actingAs($su);

        Livewire::test(SocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'bukan-url',
                'facebook_url' => 'https://facebook.com/palomountain',
                'tiktok_url' => null,
                'whatsapp_number' => null,
            ])
            ->callAction('submit')
            ->assertHasFormErrors(['instagram_url']);
    }
}
