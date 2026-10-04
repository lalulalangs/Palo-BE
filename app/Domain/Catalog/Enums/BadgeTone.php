<?php

namespace App\Domain\Catalog\Enums;

/**
 * Nada warna badge promosi pada kartu produk.
 *
 * Dipisah dari teks badge karena warnanya punya ARTINYA:
 * "Sisa Sedikit" memakai `Warning` supaya terlihat berbeda dari
 * "Batch 01" yang `Neutral`. Kalau nada ikut memakai teks badge, menambah
 * jenis badge baru akan selalu butuh perubahan kode.
 */
enum BadgeTone: string
{
    /** Badge biasa: batch, signature, dan sejenisnya. */
    case Neutral = 'neutral';

    /** Badge peringatan: "Sisa Sedikit", "Segera Habis". */
    case Warning = 'warning';
}
