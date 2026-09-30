<?php

namespace Database\Factories;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withRole(RoleSlug $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->firstOrCreate(['slug' => $slug->value], ['name' => $slug->label()])->id,
        ]);
    }

    public function alumnus(): static
    {
        return $this->withRole(RoleSlug::Alumni);
    }

    public function registrar(): static
    {
        return $this->withRole(RoleSlug::Registrar);
    }

    public function ictAdmin(): static
    {
        return $this->withRole(RoleSlug::IctAdmin);
    }

    public function qaViewer(): static
    {
        return $this->withRole(RoleSlug::QaViewer);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
