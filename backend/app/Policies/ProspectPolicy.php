<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Autorizacion a nivel de objeto sobre el expediente del prospecto.
 *
 * P2 se sirve **solo** bajo `/prospects/me`: el expediente sale del token y no
 * de la URL, asi que en el flujo normal no hay identificador ajeno que nombrar
 * (CWE-639 cerrado por diseno de rutas). Esta politica existe como **defensa
 * en profundidad**: si manana alguien introdujera una ruta con `{prospect}` en
 * la URL o cargara el registro desde otro identificador, la comprobacion vuelve
 * a caer sobre el registro concreto, no sobre el rol.
 *
 * La regla que fija es la misma que `CreditApplicationPolicy::view`: la
 * pregunta no es "que rol tiene" sino "que relacion tiene este usuario con
 * este registro". Un prospecto con permiso `CaptureOwnProspectData` que
 * intenta modificar el expediente de otro prospecto esta autenticado, tiene
 * el permiso generico y aun asi debe recibir un 403.
 */
final class ProspectPolicy
{
    public function updateOwn(User $user, ProspectRecord $prospect): Response
    {
        if (! $user->hasPermission(Permission::CaptureOwnProspectData)) {
            return $this->deny();
        }

        if ($user->prospect_id === null || (int) $user->prospect_id !== (int) $prospect->id) {
            return $this->deny();
        }

        return Response::allow();
    }

    /**
     * Mensaje generico e identico al de las demas politicas: no revela si el
     * registro existe ni que condicion fallo (regla de seguridad no
     * negociable 8).
     */
    private function deny(): Response
    {
        return Response::deny('No tiene acceso a este recurso.');
    }
}
