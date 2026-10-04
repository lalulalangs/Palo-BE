<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Katalog aset yang dihasilkan `scripts/prepare_drive_assets.py`.
 *
 * ===================================================================
 *  KENAPA BACKEND BACA MANIFEST
 * ===================================================================
 * Halaman admin perlu menampilkan PILIHAN gambar, bukan kolom teks bebas.
 * Kalau admin mengetik path-nya sendiri, salah ketik satu karakter membuat
 * gambar hilang — dan gejalanya di beranda cuma "kotak kosong tanpa foto",
 * tanpa error di mana pun. Itu jenis kesalahan yang mahal dicari.
 *
 * Daftar pilihan diambil dari `drive_asset/manifest.json`, yaitu berkas yang
 * ditulis oleh pipeline. Jadi pilihan di panel admin dan berkas yang benar-benar
 * ada di object storage tidak mungkin berbeda.
 *
 * Manifest dianggap opsional: kalau pipeline belum dijalankan, method
 * mengembalikan array kosong dan panel admin tetap bisa dibuka (dengan
 * pilihan gambar kosong) alih-alih error 500.
 *
 * Letak manifest dikonfigurasi lewat `config('palorinjani.drive_assets')`
 * supaya path-nya tidak tersebar di beberapa file.
 */
class DriveAssets
{
    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $path = config('palorinjani.drive_assets.manifest_path');

        if (! is_string($path) || ! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Opsi untuk `Select` gambar: `path` => `slot (WxH)`.
     *
     * Varian paling lebar didahulukan supaya daftar yang muncul
     * merepresentasikan aset kualitas tertinggi, dan thumbnail di akhir.
     *
     * @return array<string, string>
     */
    public static function imageOptions(): array
    {
        $opsi = [];

        foreach (self::manifest()['gambar'] ?? [] as $item) {
            if (! is_array($item) || ! isset($item['slot'])) {
                continue;
            }

            $slot = (string) $item['slot'];
            $lebar = [];
            $tinggi = null;

            foreach ($item['variants'] ?? [] as $v) {
                if (! is_array($v) || ! isset($v['path'])) {
                    continue;
                }
                $lebar[] = $v;
                $tinggi ??= (int) ($v['height'] ?? 0);
            }

            // `strnatcmp` supaya varian 1080 mengalah(width) 480 secara
            // benar. Perbandingan leksikal akan salah di sini.
            // PerbandinganNumerik, bukan leksikal: "1080" < "480" secara
            // leksikal, padahal 1080 JAUH lebih lebar dari 480.
            usort($lebar, static fn (array $a, array $b): int => ((int) ($b['width'] ?? 0)) <=> ((int) ($a['width'] ?? 0)));

            foreach ($lebar as $v) {
                $opsi[(string) $v['path']] = sprintf(
                    '%s — %dx%d',
                    $slot,
                    (int) ($v['width'] ?? 0),
                    (int) ($v['height'] ?? 0),
                );
            }
        }

        ksort($opsi);

        return $opsi;
    }

    /**
     * Opsi untuk `Select` video: `path` => label.
     *
     * @return array<string, string>
     */
    public static function videoOptions(): array
    {
        $video = self::manifest()['video'] ?? null;

        if (! is_array($video) || ! isset($video['path'])) {
            return [];
        }

        return [
            (string) $video['path'] => sprintf(
                '%s — %dx%d, %s detik',
                (string) ($video['slot'] ?? 'video'),
                (int) ($video['width'] ?? 0),
                (int) ($video['height'] ?? 0),
                (int) ($video['duration_seconds'] ?? 0),
            ),
        ];
    }

    /**
     * Opsi untuk `Select` poster video.
     *
     * @return array<string, string>
     */
    public static function posterOptions(): array
    {
        $video = self::manifest()['video'] ?? null;

        if (! is_array($video) || ! isset($video['poster_path'])) {
            return [];
        }

        return [
            (string) $video['poster_path'] => sprintf(
                'Poster — %dx%d',
                (int) ($video['poster_width'] ?? 0),
                (int) ($video['poster_height'] ?? 0),
            ),
        ];
    }

    /**
     * Durasi video dalam detik, atau `null` kalau video belum diproses.
     */
    public static function videoDuration(): ?int
    {
        $video = self::manifest()['video'] ?? null;

        if (! is_array($video) || ! isset($video['duration_seconds'])) {
            return null;
        }

        return (int) $video['duration_seconds'];
    }
}
