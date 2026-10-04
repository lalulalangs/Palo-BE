<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Models\AppSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Konten beranda yang dikelola admin.
 *
 * ===================================================================
 *  KENAPA ENDPOINT INI ADA
 * ===================================================================
 * Beranda punya delapan section, dan tiap section butuh beberapa field
 * sekaligus (judul, subjudul, tombol, gambar). Kalau frontend mengambilnya
 * satu per satu dari `GET /brand`, hasilnya sia-sia: delapan permintaan
 * HTTP untuk satu render.
 *
 * Semua konten dikumpulkan di sini supaya beranda hanya melakukan SATU
 * fetch, dan supaya frontend tidak perlu tahu daftar key mana yang berubah.
 *
 * ===================================================================
 *  BENTUK RESPONS
 * ===================================================================
 * Setiap section dikembalikan sebagai OBJEK dengan key yang sudah pasti ada,
 * dengan `null` untuk yang belum diisi. Bentuknya tidak pernah berubah,
 * sehingga frontend bisa selalu mengakses `data.film.title` tanpa cek dulu
 * dan tidak akan gagal kalau suatu key tidak ada.
 *
 * Section yang bergantung pada katalog (`lookbook`, `gear`) tetap ikut
 * dikembalikan, tapi frontend yang memutuskan menampilkannya atau tidak
 * berdasarkan data produk — supaya keputusan itu tidak terpecah antara
 * backend dan frontend.
 *
 * ===================================================================
 *  TEMPLATE PLACEHOLDER
 * ===================================================================
 * Nilai yang masih berbentuk `[... dari Admin]` dikirim apa adanya. Backend
 * TIDAK bohongkan mengarang nilai pengganti — placeholder di depan mata
 * diingat lebih cepat daripada teks yang terlihat meyakinkan tapi salah.
 */
class HomepageController extends Controller
{
    /**
     * GET /api/v1/homepage
     *
     * (Tidak ada parameter — endpoint ini hanya membaca, jadi tidak perlu
     * rate limit dan tidak butuh token.)
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'benefit' => $this->benefit(),
                'film' => $this->film(),
                'lookbook' => $this->lookbook(),
                'manifesto' => $this->manifesto(),
                'gear' => $this->gear(),
                'promo' => $this->promo(),
                'store' => $this->store(),
                'bulk' => $this->bulk(),
            ],
        ]);
    }

    /**
     * Pita benefit + kode voucher di bawah hero.
     *
     * @return array<string, mixed>
     */
    private function benefit(): array
    {
        $v = $this->raw('home.benefit');

        return [
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'voucher_code' => $this->str($v, 'voucher_code'),
            'voucher_note' => $this->str($v, 'voucher_note'),
            'cta_label' => $this->str($v, 'cta_label'),
        ];
    }

    /**
     * Opening film (video vertikal + visual essay).
     *
     * @return array<string, mixed>
     */
    private function film(): array
    {
        $v = $this->raw('home.film');

        return [
            'video_path' => $this->str($v, 'video_path'),
            'poster_path' => $this->str($v, 'poster_path'),
            'chapter' => $this->str($v, 'chapter'),
            'tag' => $this->str($v, 'tag'),
            'caption' => $this->str($v, 'caption'),
            'spec' => $this->str($v, 'spec'),
            'eyebrow' => $this->str($v, 'eyebrow'),
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'cta_primary_label' => $this->str($v, 'cta_primary_label'),
            'cta_primary_url' => $this->str($v, 'cta_primary_url'),
            'facts' => $this->listOf($v, 'facts', ['label', 'value']),
        ];
    }

