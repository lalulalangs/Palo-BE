<?php

namespace App\Filament\Support;

use BackedEnum;

/**
 * Adapter kecil untuk mengubah enum domain menjadi opsi dropdown Filament.
 *
 * Kenapa tidak memakai `->options(ProductStatus::class)` secara langsung:
 * Filament hanya bisa membaca opsi dari enum yang mengimplementasikan kontrak
 * `HasLabel`. Enum domain PALORINJANI (ProductStatus, VoucherType, OrderStatus)
 * sengaja tidak bergantung pada Filament sama sekali — aturan arsitektur
 * melarang `Domain/` meng-import framework. Enum itu punya method `label()`
 * sendiri, jadi pemanggilannya ditulis di sini, di lapisan adapter.
 *
 * Contoh keluaran: ['draft' => 'Draf', 'active' => 'Aktif', ...]
 */
class EnumOptions
{
    /**
     * Ubah enum domain menjadi opsi dropdown Filament.
     *
     * @param  class-string<BackedEnum>  $enum  NAMA KELAS, bukan instance.
     * @return array<string, string> nilai => label Bahasa Indonesia.
     *
     * CATATAN: parameter ini sengaja `class-string`, bukan `BackedEnum`.
     * Semua pemanggil mengoper NAMA KELAS — `EnumOptions::from(ProductStatus::class)`
     * — karena itu yang dipakai, bukan objek enum-nya.
     *
     * Gejala kalau signature-nya `BackedEnum`: halaman produk & voucher gagal
     * render dengan
     *   "TypeError: EnumOptions::from(): Argument #1 ($enum) must be of type
     *    BackedEnum, string given"
     * yang hanya muncul saat Blade me-render filter — bukan saat `php -l`.
     */
    public static function from(string $enum): array
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $options[(string) $case->value] = self::labelFor($case);
        }

        return $options;
    }

    private static function labelFor(BackedEnum $case): string
    {
        // Semua enum domain punya `label(): string`. Kalau suatu saat ada yang
        // belum, jatuh ke nama case agar form tetap berfungsi.
        return method_exists($case, 'label')
            ? (string) $case->label()
            : (string) $case->name;
    }
}
