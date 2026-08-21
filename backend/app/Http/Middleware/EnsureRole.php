<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a un conjunto de roles: `role:admin,auditor`.
 *
 * Es un filtro grueso y deliberadamente insuficiente por si solo. El permiso
 * concreto lo decide el Gate y el registro concreto lo decide la politica; este
 * middleware solo evita que una peticion de un rol que no tiene nada que hacer
 * en la ruta llegue a tocar la base de datos.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $allowed = array_map(
            static fn (string $role): Role => Role::from($role),
            $roles
        );

        if (! in_array($user->role, $allowed, true)) {
            // Mensaje generico: no se le dice al cliente que rol haria falta
            // (regla de seguridad no negociable 8).
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        return $next($request);
    }
}
