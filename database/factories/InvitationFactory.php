<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Invitation::generateCode(),
            'email' => null,
            'note' => null,
            'invited_by_user_id' => null,
            'used_at' => null,
            'used_by_user_id' => null,
            'expires_at' => null,
        ];
    }

    /** An already-redeemed code. */
    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }

    /** A code whose expiry is in the past. */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    /** A code bound to a specific address. */
    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => $email]);
    }
}
