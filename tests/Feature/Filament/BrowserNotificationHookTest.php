<?php

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    /**
     * @test
     * Halaman login (tamu) tidak boleh memuat banner/alert admin.
     */
    public function test_hook_is_not_rendered_on_login_page(): void
    {
        $response = $this->get('/admin/login');

        $response->assertSuccessful();
        $response->assertDontSee('palorinjaniAlertsInit', false);
        $response->assertDontSee('palorinjani_admin_alerts_enabled', false);
    }

    /**
     * @test
     * Selector JS hook harus benar-benar ada pada markup notification Filament
     * yang terpasang, bukan hanya ada sebagai string di blade.
     */
    public function test_unread_notification_markup_matches_hook_selectors(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create([
            'is_active' => true,
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'Filament\Notifications\DatabaseNotification',
            'notifiable_type' => AdminUser::class,
            'notifiable_id' => $admin->id,
            'data' => json_encode([
                'format' => 'filament',
                'title' => 'Pesanan Baru COD (Menunggu Konfirmasi)',
                'body' => 'Uji selector',
                'status' => 'warning',
                'actions' => [],
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin, 'admin')
            ->test(DatabaseNotifications::class)
            ->assertSeeHtml('id="database-notifications"')
            ->assertSeeHtml('fi-no-notification-unread-ctn')
            ->assertSeeHtml('.database-notifications.ctn');
    }
}
