<?php

declare(strict_types=1);

namespace App\Http\Requests\Credit;

use App\Domain\Credit\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P5: solicitud de simulacion de credito.
 *
 * El unico dato de entrada es el plazo. El monto, la tasa y el pago los
 * calcula el motor de reglas en el servidor: nada de lo que envie el
 * navegador influye en las condiciones de la oferta (RS-04).
 *
 * El plazo se valida contra el catalogo autorizado del dominio; el dominio
 * vuelve a hacerlo con `Term::fromMonths` como segundo cinturon (VUL-02).
 * `authorize()` no comprueba titularidad: el prospecto sale del token, no
 * hay identificador ajeno en la URL.
 */
final class SimulateCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'term_months' => ['required', 'integer', Rule::in(Term::AUTHORIZED_MONTHS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'term_months.required' => 'Elige un plazo para la simulacion.',
            'term_months.in' => 'El plazo seleccionado no esta disponible.',
        ];
    }
}
