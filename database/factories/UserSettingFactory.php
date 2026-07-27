<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserSetting> */
class UserSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'theme' => fake()->randomElement(['light', 'dark', 'system']),
            'language' => fake()->randomElement(['en', 'es', 'fr', 'de']),
            'notifications' => fake()->boolean(),
            'default_model' => null,
        ];
    }
}
