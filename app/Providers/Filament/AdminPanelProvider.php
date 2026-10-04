<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\GuestIntentsToday;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\PendingReconciliation;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StatsOverview;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel admin PALORINJANI.
 *
 * ===================================================================
 *  TUJUAN: panel admin harus TERBACA SEBAGAI BAGIAN DARI SITUS YANG SAMA
 * ===================================================================
 * Default-nya Filament memakai Amber + Inter + radius besar + mode gelap. Itu
 * terlihat seperti produk orang lain yang ditempel di belakang /
 * admin. Permintaan pemilik: samakan UI-nya dengan frontend.
 *
 * Semua token di bawah diambil dari `frontend/src/app/globals.css`
 * (`@theme`), bukan dikira ulang. Kalau token frontend berubah, daftar
 * di sini ikut berubah — dan kedua sisi harus berubah bersamaan, makanya nama
 * hex-nya ditulis persis seperti di `globals.css`.
 *
 * Yang SENGAJA tidak di-override:
 * - `info` dan `success` dibiarkan biru/hijau bawaan Filament. Depan
 *   tidak punya token untuk keduanya, dan memaksa `success` jadi hijau
 *  forests akan membuat "omzet naik" dan "stok aman" tidak bisa
 *   dibedakan di dashboard. Warna semantik tanpa padanan di frontend
 *   lebih baik dibiarkan daripada ditiru.
 * - `rounded-full` (avatar, badge status) dibiarkan bulat penuh,
 *   sama seperti frontend.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('PALORINJANI')
            // -------------------------------------------------------------
            // Font: BlinkMacSystemFont, sama seperti frontend.
            //
            // `provider: LocalFontProvider` itu WAJIB, bukan gaya penulisan.
            // Tanpa itu, `HasFont::getFontProvider()` mengembalikan
            // BunnyFontProvider setiap kali font family diisi, dan Filament
            // akan menyuntik <link> ke fonts.bunny.net untuk
            // "BlinkMacSystemFont" — permintaan yang pasti 404 dan
            // render-blocking, untuk font yang memang sudah ada di setiap
            // perangkat. `LocalFontProvider` dengan URL kosong mengembalikan
            // HTML kosong: cukup set `--font-family`, nol request jaringan.
            //
            // `monoFont` ikut disamakan karena keputusan pemilik: seluruh UI
            // pakai BlinkMacSystemFont, termasuk yang biasanya monospace
            // (kode produk, kode voucher). Storefront punya `--font-mono`
            // yang juga BlinkMacSystemFont.
            // -------------------------------------------------------------
            ->font('BlinkMacSystemFont', provider: LocalFontProvider::class)
            ->monoFont('BlinkMacSystemFont', provider: LocalFontProvider::class)
            // -------------------------------------------------------------
            // Warna. Hanya empat yang di-override; lihat docblock.
            //
            // `primary` — keluarga brand-forest. Shade 600 persis
            // `--color-brand-forest` (tombol CTA utama di frontend),
            // 500 = `--color-brand-pine`, 800 = `--color-brand-deep`.
            //
            // `gray` — netral kehijauan, bukan Zinc bawaan. Ini yang
            // mengatur latar halaman, garis pemisah, dan warna teks.
            // Di frontend: 50 = `--color-surface-paper`, 200 =
            // `--color-border-soft`, 300 = `--color-surface-dim`,
            // 500 = `--color-text-secondary`, 950 =
            // `--color-text-primary`.
            //
            // Kenapa teks utama ada di 950, bukan 900: Filament 4 memakai
            // `gray-950` untuk warna teks badan. Kalau 950 diisi warna yang
            // lebih gelap daripada `text-primary`, teks admin jadi lebih pekat
            // daripada teks storefront — persis yang tidak ingin terjadi.
            // Skala tetap monoton: 50 paling terang, 950 paling gelap.
            //
            // Dengan Zinc, panel admin jadi abu-abu dingin di samping
            // storefront yang hangat — terasa seperti dua situs.
            // -------------------------------------------------------------
            ->colors([
                'primary' => [
                    50 => '#eef5f1',
                    100 => '#d7e7de',
                    200 => '#b0cdbe',
                    300 => '#86b09a',
                    400 => '#5c9179',
                    500 => '#2c7357', // --color-brand-pine
                    600 => '#1f6048', // --color-brand-forest  ← CTA utama
                    700 => '#1a4d3b',
                    800 => '#123f31', // --color-brand-deep
                    900 => '#0d2d23',
                    950 => '#061a14',
                ],
                'gray' => [
                    50 => '#f7f5ef', // --color-surface-paper  ← latar panel
                    100 => '#eeece4',
                    200 => '#d8e1d9', // --color-border-soft    ← garis
                    300 => '#b9c9c0', // --color-surface-dim
                    400 => '#8a9d94',
                    500 => '#62766d', // --color-text-secondary
                    600 => '#4f6158',
                    700 => '#3d4d45',
                    800 => '#33423a',
                    900 => '#28362f',
                    950 => '#1e2c25', // --color-text-primary    ← teks utama
                ],
                'danger' => [
                    50 => '#fff5f4',
                    100 => '#ffe8e6',
                    200 => '#ffd4d0',
                    300 => '#ffb4ad',
                    400 => '#ff8377',
                    500 => '#f65445',
                    600 => '#ba1a1a', // --color-feedback-error
                    700 => '#9c1616',
                    800 => '#7f1717',
                    900 => '#6b1818',
                    950 => '#3a0808',
                ],
                'warning' => [
                    50 => '#fbf5ee',
                    100 => '#f5e7d6',
                    200 => '#e9caab',
                    300 => '#dba878',
                    400 => '#cb8a4f',
                    500 => '#b1743a',
                    600 => '#7c572a', // --color-feedback-warn
                    700 => '#6a4a25',
                    800 => '#573d20',
                    900 => '#48311b',
                    950 => '#291a0e',
                ],
            ])
            // -------------------------------------------------------------
            // Mode gelap DIMATIKAN, dan ini keputusan sadar.
            //
            // Frontend tidak punya mode gelap sama sekali — tidak ada token
            // gelap, tidak ada `prefers-color-scheme`. Kalau switcher
            // dibiarkan, Filament memakai palet gelap default-nya (abu-abu
            // hampir hitam) yang tidak ada kaitannya dengan PALORINJANI, jadi
            // -panel akan punya dua identitas visual. Satu identitas lebih
            // jujur daripada dua yang tidak konsisten.
            //
            // Untuk menghidupkan kembali nanti: hapus baris ini. Warna di
            // `->colors()` tidak perlu diubah.
            // -------------------------------------------------------------
            ->darkMode(false)
            // -------------------------------------------------------------
            // Radius: turun satu langkah dari bawaan Filament supaya sudut
            // panel lebih tajam, mengikuti `--radius-*` di `globals.css`.
            // Token ini ditulis di `resources/css/filament/admin/theme.css`
            // karena Tailwind, bukan di sini.
            // -------------------------------------------------------------
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Batas lebar konten. `max-w-6xl` bawaan Filement membuat tabel
            // jauh dari tepi di layar 1920px; `full` memakai lebar penuh yang
            // dipakai storefront (container 1400px + padding).
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,

                // =============================================================
                // Urutan widget di dashboard mengikuti urutan operasional:
                // berapa yang terjual, grafik trennya, apa yang perlu segera
                // diisi ulang, apa yang butuh keputusan manusia, lalu KPI guest.
                // =============================================================
                StatsOverview::class,
                RevenueChart::class,
                LowStockTable::class,
                PendingReconciliation::class,
                GuestIntentsToday::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
