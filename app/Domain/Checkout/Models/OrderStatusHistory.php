<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Checkout\Enums\OrderActor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan status order — audit trail.
 *
 * PRD §3.9: "Setiap perubahan status tercatat di order status history
 * (audit trail: siapa/kapan/status sebelum-sesudah)."
 * AC §3.9: "Given status order berubah, When dicek di halaman detail order,
 * Then riwayat status menampilkan urutan perubahan lengkap dengan timestamp."
 *
 * Tabel ini APPEND-ONLY. Tidak ada action yang boleh update atau delete di sini
 * — kalau ada, berarti ada bug di TransitionOrderStatus (mis. transisi
 * ganda karena webhook dikirim ulang).
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'order_status_histories';

    protected $fillable = [
        'order_id', 'from_status', 'to_status',
        'actor_id', 'actor_type', 'note', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => OrderActor::class,
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Aktor bisa user yang sudah dihapus, jadi polymorphic nullOnDelete.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
