<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un rol de solo lectura no puede provocar ningun cambio de estado.
 *
 * La Fase 3 §4.9 marca al auditor como "solo lectura". Eso ya se cumple porque
 * su lista de permisos no contiene ninguno de escritura, pero un permiso que
 * falta protege solo mientras nadie lo anada por descuido, y una ruta nueva
 * puede olvidarse de comprobar el Gate. Este middleware corta por el metodo
 * HTTP, que es una propiedad de la peticion y no de la lista de permisos: aun
 * si ambas cosas fallaran, un auditor no escribe.
 *
 * Se apoya en Role::isReadOnly(), que se deriva de los permisos, de modo que no
 * puede contradecir la matriz de la Fase 3.
 *
 * Se aplica a los recursos de negocio, no a los endpoints de autenticacion:
 * cerrar la propia sesion o renovar el propio token no es un cambio de estado
 * del negocio, y un auditor que no pudiera cerrar sesion tendria que esperar a
 * que su token caducara solo.
 */
final class EnforceReadOnlyRole
{
    /** Metodos sin efectos secundarios segun RFC 9110 §9.2.1. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->role->isReadOnly()
            && ! in_array($request->method(), self::SAFE_METHODS, true)
        ) {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        return $next($request);
    }
}
