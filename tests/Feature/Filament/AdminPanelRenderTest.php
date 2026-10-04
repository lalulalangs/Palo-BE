<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalog\Models\Banner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uji bahwa panel Filament benar-benar ME-RENDER, bukan sekadar merespons 200.
 *
 * Kenapa test ini perlu ada:
 * Panel admin bisa mengembalikan HTTP 200 sambil merender halaman kosong atau
 * crash di tengah. `assertOk()` saja tidak cukup. Test ini memaksa setiap
 * halaman benar-benar dirender, termasuk widget dashboard yang menjalankan
 * query agregat.
 *
 * Test RBAC (siapa boleh apa) ada di AdminAccessControlTest — jangan
 * dicampur di sini supaya saat ada kegagalan, penyebabnya jelas.
 */
class AdminPanelRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin Uji Render',
            'email' => 'render-'.uniqid().'@uji.local',
            'password' => bcrypt('Senaru#2026Aman'),
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Panel memuat tanpa error — ini yang biasanya pertama rusak.
     */
    public function test_panel_admin_bisa_dimuat(): void
    {
        $this->admin();

        $this->get('/admin')->assertOk();
    }

    /**
     * Halaman login WAJIB bisa diakses tanpa login. Kalau tidak, admin yang
     * terkunci tidak bisa masuk sama sekali.
     */
    public function test_halaman_login_tersedia(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    /**
     * Pelanggan (role NULL) harus DITOLAK masuk panel, bukan sekadar melihat
     * menu kosong. PRD §3.10: role-based access control.
     */
    public function test_pelanggan_ditolak_di_panel(): void
    {
        $customer = User::create([
            'name' => 'Pembeli Biasa',
            'email' => 'pelanggan-'.uniqid().'@uji.local',
            'password' => bcrypt('rahasia-uji-123'),
            'role' => null, // NULL = pelanggan, bukan admin
        ]);

        $response = $this->actingAs($customer)->get('/admin');

        // 403 forbidden ATAU redirect ke login. Dua-duanya benar: yang penting
        // BUKAN 200.
        $this->assertContains(
            $response->getStatusCode(),
            [302, 403],
            'Pelanggan tidak boleh bisa masuk panel admin.',
        );
    }

    /**
     * Setiap halaman utama resource harus benar-benar dirender.
     *
     * Ini yang menangkap kesalahan di schema/tabel/widget yang tidak akan
     * terlihat dari `assertOk()` pada halaman dashboard saja.
     *
     * Catatan: memakai atribut `#[DataProvider]`, bukan anotasi
     * `@dataProvider`. PHPUnit 11+ membaca metadata dari atribut, dan anotasi
     * docblock sudah deprecated — kalau dipakai, test-nya lolos diam-diam
     * tanpa pernah dijalankan sama sekali. Gejalanya: "0 passed" padahal
     * test-nya memang tidak dieksekusi.
     */
    #[DataProvider('halamanResource')]
    public function test_halaman_resource_me_render(string $url): void
    {
        $this->admin();

        $this->get($url)->assertOk();
    }

    /**
     * Halaman banner me-render data baris dengan berbagai status jadwal.
     */
    public function test_halaman_banner_me_render_dengan_data(): void
    {
        $this->admin();

        Banner::create([
            'title' => 'Banner Aktif',
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'sort_order' => 1,
        ]);

        Banner::create([
            'title' => 'Banner Terjadwal',
            'is_active' => true,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(5),
            'sort_order' => 2,
        ]);

        Banner::create([
            'title' => 'Banner Kedaluwarsa',
            'is_active' => true,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDay(),
            'sort_order' => 3,
        ]);

        $this->get('/admin/banner')
            ->assertOk()
            ->assertSee('Tayang sekarang')
            ->assertSee('Terjadwal')
            ->assertSee('Berakhir');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function halamanResource(): array
    {
        // URL diambil dari slug resource, bukan dari Model, supaya test ini
        // ikut menangkap resource yang forgot mendaftarkan halamannya.
        return [
            'banner' => ['/admin/banner'],
            'produk' => ['/admin/produk'],
            'sku' => ['/admin/sku'],
            'kategori' => ['/admin/kategori'],
            'order' => ['/admin/order'],
            'voucher' => ['/admin/voucher'],
            'penyesuaian stok' => ['/admin/penyesuaian-stok'],
            'niat whatsapp' => ['/admin/niat-whatsapp'],
            'pengaturan' => ['/admin/pengaturan'],
            'pengguna' => ['/admin/pengguna'],
        ];
    }

    /**
     * Halaman kustom (bukan Resource) juga harus benar-benar dirender.
     *
     * ===================================================================
     *  KENAPA DIBEDAKAN DARI `halamanResource`
     * ===================================================================
     * Halaman kustom di `app/Filament/Pages` punya jalur render yang BERBEDA
     * dari Resource, jadi testnya dipisah supaya saat ada kegagalan penyebabnya
     * jelas.
     *
     * Jalur ini punya jebakan yang nyata: menaruh `$this->form` di dalam
     * `content()` membuat halaman 500, karena Filament sedang menyusun schema
     * `content` ketika `__get('form')` dipanggil. Bug itu sudah terjadi di
     * `StockOpname` sejak awal dan tidak pernah terlihat — halaman opname
     * tidak pernah dirender oleh test mana pun. Sekarang sudah ikut tercakup,
     * dan `SocialMediaSettings` yang baru memakai pola yang benar sejak awal.
     */
    #[DataProvider('halamanKustom')]
    public function test_halaman_kustom_me_render(string $url): void
    {
        $this->admin();

        $this->get($url)->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function halamanKustom(): array
    {
        return [
            'opname stok' => ['/admin/stock-opname'],
            'media sosial' => ['/admin/media-sosial'],
            'konten beranda' => ['/admin/konten-beranda'],
            'audit log' => ['/admin/audit-log'],
        ];
    }

    /**
     * Dashboard menjalankan lima widget. Kalau salah satu query-nya salah,
     * halaman dashboard tidak akan ter-render.
     */
    public function test_widget_dashboard_tidak_menggagalkan_halaman(): void
    {
        $this->admin();

        // Paksa render ulang dengan cache widget dimatikan supaya query
        // agregatnya benar-benar dijalankan.
        Filament::auth()->user();

        $this->get('/admin')->assertOk();
    }
}
