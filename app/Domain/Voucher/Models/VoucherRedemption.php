<?php

namespace App\Domain\Voucher\Models;

use App\Domain\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pemakaian voucher oleh satu order.
 *
 *Fungsi: membuat jewak yang bisa diaudit & mendukung aturan "jangan
 * menggandakan diskon saat order dibatalkan" (system_map §5: "catat pemakaian
 * per order dan aturan pelepasan saat batal agar tidak menggandakan diskon").
 *
 * `order_id` UNIQUE — satu order maksimal memakai satu voucher.
 */
class VoucherRedemption extends Model
{
    use HasFactory;

    protected $table = 'voucher_redemptions';

    public $timestamps = false;

    protected $fillable = ['voucher_id', 'order_id', 'discount_amount'];

    protected function casts(): array
    {
        return ['discount_amount' => 'integer'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
