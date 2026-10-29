<?php

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_groups_and_items_structure_for_super_admin(): void
    {
        $superAdmin = AdminUser::factory()->superAdmin()->create();
        $this->actingAs($superAdmin, 'admin');

        $panel = filament()->getPanel('admin');
        $navigation = $panel->getNavigation();

        $groupLabels = array_values(array_map(fn ($group) => $group->getLabel(), $navigation));

        $expectedGroupOrder = [
            null,
            'Katalog Produk',
            'Inventaris',
            'Penjualan',
            'Konten & Promosi',
            'Pengaturan',
            'Role & Pengguna',
        ];

        $this->assertSame($expectedGroupOrder, $groupLabels);

        // Group: Katalog Produk
        $katalogGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Katalog Produk');
        $this->assertNotNull($katalogGroup);
        $katalogItems = collect($katalogGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Produk', 'sort' => 1],
            ['label' => 'Kategori', 'sort' => 2],
            ['label' => 'Koleksi', 'sort' => 3],
        ], $katalogItems);

        // Group: Konten & Promosi
        $kontenGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Konten & Promosi');
        $this->assertNotNull($kontenGroup);
        $kontenItems = collect($kontenGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Banner', 'sort' => 1],
            ['label' => 'Voucher', 'sort' => 3],
        ], $kontenItems);

        // Group: Inventaris
        $inventarisGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Inventaris');
        $this->assertNotNull($inventarisGroup);
        $inventarisItems = collect($inventarisGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Riwayat Mutasi Stok', 'sort' => 1],
            ['label' => 'Opname Stok', 'sort' => 2],
        ], $inventarisItems);

        // Group: Penjualan
        $penjualanGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Penjualan');
        $this->assertNotNull($penjualanGroup);
        $penjualanItems = collect($penjualanGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Pesanan', 'sort' => 1],
        ], $penjualanItems);

        // Group: Pengaturan
        $pengaturanGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Pengaturan');
        $this->assertNotNull($pengaturanGroup);
        $pengaturanItems = collect($pengaturanGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Media Sosial & Kontak', 'sort' => 1],
            ['label' => 'Ekspedisi & Ongkir', 'sort' => 2],
        ], $pengaturanItems);

        // Group: Role & Pengguna
        $roleGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === 'Role & Pengguna');
        $this->assertNotNull($roleGroup);
        $roleItems = collect($roleGroup->getItems())->map(fn ($item) => [
            'label' => $item->getLabel(),
            'sort' => $item->getSort(),
        ])->all();

        $this->assertSame([
            ['label' => 'Role', 'sort' => 1],
            ['label' => 'Pengguna', 'sort' => 2],
        ], $roleItems);

        // Standalone Top Item: Dashboard
        $topGroup = collect($navigation)->first(fn ($g) => $g->getLabel() === null);
        $this->assertNotNull($topGroup);
        $topItems = collect($topGroup->getItems())->map(fn ($item) => $item->getLabel())->all();
        $this->assertSame(['Dashboard'], $topItems);
    }

    public function test_sidebar_desktop_is_collapsible(): void
    {
        $panel = filament()->getPanel('admin');
        $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
    }

    public function test_navigation_groups_have_icons(): void
    {
        $panel = filament()->getPanel('admin');
        $groups = collect($panel->getNavigationGroups())->keyBy(fn ($g) => $g->getLabel());

        $this->assertTrue($groups->has('Katalog Produk'));
        $this->assertSame(Heroicon::OutlinedSquare2Stack, $groups->get('Katalog Produk')->getIcon());

        $this->assertTrue($groups->has('Inventaris'));
        $this->assertSame(Heroicon::OutlinedArchiveBox, $groups->get('Inventaris')->getIcon());

        $this->assertTrue($groups->has('Penjualan'));
        $this->assertSame(Heroicon::OutlinedShoppingBag, $groups->get('Penjualan')->getIcon());

        $this->assertTrue($groups->has('Konten & Promosi'));
        $this->assertSame(Heroicon::OutlinedPhoto, $groups->get('Konten & Promosi')->getIcon());

        $this->assertTrue($groups->has('Pengaturan'));
        $this->assertSame(Heroicon::OutlinedCog6Tooth, $groups->get('Pengaturan')->getIcon());

        $this->assertTrue($groups->has('Role & Pengguna'));
        $this->assertSame(Heroicon::OutlinedShieldCheck, $groups->get('Role & Pengguna')->getIcon());
    }
}
