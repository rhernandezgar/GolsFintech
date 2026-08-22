<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Cliente de tokens personales.
 *
 * Es el que Passport necesita para emitir la sesion del prospecto en P1, que no
 * pasa por el flujo de codigo de autorizacion: en P1 no hay todavia usuario que
 * conceda nada, el expediente y su credencial nacen a la vez.
 *
 * No tiene URI de redireccion ni concede ningun otro flujo: solo emite tokens
 * personales para el proveedor `users`. Es idempotente.
 */
final class OAuthPersonalAccessClientSeeder extends Seeder
{
    public const CLIENT_NAME = 'GolsFintech Prospect Session';

    public function run(): void
    {
        $exists = Client::query()
            ->where('name', self::CLIENT_NAME)
            ->get()
            ->contains(static fn (Client $client): bool => $client->hasGrantType('personal_access'));

        if ($exists) {
            $this->command?->info('El cliente de tokens personales ya existe.');

            return;
        }

        app(ClientRepository::class)->createPersonalAccessGrantClient(
            name: self::CLIENT_NAME,
            provider: 'users',
        );

        $this->command?->info('Cliente de tokens personales creado (sesion de prospecto).');
    }
}
