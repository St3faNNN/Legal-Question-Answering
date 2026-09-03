<?php

namespace App\Http\Resources;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Services\Chat\EscalationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'bio' => $this->bio,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_active_at' => $this->last_active_at,
            // Deploy capabilities, so the SPA does not offer a control the
            // backend would refuse. Escalation needs an agent service to reach;
            // without one, no button is rendered rather than one that 422s.
            'features' => [
                'escalation' => app(EscalationService::class)->isConfigured(),
                // Whether to render the dashboard link. NOT the access control —
                // that is EnsureUserIsAdmin on the admin routes, which 404s
                // regardless of what the client believes.
                'admin' => EnsureUserIsAdmin::isAdmin($request),
            ],
        ];
    }
}
