<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Models\UserSetting;

class SettingsService
{
    /**
     * Get the settings for a user, creating defaults if they don't exist yet.
     */
    public function getForUser(User $user): UserSetting
    {
        return $user->settings()->firstOrCreate(
            ['user_id' => $user->getKey()],
            $this->defaults(),
        );
    }

    /**
     * Update (or create) the settings for a user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateForUser(User $user, array $attributes): UserSetting
    {
        $settings = $this->getForUser($user);
        $settings->update($attributes);

        return $settings->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'theme' => 'system',
            'language' => 'en',
            'notifications' => true,
            'default_model' => null,
        ];
    }
}
