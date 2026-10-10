<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum AdminFeature: string implements HasLabel
{
    case Products = 'products';
    case Categories = 'categories';
    case Collections = 'collections';
    case Banners = 'banners';
    case HeroSliders = 'hero-sliders';
    case Orders = 'orders';
    case StockOpnames = 'stock-opnames';
    case StockMovements = 'stock-movements';
    case SocialMediaSettings = 'social-media-settings';
    case Roles = 'roles';
    case AdminUsers = 'admin-users';

    public function getLabel(): string
    {
        return match ($this) {
            self::Products => 'Produk',
            self::Categories => 'Kategori',
            self::Collections => 'Koleksi (Series)',
            self::Banners => 'Banner',
            self::HeroSliders => 'Hero Slider',
            self::Orders => 'Pesanan',
            self::StockOpnames => 'Opname Stok',
            self::StockMovements => 'Riwayat Mutasi Stok',
            self::SocialMediaSettings => 'Media Sosial & Kontak',
            self::Roles => 'Role',
            self::AdminUsers => 'Pengguna',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Products => Heroicon::OutlinedCube,
            self::Categories => Heroicon::OutlinedTag,
            self::Collections => Heroicon::OutlinedSparkles,
            self::Banners => Heroicon::OutlinedPhoto,
            self::HeroSliders => Heroicon::OutlinedPresentationChartBar,
            self::Orders => Heroicon::OutlinedShoppingBag,
            self::StockOpnames => Heroicon::OutlinedScale,
            self::StockMovements => Heroicon::OutlinedClipboardDocumentList,
            self::SocialMediaSettings => Heroicon::OutlinedGlobeAlt,
            self::Roles => Heroicon::OutlinedShieldCheck,
            self::AdminUsers => Heroicon::OutlinedUsers,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $feature) {
            $options[$feature->value] = $feature->getLabel();
        }

        return $options;
    }

    /**
     * @return array<string, Heroicon>
     */
    public static function icons(): array
    {
        $icons = [];

        foreach (self::cases() as $feature) {
            $icons[$feature->value] = $feature->getIcon();
        }

        return $icons;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
