<?php

namespace App\Models;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\HasApiTokens;

/**
 * Usuario autenticable del sistema.
 *
 * `role` no es fillable a proposito: el rol decide que puede hacer el usuario y
 * asignarlo desde una peticion masiva convertiria cualquier formulario de alta
 * o de perfil en una escalada de privilegios. Se asigna de forma explicita.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Argon2id, por el driver de config/hashing.php.
            'password' => 'hashed',
            'role' => Role::class,
            // Cifrado a nivel de columna: una copia de la tabla no basta para
            // generar codigos TOTP validos.
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_verified_at' => 'datetime',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(ProspectRecord::class, 'prospect_id');
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->role->hasPermission($permission);
    }

    /**
     * El segundo factor esta activo cuando el secreto existe y el usuario ya
     * demostro que su autenticador lo tiene. Un secreto sin confirmar no cuenta.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * TOTP obligatorio para todo perfil administrativo (RS-01, Fase 3 §4.4).
     */
    public function requiresTwoFactor(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Reautenticacion reciente con segundo factor, para las operaciones que la
     * exigen (datos completos de la tarjeta, Fase 3 §4.9).
     */
    public function hasRecentTwoFactorVerification(int $maxAgeSeconds): bool
    {
        $verifiedAt = $this->two_factor_verified_at;

        if (! $verifiedAt instanceof Carbon) {
            return false;
        }

        return $verifiedAt->greaterThanOrEqualTo(Carbon::now()->subSeconds($maxAgeSeconds));
    }
}
