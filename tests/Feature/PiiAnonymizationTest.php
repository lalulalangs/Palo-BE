<?php

namespace Tests\Feature;

use App\Models\GuestCheckoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiiAnonymizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_guest_checkout_message_is_anonymized(): void
    {
        $old = GuestCheckoutLog::create([
            'session_id' => 'session-old',
            'cart_snapshot' => [],
            'estimated_total' => 100000,
            'wa_message_text' => 'Nama: Budi, Nomor HP: 081234567890',
            'created_at' => now()->subDays(120),
        ]);

        $recent = GuestCheckoutLog::create([
            'session_id' => 'session-new',
            'cart_snapshot' => [],
            'estimated_total' => 100000,
            'wa_message_text' => 'Nama: Sari, Nomor HP: 081298765432',
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('pii:anonymize')->assertSuccessful();

        $this->assertSame('[ANONYMIZED]', $old->fresh()->wa_message_text);
        $this->assertSame('Nama: Sari, Nomor HP: 081298765432', $recent->fresh()->wa_message_text);
    }

    public function test_anonymize_command_respects_custom_retention_days(): void
    {
        $log = GuestCheckoutLog::create([
            'session_id' => 'session-custom',
            'cart_snapshot' => [],
            'estimated_total' => 0,
            'wa_message_text' => 'Nama: Joko',
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('pii:anonymize', ['--days' => 30])->assertSuccessful();

        $this->assertSame('Nama: Joko', $log->fresh()->wa_message_text);
    }
}
