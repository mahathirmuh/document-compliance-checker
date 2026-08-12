<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // The least-privileged role is the default so an authorisation
            // test that forgets to set one fails closed, not open.
            'role' => UserRole::VIEWER,
            'status' => UserStatus::ACTIVE,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function superAdmin(): static
    {
        return $this->role(UserRole::SUPER_ADMIN);
    }

    public function admin(): static
    {
        return $this->role(UserRole::ADMIN);
    }

    public function documentController(): static
    {
        return $this->role(UserRole::DOCUMENT_CONTROLLER);
    }

    public function reviewer(): static
    {
        return $this->role(UserRole::REVIEWER);
    }

    public function viewer(): static
    {
        return $this->role(UserRole::VIEWER);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::INACTIVE]);
    }
}
