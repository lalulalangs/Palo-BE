<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga registrasi rate limiter.
 *
 * Kenapa test ini perlu ada: `RateLimiter::for()` mendaftarkan limiter di
 * dalam container. Kalau blok registrasinya terhapus, TIDAK ADA error saat
 * boot, tidak ada error di `route:list`, dan `php -l` tetap lolos.
 *
 * Error baru muncul saat middleware benar-benar dijalankan — artinya saat
 * ada request sungguhan ke endpoint yang dilindungi. Kalau itu terjadi di
 * produksi, seluruh traffic authenticated akan kena HTTP 500.
 *
 * Test ini membuat penghapusan serupa terdeteksi saat test berjalan, bukan
 * ketahuan saat user sudah mengalaminya di produksi.
 */
class RateLimiterRegistrationTest extends TestCase
{
    #[DataProvider('namaLimiter')]
    public function test_limiter_terdaftar(string $name): void
    {
        $this->assertNotNull(
            RateLimiter::limiter($name),
            "Rate limiter '{$name}' belum terdaftar. Setiap limiter yang dipakai di route harus punya RateLimiter::for() di AppServiceProvider::boot().",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namaLimiter(): array
    {
        return [
            'api' => ['api'],
            'login' => ['login'],
            'checkout' => ['checkout'],
            'guest' => ['guest'],
        ];
    }

    /**
     * Cross-check: nama limiter yang dirujuk route HARUS ada yang terdaftar.
     *
     * Ini yang menutup celah di atas. Kalau ada route memakai `throttle:xyz`
     * sementara `xyz` tidak terdaftar, test ini gagal — bukan production.
     */
    public function test_semua_limiter_yang_dirujuk_route_terdaftar(): void
    {
        $referenced = [];

        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            foreach ($middleware as $name) {
                if (str_starts_with($name, 'throttle:')) {
                    $referenced[] = explode(':', $name, 2)[1];
                }
            }
        }

        $referenced = array_values(array_unique($referenced));

        $this->assertNotEmpty($referenced, 'Seharusnya ada route yang memakai throttle.');

        foreach ($referenced as $name) {
            // `throttle:60,1` adalah spesifikasi INLINE (jumlah, menit), bukan
            // nama limiter bernama. Yang harus terdaftar hanya nama tanpa koma.
            // Tanpa filter ini, test akan salah melaporkan limiter "tidak
            // terdaftar" padahal spesifikasi inline selalu valid.
            if (str_contains($name, ',')) {
                continue;
            }

            $this->assertNotNull(
                RateLimiter::limiter($name),
                "Route memakai throttle:{$name} tetapi limiter itu tidak terdaftar.",
            );
        }
    }
}
