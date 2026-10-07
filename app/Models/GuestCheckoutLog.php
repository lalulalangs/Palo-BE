<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestCheckoutLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
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
}
