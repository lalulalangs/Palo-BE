<?php

namespace App\Events\Orders;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAwaitingConfirmation
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order
    ) {}
}
