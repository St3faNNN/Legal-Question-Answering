<?php

namespace App\Http\Resources;

use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserSetting */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'theme' => $this->theme,
            'language' => $this->language,
            'notifications' => $this->notifications,
            'default_model' => $this->default_model,
            'updated_at' => $this->updated_at,
        ];
    }
}
