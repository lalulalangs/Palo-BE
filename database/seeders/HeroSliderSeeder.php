<?php

namespace Database\Seeders;

use App\Models\HeroSlider;
use Illuminate\Database\Seeder;

class HeroSliderSeeder extends Seeder
{
    public function run(): void
    {
        HeroSlider::firstOrCreate(
            ['title' => 'Stories from the Land of Rinjani'],
            [
                'series_tag' => 'FORM. 01 — RINJANI SERIES',
                'location_tag' => 'SENARU / 600 MDPL',
                'badge' => 'KOLEKSI BUSANA & CENDERAMATA',
                'description' => 'Dirancang di lembah Senaru dengan keheningan kawah Segara Anak dan keteduhan hutan tropis Lombok.',
                'image_desktop' => 'heroes/rinjani-desktop.webp',
                'image_mobile' => 'heroes/rinjani-mobile.webp',
                'image_alt' => 'Model mengenakan pakaian Rinjani Series di Senaru',
                'primary_btn_label' => 'LIHAT KATALOG',
                'primary_btn_url' => '/katalog/rinjani-series',
                'secondary_btn_label' => 'CERITA SENARU',
                'secondary_btn_url' => '/cerita/senaru',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );
    }
}
