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
    }
}
