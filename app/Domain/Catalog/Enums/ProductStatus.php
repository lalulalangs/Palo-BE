<?php

namespace App\Domain\Catalog\Enums;

/**
 * Status publikasi produk.
 *
 * PRD §6A: "hanya produk berstatus aktif tampil" dan "Tidak ada produk contoh
 * yang dipublikasikan sebelum admin mengisi data asli".
 *
 * Alasan memakai enum dan bukan boolean `is_active`:
 * ada keadaan ketiga yang perlu dibedakan secara operasional — `draft` berarti
 * "sudah dibuat admin tapi datanya belum diverifikasi pemilik", sehingga
 * produknya boleh disimpan tapi TIDAK boleh bocor ke publik.
 */
enum ProductStatus: string
{
    /** Baru dibuat admin, belum diverifikasi pemilik brand. Tidak tampil di publik. */
    case Draft = 'draft';

    /** Sudah diverifikasi dan siap tayang. Satu-satunya status yang tampil di storefront. */
    case Active = 'active';

    /** Pernah aktif lalu sengaja diturunkan. Tidak tampil, tapi datanya disimpan. */
    case Inactive = 'inactive';

    /**
     * Label Bahasa Indonesia untuk UI admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Active => 'Aktif',
            self::Inactive => 'Nonaktif',
        };
    }
}
