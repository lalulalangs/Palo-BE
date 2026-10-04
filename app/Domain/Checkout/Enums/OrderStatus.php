<?php

namespace App\Domain\Checkout\Enums;

/**
 * Status order beserta transisi yang diizinkan.
 *
 * PRD §3.9: "State machine order (Asumsi): Menunggu Pembayaran -> Dibayar ->
 * Diproses -> Dikirim -> Selesai; Expired hanya dari menunggu pembayaran;
 * pembatalan sebelum dikirim dan refund setelah pembayaran memerlukan aturan
 * stok serta otorisasi terpisah. Transisi hanya melalui service terpusat agar
 * webhook dan admin tidak saling menimpa."
 *
 * system_map §4.4.3 mengulang hal yang sama. Semuanya di-encode di sini
 * supaya mustahil ada dua tempat yang berbeda aturan transisi.
 *
 * AC PRD §3.9 yang TERNYATA di-encode:
 *   - "Order 'Dibayar' tidak dapat langsung melewati 'Diproses'."  -> dari
 *     `dibayar`, `dikirim` TIDAK ada di daftar transisi di bawah.
 *   - "Given order dibatalkan sebelum dikirim, Then stok SKU terkait
 *      dikembalikan otomatis ke inventori."  -> `dibatalkan` hanya reachable
 *      dari status sebelum `dikirim`.
 */
enum OrderStatus: string
{
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Dibayar = 'dibayar';
    case Diproses = 'diproses';
    case Dikirim = 'dikirim';
    case Selesai = 'selesai';
    case Expired = 'expired';
    case Dibatalkan = 'dibatalkan';

    /**
     * Transisi yang diizinkan.
     *
     * Kunci = status sekarang, nilai = daftar status tujuan yang sah.
     *
     * PENTING: daftar inilah yang mencegah bug "webhook dan admin saling
     * menimpa" (PRD §3.9). Menghapus entri berarti membuka celah bug baru.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Pembayaran belum masuk. Hanya bisa jadi Dibayar, Expired, atau Dibatalkan.
            self::MenungguPembayaran => [self::Dibayar, self::Expired, self::Dibatalkan],

            // Sudah bayar, belum diproses. TIDAK BOLEH langsung ke `dikirim`
            // (AC §3.9: wajib lewat `diproses`).
            self::Dibayar => [self::Diproses, self::Dibatalkan],

            // Sudah diproses, boleh dikirim atau dibatalkan.
            self::Diproses => [self::Dikirim, self::Dibatalkan],

            // Sudah di kurir. Pembatalan setelah ini memerlukan aturan
            // pengembalian dana (lihat OD-07 — belum diputuskan), jadi untuk
            // sekarang TIDAK ada transisi keluar dari `dikirim`.
            self::Dikirim => [self::Selesai],

            // Status akhir. Tidak ada jalan keluar.
            self::Selesai => [],
            self::Expired => [],
            self::Dibatalkan => [],
        };
    }

    /**
     * Apakah perpindahan status ini sah.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Status terminal: tidak ada transisi keluar.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Apakah order sudah "momentum uangnya keluar", sehingga pembatalan
     * tidak cukup — butuh refund (OD-07).
     */
    public function requiresRefundToCancel(): bool
    {
        return in_array($this, [self::Dibayar, self::Diproses, self::Dikirim, self::Selesai], true);
    }

    /**
     * Status yang menandakan order sudah bebas dari reservasi stok.
     *
     * Stok hanya boleh dilepas dari status ini; status lain berarti order
     * masih actively berjalan dan stok masih diamankan.
     *
     * @return array<int, string>
     */
    public static function releasableValues(): array
    {
        return [
            self::Expired->value,
            self::Dibatalkan->value,
            self::Dibayar->value,   // sudah jadi pengurangan stok fisik
            self::Selesai->value,
        ];
    }
}
