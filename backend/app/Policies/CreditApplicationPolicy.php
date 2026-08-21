<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Autorizacion a nivel de objeto sobre la solicitud de credito.
 *
 * Es el control que la Fase 3 §4.9 subraya expresamente: "no basta con
 * comprobar que un usuario puede consultar clientes en general, sino que debe
 * verificarse que tiene derecho sobre el cliente especifico solicitado, pues su
 * ausencia es una de las causas mas frecuentes de acceso indebido en
 * aplicaciones que si autentican correctamente".
 *
 * Por eso la pregunta que se hace aqui nunca es "que rol tiene" sino "que
 * relacion tiene este usuario con este registro". El prospecto autenticado que
 * pide la solicitud de otro prospecto esta autenticado, tiene el permiso
 * generico de consultar solicitudes y aun asi debe recibir un 403.
 */
final class CreditApplicationPolicy
{
    public function view(User $user, CreditApplicationRecord $application): Response
    {
        // Los perfiles administrativos con alcance sobre cualquier expediente.
        if ($user->hasPermission(Permission::ViewAnyCreditApplication)) {
            return Response::allow();
        }

        // El prospecto y el cliente, unicamente sobre lo suyo.
        if ($user->hasPermission(Permission::ViewOwnCreditApplication)
            && $this->belongsToUser($user, $application)
        ) {
            return Response::allow();
        }

        return $this->deny();
    }

    /**
     * Los ingresos declarados son un campo restringido (RS-05).
     *
     * Se autoriza por campo y no por endpoint: la solicitud se consulta con un
     * 200 y el ingreso simplemente no viaja. Devolver 403 por el recurso
     * completo obligaria a duplicar endpoints por rol, y ocultarlo solo en la
     * interfaz no seria un control.
     */
    public function viewDeclaredIncome(User $user, CreditApplicationRecord $application): Response
    {
        // Entre los perfiles administrativos, solo el analista de riesgos: es
        // el unico cuyo alcance incluye la informacion economica del prospecto.
        if ($user->hasPermission(Permission::ViewAnyDeclaredIncome)) {
            return Response::allow();
        }

        // El propio prospecto ve el ingreso que el mismo declaro.
        if ($user->hasPermission(Permission::ViewOwnDeclaredIncome)
            && $this->belongsToUser($user, $application)
        ) {
            return Response::allow();
        }

        return $this->deny();
    }

    public function updateStatus(User $user, CreditApplicationRecord $application): Response
    {
        return $user->hasPermission(Permission::UpdateCreditApplicationStatus)
            ? Response::allow()
            : $this->deny();
    }

    /**
     * La solicitud es del usuario cuando cuelga de su prospecto. El anclaje
     * persiste tras la conversion a cliente, igual que el prospect_id de la
     * bitacora.
     */
    private function belongsToUser(User $user, CreditApplicationRecord $application): bool
    {
        return $user->prospect_id !== null
            && (int) $user->prospect_id === (int) $application->prospect_id;
    }

    /**
     * Mensaje generico e identico en todos los casos: no revela si el registro
     * existe ni que condicion fallo (regla de seguridad no negociable 8).
     */
    private function deny(): Response
    {
        return Response::deny('No tiene acceso a este recurso.');
    }
}
