<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P4: solicitud de validacion de identidad.
 *
 * El prospecto sale del token, siempre; el unico dato aceptado en el cuerpo
 * es el `document_public_id` opcional cuando el prospecto cargo su
 * identificacion en P3. Sin documento, la vigencia no se puede comprobar y el
 * conjunto queda en `deferred`; con documento cargado, la validacion es
 * completa.
 *
 * `authorize()` no comprueba titularidad: eso lo resuelve el use case
 * `ValidateIdentity`, que carga el prospecto del token y verifica que el
 * documento le pertenece. La duplicacion aqui obligaria al FormRequest a
 * cargar el registro de la base por su cuenta.
 */
final class ValidateIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_public_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
