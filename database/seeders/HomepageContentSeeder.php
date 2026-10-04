<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\AppSetting;
use Illuminate\Database\Seeder;

/**
 * Konten beranda yang dikelola admin.
 *
 * ===================================================================
 *  KENAPA DELAPAN KUNCI BUKAN PULUHAN KUNCI LEPAS
 * ===================================================================
 * Beranda punya delapan section, dan tiap section punya beberapa field
 * yang selalu booked sebagai satu kesatuan (judul + subjudul + tombolnya).
 * Kalau tiap field jadi key sendiri, `app_settings` akan berisi puluhan
 * baris yang hanya bermakna sebagai kelompok — dan `AppSettingResource` akan
 * menampilkannya sebagai daftar tak berstruktur yang mustahil dibaca admin.
 *
 * Jadi tiap section = satu key, nilainya objek. Delapan key, delapan
 * section, dan form admin memetakan 1:1 tanpa ada field yang menggantung.
 *
 * Kolom `value` sudah `json`, jadi objek & array tersimpan apa adanya.
 *
 * ===================================================================
 *  BATASAN ISI — DIBACA SEBELUM MENGUBAH TEKS DI SINI
 * ===================================================================
 * Isi di bawah dibagi dua, dan pemisahan ini disengaja:
 *
 * 1. **COPY EDITORIAL** memakai kalimat dari mockup yang Anda berikan.
 *    Itu copy Anda, jadi sudah disetujui. Contoh: "Merekam Sunyi, Embun
 *    Pagi, dan Ketangguhan Kain di Lereng Senaru".
 *
 * 2. **FAKTA OPERASIONAL & TRANSAKSIONAL** TIDAK boleh dikarang
 *    (PRD §1.1A). Semuanya placeholder sampai Anda mengisinya lewat panel
 *    admin. Contoh: kode voucher, kode promo, tanggal berakhir, jam buka.
 *
 * Yang membuat halaman terlihat "belum jadi" sementara ini disengaja:
 * menampilkan kode voucher karangan berarti pelanggan sincerely mencoba
 * kode yang tidak berlaku. Placeholder yang jujur lebih baik daripada
 * rechargeable yang menipu.
 *
 * Pengecualian yang FAKTUAL dan boleh ditulis: `720P VERTICAL • STEREO`
 * di spec film — itu memang kondisi berkas video hasil `prepare_drive_assets.py`,
 * bukan klaim pemasaran. Mockup menulis "4K RES" dan itu tidak benar untuk
 * berkas yang kita punya.
 */
class HomepageContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $key => $spec) {
            AppSetting::firstOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $spec['value']],
                    'group' => 'homepage',
                    'label' => $spec['label'],
                    'description' => $spec['description'],
                ],
            );
        }
    }

    /**
     * @return array<string, array{label: string, description: string, value: array<string, mixed>}>
     */
    private function definitions(): array
    {
        return [
            'home.benefit' => [
                'label' => 'Pita Benefit & Voucher',
                'description' => 'Baris di bawah hero: klaim benefit + kode voucher + tombol WhatsApp.',
                'value' => [
                    'title' => 'BEBAS BIAYA PENGIRIMAN SELURUH INDONESIA',
                    'body' => 'Gunakan kode voucher saat checkout atau sebutkan kode langsung kepada Customer Service WhatsApp.',
                    'voucher_code' => '[Kode Voucher dari Admin]',
                    'voucher_note' => null,
                    'cta_label' => 'Konsultasi WhatsApp',
                ],
            ],

            'home.film' => [
                'label' => 'Opening Film',
                'description' => 'Video vertikal + teks visual essay di beranda.',
                'value' => [
                    'video_path' => 'drive/drive-opening-film.mp4',
                    'poster_path' => 'drive/drive-opening-film-poster.webp',
                    'chapter' => 'CHAPTER 01',
                    'tag' => 'LOOKBOOK REEL',
                    'caption' => 'Suara Langkah Pertama di Ketinggian 601 MDPL',
                    'spec' => '720P VERTICAL • STEREO AUDIO',
                    'eyebrow' => 'VISUAL ESSAY // LOOKBOOK DIARY',
                    'title' => 'Merekam Sunyi, Embun Pagi, dan Ketangguhan Kain di Lereng Senaru',
                    'body' => 'Sebuah pembuka visual yang merangkum ritme hidup di gerbang pendakian Rinjani. Melalui lensa film portrait, kami mengabadikan bagaimana koleksi katun berat dan perlengkapan ekspedisi Palo menyatu dengan kabut hutan lembap, batuan vulkanik, dan langkah kaki para penjelajah.',
                    'cta_primary_label' => 'Eksplorasi Koleksi Terkait',
                    'cta_primary_url' => '/produk',
                    'facts' => [
                        ['label' => 'LOKASI PEREKAMAN', 'value' => 'Jalur Senaru, Lombok Utara'],
                        ['label' => 'DESAIN & KELENGKAPAN', 'value' => 'Chase The Edge & Crater of Serenity'],
                    ],
                ],
            ],

            'home.lookbook' => [
                'label' => 'Lookbook Split',
                'description' => 'Dua panel editorial bersebelahan dengan foto besar.',
                'value' => [
                    [
                        'eyebrow' => 'LOMBOK LOOKBOOK // 01',
                        'title' => 'Lahir dari Hening Senaru 601 MDPL',
                        'body' => 'Ditenun & dicetak bertutur. Membawa kesejukan air Terjun Tiu Kelep dan kerendahan hati lereng gunung ke setiap serat kain.',
                        'image_path' => 'drive/drive-alam-lookbook-1080.webp',
                    ],
                    [
                        'eyebrow' => 'RINJANI CALDERA TRIBUTE',
                        'title' => 'Ilustrasi & Tipografi Berakar Lokal',
                        'body' => 'Setiap goresan mencatat batas punggungan 3726 MDPL, perhentian danau kaldera, dan doa keselamatan para penjelajah.',
                        'image_path' => 'drive/drive-camp-exploring-1080.webp',
                    ],
                ],
            ],

            'home.manifesto' => [
                'label' => 'Brand Manifesto',
                'description' => 'Panel manifesyo brand di atas latar gelap.',
                'value' => [
                    'image_path' => 'drive/drive-manifesto-1080.webp',
                    'badge' => 'MANIFESTO // ARCHIVE',
                    'eyebrow' => 'BRAND MANIFESTO // FILOSOFI SENARU',
                    'title' => 'Good Things Grow From The Mountain',
                    'body' => 'PALO lahir dari hening dan keagungan Gunung Rinjani. Kami percaya bahwa pakaian bukan sekadar penutup raga, melainkan pernyataan spiritual dan tanda kehormatan bagi mereka yang melangkah selaras dengan alam liar Lombok.',
                    'strip_left' => 'SENARU 601 MDPL',
                    'strip_right' => 'EDISI CETAK RESMI',
                    'points' => [
                        [
                            'title' => 'Ruang Singgah Senaru',
                            'body' => 'Bukan sekadar toko ritel, melainkan tempat berkumpul, bertukar cerita jalur, dan merayakan persahabatan kaki gunung.',
                        ],
                        [
                            'title' => 'Filosofi Material',
                            'body' => 'Katun berat berserat kokoh, pigmen alami bernuansa bumi, serta siluet santai yang nyaman untuk iklim tropis lembap.',
                        ],
                    ],
                    'cta_primary_label' => 'Kunjungi Toko Senaru',
                    'cta_primary_url' => '/kontak',
                    'cta_secondary_label' => 'Jelajahi Cerita Lengkap',
                    'cta_secondary_url' => '/cerita',
                ],
            ],

            'home.gear' => [
                'label' => 'Header Headwear & Gear',
                'description' => 'Judul section aksesori. Section hanya tampil bila ada produk di kategori itu.',
                'value' => [
                    'eyebrow' => 'AKSESORI & PERLENGKAPAN',
                    'title' => 'PALO : HEADWEAR & GEAR SENARU',
                    'cta_label' => 'Katalog Lengkap',
                ],
            ],

            'home.promo' => [
                'label' => 'Promo Musim',
                'description' => 'Panel promo event. Kode, tanggal, dan kuota WAJIB diisi admin.',
                'value' => [
                    'eyebrow' => 'PROMO MUSIM // SENARU',
                    'badges' => [],
                    'title' => 'Promo Musim Pendakian',
                    'body' => 'Detail paket, bonus, dan syaratnya diisi pemilik lewat panel admin. Jangan tampilkan section ini sampai promo benar-benar ada — mengiklankan promo yang belum disetujui berisiko mengarahkan pelanggan ke halaman yang tidak pernah jadi.',
                    'deadline_note' => '[Tanggal berakhir & kuota promo dari Admin]',
                    'code_label' => 'KODE PROMO',
                    'code' => '[Kode Promo dari Admin]',
                    'code_body' => null,
                    'cta_primary_label' => 'Klaim Voucher Promo',
                    'cta_secondary_label' => 'Detail Syarat Event',
                    'cta_secondary_url' => '/kontak',
                ],
            ],

            'home.store' => [
                'label' => 'Kotak Storefront',
                'description' => 'Kotak kiri: info toko fisik. Alamat memakai key store.address.',
                'value' => [
                    'eyebrow' => 'LOKASI FISIK',
                    'title' => 'Toko Senaru — Pintu Masuk Rinjani',
                    'body' => 'Singgah sejenak di ruang etalase resmi kami di Desa Senaru sebelum memulai pendakian. Tersedia fitting ukuran langsung serta memorabilia edisi terbatas.',
                    'maps_label' => 'Buka Penunjuk Arah Google Maps',
                ],
            ],

            'home.bulk' => [
                'label' => 'Kotak Pesanan Custom',
                'description' => 'Kotak kanan: layanan pesanan komunitas / B2B.',
                'value' => [
                    'eyebrow' => 'LAYANAN KOMUNITAS',
                    'title' => 'Pesanan Custom Partai & Ekspedisi',
                    'body' => 'Kami menerima pembuatan seragam t-shirt ekspedisi, merchandise komunitas PECinta Alam, perusahaan, maupun instansi dengan sentuhan estetika outdoor khas Palo Rinjani.',
                    'points' => [
                        'Minimal order fleksibel untuk grup trip',
                        'Pilihan kain katun combed 24s/20s & dry-fit',
                        'Pendampingan visual & tipografi lokal',
                    ],
                    'cta_label' => 'Diskusikan Pesanan via WhatsApp',
                ],
            ],
        ];
    }
}
