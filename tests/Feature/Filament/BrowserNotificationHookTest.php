<?php

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserNotificationHookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Memastikan script hook notifikasi browser & audio chime ter-render pada panel admin.
     */
    public function test_browser_notification_hook_is_rendered_in_admin_panel(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertSuccessful();
        $response->assertSee('palorinjani_seen_notification_ids', false);
        $response->assertSee('playNotificationChime', false);
        $response->assertSee('FilamentNotification', false);
        $response->assertSee('Notification.requestPermission', false);
        $response->assertSee('palorinjani_admin_alerts_enabled', false);

        // Livewire v4 menyuntikkan script-nya setelah render hook BODY_END,
        // jadi hook harus menunggu 'livewire:init' dan tetap punya fallback scan.
        $response->assertSee('waitForLivewire', false);
        $response->assertSee('livewire:init', false);
        $response->assertSee('setInterval(processIncomingNotifications, 15000)', false);

        // Chime tidak boleh membuat AudioContext sebelum ada user gesture
        // (Chrome menolak dan mencatat warning di console).
        $response->assertSee('hasUserGesture', false);
    }
}
