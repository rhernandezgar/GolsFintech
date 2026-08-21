<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * Cliente OAuth2 que omite la pantalla de consentimiento cuando es de primera
 * parte, es decir, cuando lo posee la propia aplicacion y no un usuario.
 *
 * La pantalla de consentimiento existe para que una persona autorice a un
 * TERCERO a acceder a sus datos. La SPA de GolsFintech no es un tercero: es la
 * aplicacion. Preguntarle al usuario si autoriza a GolsFintech a acceder a
 * GolsFintech no aporta ninguna decision y si tiene un costo: acostumbra a la
 * gente a aprobar pantallas de consentimiento sin leerlas, que es
 * precisamente lo que hace utiles a los ataques de consentimiento cuando algun
 * dia exista un cliente de terceros.
 *
 * La omision se limita a los clientes de primera parte. Un cliente registrado a
 * nombre de un usuario —un integrador externo— sigue pasando por la pantalla.
 */
final class FirstPartyClient extends Client
{
    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
