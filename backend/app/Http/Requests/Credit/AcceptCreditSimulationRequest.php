<?php

declare(strict_types=1);

namespace App\Http\Requests\Credit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P6: aceptacion de la simulacion.
 *
 * El cuerpo esta vacio a proposito. El UUID de la simulacion viaja por la URL
 * y la titularidad la comprueba el caso de uso contra el prospecto del token
 * (CWE-639). El aviso legal y la version del contrato viven en configuracion
 * del servidor: son cosa del servidor firmar QUE version se acepta, no del
 * cliente proponerla.
 */
final class AcceptCreditSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
