<?php

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Infrastructure\Security\TotpAuthenticator;
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
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // El rol menos privilegiado por defecto: una prueba que necesite
            // mas tiene que pedirlo, no recibirlo por descuido.
            'role' => Role::Prospect,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    /**
     * Perfil administrativo con el segundo factor ya dado de alta.
     *
     * El secreto se genera de verdad para que la prueba pueda calcular el
     * codigo del momento y ejercitar la verificacion real, no un doble.
     */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret ?? (new TotpAuthenticator)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function forProspect(int $prospectId): static
    {
        return $this->state(fn (array $attributes) => ['prospect_id' => $prospectId]);
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
}
