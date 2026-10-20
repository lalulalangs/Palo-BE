<?php

namespace Tests\Unit;

use App\Models\Voucher;
use Tests\TestCase;

class VoucherDiscountRoundingTest extends TestCase
{
    public function test_percentage_discount_is_rounded_to_two_decimals(): void
    {
        $voucher = new Voucher([
            'type' => Voucher::TYPE_PERCENTAGE,
            'value' => 33.33,
        ]);

        $this->assertEqualsWithDelta(4114.59, $voucher->calculateDiscount(12345), 0.0001);
    }

    public function test_fixed_discount_is_capped_at_subtotal(): void
    {
        $voucher = new Voucher([
            'type' => Voucher::TYPE_FIXED,
            'value' => 50000,
        ]);

        $this->assertSame(20000.0, $voucher->calculateDiscount(20000));
    }

    public function test_zero_subtotal_returns_zero_discount(): void
    {
        $voucher = new Voucher([
            'type' => Voucher::TYPE_PERCENTAGE,
            'value' => 10,
        ]);

        $this->assertSame(0.0, $voucher->calculateDiscount(0));
    }
}
