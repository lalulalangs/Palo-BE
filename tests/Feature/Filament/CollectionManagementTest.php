<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Collections\Pages\CreateCollection;
use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Collections\RelationManagers\ProductsRelationManager;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $this->category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);
    }

    public function test_can_render_collection_list_page_and_table(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE (10th Anniversary)',
            'slug' => 'decade',
            'badge_label' => 'Limited Drop',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListCollections::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$collection]);
    }

    public function test_can_render_create_collection_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCollection::class)
            ->assertSuccessful()
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('slug')
            ->assertFormFieldExists('tagline')
            ->assertFormFieldExists('badge_label')
            ->assertFormFieldExists('is_active');
    }

    public function test_can_create_collection(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCollection::class)
            ->fillForm([
                'name' => 'DECADE Collection',
                'slug' => 'decade-collection',
                'tagline' => 'A Decade of Mountain Heritage',
                'badge_label' => 'Special Edition',
                'is_active' => true,
                'is_featured' => true,
                'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('collections', [
            'name' => 'DECADE Collection',
            'slug' => 'decade-collection',
            'badge_label' => 'Special Edition',
            'is_featured' => true,
        ]);
    }

    public function test_can_render_edit_collection_page(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE Collection',
            'slug' => 'decade-collection',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditCollection::class, [
                'record' => $collection->getRouteKey(),
            ])
            ->assertSuccessful()
            ->assertSchemaComponentExists('name');
    }

    public function test_can_manage_products_in_collection_via_relation_manager(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE Collection',
            'slug' => 'decade-collection',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'DECADE Windbreaker',
            'slug' => 'decade-windbreaker',
            'base_price' => 350000,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id, ['sort_order' => 1]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$product]);
    }

    public function test_can_attach_product_to_collection_via_relation_manager_attach_action(): void
    {
        $collection = Collection::create([
            'name' => 'Summit Series',
            'slug' => 'summit-series',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Jaket Summit Gore-Tex',
            'slug' => 'jaket-summit-gore-tex',
            'base_price' => 1250000,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->mountTableAction('attach')
            ->set('mountedActions.0.data.recordId', $product->id)
            ->set('mountedActions.0.data.sort_order', 3)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $attached = $collection->fresh()->products()->where('product_id', $product->id)->first();
        $this->assertNotNull($attached);
        $this->assertEquals(3, $attached->pivot->sort_order);
    }

    public function test_can_detach_product_from_collection_via_detach_action(): void
    {
        $collection = Collection::create([
            'name' => 'Trail Series',
            'slug' => 'trail-series',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Sepatu Trail 01',
            'slug' => 'sepatu-trail-01',
            'base_price' => 750000,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id, ['sort_order' => 1]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->callTableAction('detach', $product)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('collection_product', [
            'collection_id' => $collection->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_creating_collection_redirects_to_edit_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCollection::class)
            ->fillForm([
                'name' => 'Ekspedisi Segara Anak',
                'slug' => 'ekspedisi-segara-anak',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $created = Collection::where('slug', 'ekspedisi-segara-anak')->first();
        $this->assertNotNull($created);
    }

    public function test_can_upload_and_optimize_collection_banners_to_webp(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $desktop = \Illuminate\Http\UploadedFile::fake()->image('banner-desktop.jpg', 2400, 1200);
        $mobile = \Illuminate\Http\UploadedFile::fake()->image('banner-mobile.png', 1080, 1920);

        Livewire::actingAs($this->admin)
            ->test(CreateCollection::class)
            ->fillForm([
                'name' => 'Series Rinjani Peak',
                'slug' => 'series-rinjani-peak',
                'is_active' => true,
                'banner_desktop' => $desktop,
                'banner_mobile' => $mobile,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = Collection::where('slug', 'series-rinjani-peak')->first();
        $this->assertNotNull($collection);
        $this->assertNotNull($collection->banner_desktop);
        $this->assertNotNull($collection->banner_mobile);
        $this->assertStringEndsWith('.webp', $collection->banner_desktop);
        $this->assertStringEndsWith('.webp', $collection->banner_mobile);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('public')->exists($collection->banner_desktop));
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('public')->exists($collection->banner_mobile));
    }

    public function test_ended_at_cannot_be_before_published_at_in_collection_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCollection::class)
            ->fillForm([
                'name' => 'Flash Event Series',
                'slug' => 'flash-event-series',
                'published_at' => '2026-10-10 10:00:00',
                'ended_at' => '2026-10-09 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['ended_at']);
    }
}
