<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Role;
use App\Infrastructure\Security\TotpAuthenticator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Alta de un usuario administrativo con su segundo factor.
 *
 * No existe un endpoint publico para esto a proposito: no hay auto-registro de
 * perfiles administrativos, y un endpoint de alta accesible desde la red seria
 * el camino mas corto para crear una cuenta de administrador. El alta la hace
 * quien tiene acceso al servidor.
 *
 * El secreto TOTP se imprime una sola vez, aqui, y despues solo existe cifrado
 * en la columna. Quien no lo capture tendra que darse de alta de nuevo.
 */
final class CreateStaffUserCommand extends Command
{
    // La ayuda de la linea de ordenes va en ingles como el resto del codigo:
    // quien la lee es quien opera el servidor. Los textos que se le muestran a
    // la persona durante el alta si van en espanol.
    protected $signature = 'user:create
        {--name= : Full name}
        {--email= : Email address}
        {--role= : prospect, customer, admin, auditor or risk_analyst}';

    protected $description = 'Creates a user with its role and, for staff profiles, its TOTP second factor';

    public function handle(TotpAuthenticator $totp): int
    {
        $name = $this->option('name') ?: $this->ask('Nombre completo');
        $email = $this->option('email') ?: $this->ask('Correo electronico');
        $role = $this->option('role') ?: $this->choice(
            'Rol',
            array_column(Role::cases(), 'value'),
            Role::Auditor->value
        );

        // La contrasena se pide sin eco y nunca llega por un argumento de la
        // linea de ordenes: los argumentos quedan en el historial del shell y
        // son visibles en la lista de procesos.
        $password = $this->secret('Contrasena');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'role' => ['required', 'string', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
                'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::from($role);

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        // Argon2id, por el driver de config/hashing.php.
        $user->password = Hash::make($password);
        $user->role = $role;

        $secret = null;

        if ($role->isStaff()) {
            $secret = $totp->generateSecret();
            $user->two_factor_secret = $secret;
            $user->two_factor_confirmed_at = now();
        }

        $user->save();

        $this->info(sprintf('Usuario %s dado de alta con rol %s.', $email, $role->value));

        if ($secret !== null) {
            $this->newLine();
            $this->warn('Segundo factor obligatorio. Capture esto AHORA, no se vuelve a mostrar:');
            $this->line('  Secreto: '.$secret);
            $this->line('  URI:     '.$totp->provisioningUri($secret, $email, config('security.totp.issuer')));
            $this->newLine();
            $this->warn(sprintf(
                'El algoritmo es %s. Use un autenticador que respete el parametro algorithm '
                .'(Aegis, FreeOTP, 1Password); Google Authenticator calcula siempre con SHA-1 y no servira.',
                strtoupper((string) config('security.totp.algorithm'))
            ));
        }

        return self::SUCCESS;
    }
}
