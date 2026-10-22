<?php

namespace Database\Factories;

use App\Models\HeroSlider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroSlider>
 */
class HeroSliderFactory extends Factory
{
    protected $model = HeroSlider::class;

    public function definition(): array
    {
        return [
            'series_tag' => 'FORM. 01 — RINJANI SERIES',
            'location_tag' => 'SENARU / 600 MDPL',
            'badge' => 'KOLEKSI BUSANA & CENDERAMATA',
            'title' => 'Stories from the Land of Rinjani',
            'description' => 'Dirancang di lembah Senaru dengan keheningan kawah Segara Anak dan keteduhan hutan tropis Lombok.',
            'image_desktop' => 'heroes/rinjani-desktop.webp',
            'image_mobile' => 'heroes/rinjani-mobile.webp',
            'image_alt' => 'Model mengenakan pakaian Rinjani Series di Senaru',
            'primary_btn_label' => 'LIHAT KATALOG',
            'primary_btn_url' => '/katalog/rinjani-series',
            'secondary_btn_label' => 'CERITA SENARU',
            'secondary_btn_url' => '/cerita/senaru',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
