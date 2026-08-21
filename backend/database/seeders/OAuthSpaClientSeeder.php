<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Cliente OAuth2 de la SPA de Vue.
 *
 * Se crea como cliente PUBLICO —confidential: false— porque no puede custodiar
 * un secreto: cualquier valor incorporado en el codigo que se entrega al
 * navegador es legible por el usuario, de modo que un "secreto de cliente" en
 * una SPA es un secreto compartido con todo el mundo. Lo que sustituye al
 * secreto es PKCE, que genera un verificador nuevo en cada intento de
 * autenticacion (Fase 3 §4.4, RFC 7636).
 *
 * Es idempotente: volver a sembrar no duplica el cliente ni cambia su
 * identificador, que la SPA lleva en su configuracion.
 */
final class OAuthSpaClientSeeder extends Seeder
{
    public const CLIENT_NAME = 'GolsFintech SPA';

    public function run(): void
    {
        if (Client::query()->where('name', self::CLIENT_NAME)->exists()) {
            $this->command?->info('El cliente publico de la SPA ya existe.');

            return;
        }

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: self::CLIENT_NAME,
            redirectUris: [config('app.frontend_url').'/auth/callback'],
            confidential: false,
        );

        $this->command?->info('Cliente publico de la SPA creado.');
        $this->command?->line('  client_id: '.$client->getKey());
        $this->command?->line('  Sin secreto de cliente: la SPA autentica con PKCE (S256).');
    }
}
