<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Access\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identidad y permisos efectivos del usuario del token.
 *
 * La SPA lo usa para ocultar las opciones que el usuario no puede ejercer. Esa
 * ocultacion es usabilidad, no un control: la Fase 3 §4.9 lo dice
 * expresamente, y por eso cada endpoint vuelve a comprobar el permiso en el
 * servidor aunque la interfaz ya lo hubiera escondido.
 */
final class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse(['data' => [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'read_only' => $user->role->isReadOnly(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                $user->role->permissions()
            ),
        ]]);
    }
}
