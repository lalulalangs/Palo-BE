<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestCheckoutLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'session_id',
        'cart_snapshot',
        'estimated_total',
        'wa_message_text',
        'wa_cs_number_used',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cart_snapshot' => 'array',
            'estimated_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
