<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateDokuPaidCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_rejected_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('payments:simulate-doku-paid', ['order_number' => 'PRJ-TEST'])
            ->expectsOutputToContain('hanya dapat dijalankan')
            ->assertFailed();
    }

    public function test_command_fails_for_unknown_order(): void
    {
        $this->artisan('payments:simulate-doku-paid', ['order_number' => 'PRJ-TIDAK-ADA'])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertFailed();
    }
}
