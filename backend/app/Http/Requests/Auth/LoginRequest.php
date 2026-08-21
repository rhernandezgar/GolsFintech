<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Toda validacion del cliente se replica en el servidor (regla de seguridad no
 * negociable 4). Este es el punto donde se replica la del formulario de acceso.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            // Solo digitos y longitud exacta: un codigo TOTP no es texto libre.
            'totp_code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
