<?php

namespace App\Services\Vouchers;

use App\Models\Voucher;

class VoucherValidationResult
{
    public function __construct(
        public Voucher $voucher,
        public float $discountAmount,
        public float $subtotal,
        public float $finalSubtotal,
    ) {}
}