    /**
     * Lookbook split — daftar, bukan objek tunggal.
     *
     * @return list<array<string, mixed>>
     */
    private function lookbook(): array
    {
        return $this->listOf(
            $this->raw('home.lookbook'),
            null,
            ['eyebrow', 'title', 'body', 'image_path'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifesto(): array
    {
        $v = $this->raw('home.manifesto');

        return [
            'image_path' => $this->str($v, 'image_path'),
            'badge' => $this->str($v, 'badge'),
            'eyebrow' => $this->str($v, 'eyebrow'),
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'strip_left' => $this->str($v, 'strip_left'),
            'strip_right' => $this->str($v, 'strip_right'),
            'points' => $this->listOf($v, 'points', ['title', 'body']),
            'cta_primary_label' => $this->str($v, 'cta_primary_label'),
            'cta_primary_url' => $this->str($v, 'cta_primary_url'),
            'cta_secondary_label' => $this->str($v, 'cta_secondary_label'),
            'cta_secondary_url' => $this->str($v, 'cta_secondary_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gear(): array
    {
        $v = $this->raw('home.gear');

        return [
            'eyebrow' => $this->str($v, 'eyebrow'),
            'title' => $this->str($v, 'title'),
            'cta_label' => $this->str($v, 'cta_label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promo(): array
    {
        $v = $this->raw('home.promo');

        return [
            'eyebrow' => $this->str($v, 'eyebrow'),
            'badges' => array_values(array_filter(
                is_array($v['badges'] ?? null) ? $v['badges'] : [],
                'is_string',
            )),
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'deadline_note' => $this->str($v, 'deadline_note'),
            'code_label' => $this->str($v, 'code_label'),
            'code' => $this->str($v, 'code'),
            'code_body' => $this->str($v, 'code_body'),
            'cta_primary_label' => $this->str($v, 'cta_primary_label'),
            'cta_secondary_label' => $this->str($v, 'cta_secondary_label'),
            'cta_secondary_url' => $this->str($v, 'cta_secondary_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function store(): array
    {
        $v = $this->raw('home.store');

        return [
            'eyebrow' => $this->str($v, 'eyebrow'),
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'maps_label' => $this->str($v, 'maps_label'),

            // Alamat & jam TIDAK diulang di sini. Dipakai langsung dari key
            // toko supaya tidak ada dua sumber untuk satu nilai.
            'address' => AppSetting::get('store.address'),
            'city' => AppSetting::get('store.city'),

            // Jam buka tetap `null` selama OD-05 belum ditutup — sama
            // seperti `GET /brand`. Frontend menampilkan catatan menunggu
            // konfirmasi, bukan jam karangan.
            'opening_hours' => AppSetting::get('store.opening_hours'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bulk(): array
    {
        $v = $this->raw('home.bulk');

        return [
            'eyebrow' => $this->str($v, 'eyebrow'),
            'title' => $this->str($v, 'title'),
            'body' => $this->str($v, 'body'),
            'points' => array_values(array_filter(
                is_array($v['points'] ?? null) ? $v['points'] : [],
                'is_string',
            )),
            'cta_label' => $this->str($v, 'cta_label'),
        ];
    }

    /**
     * Nilai mentah satu key `home.*`.
     *
     * Kembalikan array kosong kalau key belum ada, supaya pemanggil tidak
     * perlu cek `null` sebelum membaca sub-key.
     *
     * @return array<string, mixed>
     */
    private function raw(string $key): array
    {
        $value = AppSetting::get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Baca satu sub-key sebagai string bersih.
     *
     * String kosong/null dinormalisasi jadi `null` supaya frontend bisa
     * membedakan "belum diisi" dari "ada isinya tapi kosong".
     *
     * @param  array<string, mixed>  $source
     */
    private function str(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Baca daftar item dan pastikan tiap item punya seluruh key yang diminta.
     *
     * Selector yang tetap dipakai frontend dengan aman walau admin menambah
     * field baru: field yang tidak ada selalu `null`, tidak pernah
     * `undefined`.
     *
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function listOf(array $source, ?string $key, array $keys): array
    {
        $items = $key === null ? $source : ($source[$key] ?? null);

        if (! is_array($items)) {
            return [];
        }

        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($keys as $k) {
                $row[$k] = $this->str($item, $k);
            }

            // Item tanpa isi sama sekali tidak berguna — skip, supaya
            // frontend tidak perlu render kartu kosong.
            if (collect($row)->filter()->isEmpty()) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }
}
