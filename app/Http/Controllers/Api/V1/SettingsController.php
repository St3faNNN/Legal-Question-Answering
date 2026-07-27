<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\SettingResource;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $service) {}

    /**
     * Get settings
     *
     * Returns the authenticated user's settings (creates defaults if none exist).
     *
     * @group Settings
     *
     * @authenticated
     *
     * @response 200 {"data":{"theme":"system","language":"en","notifications":true,"default_model":null,"updated_at":"..."}}
     */
    public function show(Request $request): SettingResource
    {
        $settings = $this->service->getForUser($request->user());

        return new SettingResource($settings);
    }

    /**
     * Update settings
     *
     * Updates one or more settings fields.
     *
     * @group Settings
     *
     * @authenticated
     *
     * @bodyParam theme string optional One of: light, dark, system. Example: dark
     * @bodyParam language string optional Language code (2-10 chars). Example: fr
     * @bodyParam notifications boolean optional Enable/disable notifications. Example: false
     * @bodyParam default_model string nullable Default AI model identifier. Example: bart-finetuned
     *
     * @response 200 {"data":{"theme":"dark","language":"fr","notifications":false,"default_model":null}}
     */
    public function update(UpdateSettingsRequest $request): SettingResource
    {
        $settings = $this->service->updateForUser($request->user(), $request->validated());

        return new SettingResource($settings);
    }
}
