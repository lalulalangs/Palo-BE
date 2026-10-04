<?php

namespace App\Domain\Guest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Niat menghubungi Customer Service lewat WhatsApp.
 *
 * ===================================================================
 *  BACA INI SEBELUM MENGUBAH APA PUN DI TABEL INI
 * ===================================================================
 * PRD §3.11:
 *   "Log adalah NIAT MENGHUBUNGKAN CS, bukan bukti pesan terkirim atau order
 *    jadi; tidak mengunci stok."
 *
 * Konsekuensi yang tidak bisa ditawar:
 *   - TIDAK ADA kolom order_id. Kalau nanti ditambah, itu berarti alur guest
 *     salah dan sudah berubah jadi checkout online — padahal PRD §7 secara
 *     eksplisit menaruh "guest checkout online langsung" di luar scope.
 *   - TIDAK ADA reservasi stok yang dibuat dari sini.
 *   - TIDAK ADA panggilan ke payment gateway.
 *
 * Kenapa tabel ini tetap perlu ada? Karena:
 *   1. Admin/CS butuh cara mencari "order" dari buyer yang menghubungi lewat WA.
 *   2. KPI "Guest-to-WhatsApp Conversion Rate" (PRD §8) dihitung dari sini.
 *   3. `cs_number_used` disimpan supaya histori tetap accurate walaupun admin
 *      mengganti nomor CS (PRD §3.11 AC).
 */
class GuestCheckoutLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_code', 'cs_number_used', 'wa_url', 'item_snapshot',
        'estimated_total', 'guest_name', 'guest_phone', 'guest_note',
        'is_custom_inquiry',
    ];

    protected function casts(): array
    {
        return [
            'item_snapshot' => 'array',
            'estimated_total' => 'integer',
            'is_custom_inquiry' => 'boolean',
        ];
    }

    /**
     * Scope untuk panel admin: catatan permintaan custom/partai saja.
     */
    public function scopeCustomInquiries($query)
    {
        return $query->where('is_custom_inquiry', true);
    }

    /**
     * Scope untuk panel admin: checkout via WhatsApp dari cart.
     */
    public function scopeCheckoutIntents($query)
    {
        return $query->where('is_custom_inquiry', false);
    }

    /**
     * Ringkasan item untuk ditampilkan di panel admin.
     *
     * Sengaja dibaca dari snapshot, bukan dari katalog: CS harus melihat
     * apa yang sebenarnya dilihat buyer saat menghubungi, apa pun yang terjadi
     * ke katalog sesudahnya.
     */
    public function itemSummary(): string
    {
        if (empty($this->item_snapshot)) {
            return '—';
        }

        $lines = [];
        foreach ($this->item_snapshot as $item) {
            $lines[] = sprintf(
                '%s x%s — %s',
                $item['name'] ?? '[Nama Produk]',
                $item['quantity'] ?? 1,
                $this->formatRupiah($item['subtotal'] ?? 0),
            );
        }

        return implode("\n", $lines);
    }

    private function formatRupiah(int $amount): string
    {
        if ($amount === 0) {
            return '[Harga dari Admin]';
        }

        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
