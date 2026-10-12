<?php

namespace Database\Factories;

use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper('RINJANI'.Str::random(5)),
            'type' => Voucher::TYPE_FIXED,
            'value' => 25000.00,
            'min_purchase' => 100000.00,
            'quota' => 50,
            'used_count' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'is_active' => true,
        ];
    }

    public function fixed(float $value = 25000.00, float $minPurchase = 100000.00): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Voucher::TYPE_FIXED,
            'value' => $value,
            'min_purchase' => $minPurchase,
        ]);
    }

    public function percentage(float $percent = 10.00, float $minPurchase = 50000.00): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Voucher::TYPE_PERCENTAGE,
            'value' => $percent,
            'min_purchase' => $minPurchase,
        ]);
    }

    public function unlimitedQuota(): static
    {
        return $this->state(fn (array $attributes) => [
            'quota' => 0,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => now()->subDays(30)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function quotaExhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'quota' => 10,
            'used_count' => 10,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
