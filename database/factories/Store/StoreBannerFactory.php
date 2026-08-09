<?php

namespace Database\Factories\Store;

use App\Modules\Store\Models\StoreBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreBannerFactory extends Factory
{
    protected $model = StoreBanner::class;

    public function definition(): array
    {
        return [
            'image' => 'banners/'.$this->faker->uuid().'.jpg',
            'title' => $this->faker->words(3, true),
            'subtitle' => $this->faker->sentence(),
            'button_label' => 'تسوّق الآن',
            'button_url' => '/shop',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
