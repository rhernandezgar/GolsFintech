<?php

declare(strict_types=1);

namespace App\Http\Requests\Prospect;

use App\Domain\Prospect\Sex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P2 (PATCH): actualizacion parcial del expediente del prospecto.
 *
 * El prospecto llena el formulario por pasos y no siempre envia todos los
 * campos. Todos los campos son opcionales (`sometimes`), y la validacion
 * estricta —formato de CURP y RFC, cotas de edad, formato del ingreso—
 * aplica **solo a los campos presentes**. La comprobacion de que el
 * expediente esta completo NO es de este endpoint: es de
 * `POST /prospects/me/confirm`.
 *
 * La validacion del dominio se hace DENTRO del caso de uso, convirtiendo
 * cada dato en su objeto de valor —CURP con digito verificador, RFC con
 * estructura, telefono con formato—: nada de lo que llega del navegador
 * entra al motor sin pasar por su validador (regla de seguridad no
 * negociable 4).
 *
 * `authorize()` no comprueba titularidad: eso lo resuelve el controlador
 * cargando el prospecto desde el token y llamando a
 * `ProspectPolicy::updateOwn`. Meter la comprobacion aqui habria duplicado
 * la logica y dejado al FormRequest teniendo que cargar el registro por
 * su cuenta.
 */
final class CaptureProspectDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],
            // La CURP se acepta como cadena de 18 aqui; el digito verificador
            // lo comprueba `Curp::fromString` dentro del caso de uso.
            'curp' => ['sometimes', 'nullable', 'string', 'size:18'],
            'rfc' => ['sometimes', 'nullable', 'string', 'min:12', 'max:13'],
            'age' => ['sometimes', 'nullable', 'integer', 'min:18', 'max:100'],
            'sex' => ['sometimes', 'nullable', 'string', Rule::in(array_column(Sex::cases(), 'value'))],
            // Cadena decimal, no numero: la conversion a centavos vive en
            // Money y no queremos que aqui se convierta en float.
            'monthly_income' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'geographic_location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc,strict', 'max:120'],
            // El formato definitivo lo valida `PhoneNumber::fromString`; aqui
            // solo se cortan basura evidente y longitudes fuera de rango.
            'phone' => ['sometimes', 'nullable', 'string', 'min:8', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'age.min' => 'La edad minima para solicitar credito es 18 anios.',
            'age.max' => 'Verifica la edad ingresada.',
            'sex.in' => 'Selecciona una opcion valida para el sexo.',
            'monthly_income.regex' => 'El ingreso mensual debe ser un numero con hasta dos decimales.',
            'email.email' => 'El correo no tiene un formato valido.',
        ];
    }
}
