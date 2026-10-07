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
}
