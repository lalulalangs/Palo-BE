<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BannerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    public function test_can_render_banner_list_page_and_table(): void
    {
        $banner = Banner::create([
            'title' => 'Promo Spesial Rinjani Peak Season',
            'image_url' => 'banners/hero-rinjani.webp',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListBanners::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$banner]);
    }

    public function test_can_render_create_banner_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateBanner::class)
            ->assertSuccessful()
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('image_url')
            ->assertFormFieldExists('start_date')
            ->assertFormFieldExists('end_date')
            ->assertFormFieldExists('is_active');
    }

    public function test_can_create_banner_with_image_upload_and_dates(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('banner-hero.jpg', 1920, 800);

        Livewire::actingAs($this->admin)
            ->test(CreateBanner::class)
            ->fillForm([
                'title' => 'Grand Opening Palo Rinjani',
                'image_url' => [$file],
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonths(1)->toDateString(),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('banners', [
            'title' => 'Grand Opening Palo Rinjani',
            'is_active' => true,
        ]);

        $banner = Banner::where('title', 'Grand Opening Palo Rinjani')->first();
        $this->assertNotNull($banner);
        $this->assertNotEmpty($banner->image_url);
    }

    public function test_can_edit_and_update_banner(): void
    {
        $banner = Banner::create([
            'title' => 'Banner Diskon Awal Musim',
            'image_url' => 'banners/season-sale.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditBanner::class, [
                'record' => $banner->getRouteKey(),
            ])
            ->fillForm([
                'title' => 'Banner Diskon Awal Musim (Updated)',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('banners', [
            'id' => $banner->id,
            'title' => 'Banner Diskon Awal Musim (Updated)',
            'is_active' => false,
        ]);
    }

    public function test_scope_active_filters_inactive_and_out_of_schedule_banners(): void
    {
        // 1. Banner aktif tanpa tanggal batas (selalu tayang)
        $bannerAlways = Banner::create([
            'title' => 'Banner Always Active',
            'image_url' => 'banners/always.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // 2. Banner aktif dalam rentang jadwal hari ini
        $bannerInSchedule = Banner::create([
            'title' => 'Banner In Schedule',
            'image_url' => 'banners/in-schedule.jpg',
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // 3. Banner dinonaktifkan secara manual (is_active = false)
        $bannerDisabled = Banner::create([
            'title' => 'Banner Disabled',
            'image_url' => 'banners/disabled.jpg',
            'is_active' => false,
            'sort_order' => 3,
        ]);

        // 4. Banner jadwal mendatang (start_date > today)
        $bannerUpcoming = Banner::create([
            'title' => 'Banner Upcoming',
            'image_url' => 'banners/upcoming.jpg',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'is_active' => true,
            'sort_order' => 4,
        ]);

        // 5. Banner jadwal sudah lewat (end_date < today)
        $bannerExpired = Banner::create([
            'title' => 'Banner Expired',
            'image_url' => 'banners/expired.jpg',
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'is_active' => true,
            'sort_order' => 5,
        ]);

        $activeBanners = Banner::active()->get();

        $this->assertTrue($activeBanners->contains($bannerAlways));
        $this->assertTrue($activeBanners->contains($bannerInSchedule));
        $this->assertFalse($activeBanners->contains($bannerDisabled));
        $this->assertFalse($activeBanners->contains($bannerUpcoming));
        $this->assertFalse($activeBanners->contains($bannerExpired));
        $this->assertCount(2, $activeBanners);
    }

    public function test_can_delete_banner(): void
    {
        $banner = Banner::create([
            'title' => 'Banner Yang Akan Dihapus',
            'image_url' => 'banners/delete-me.jpg',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditBanner::class, [
                'record' => $banner->getRouteKey(),
            ])
            ->callAction('delete');

        $this->assertDatabaseMissing('banners', [
            'id' => $banner->id,
        ]);
    }
}
