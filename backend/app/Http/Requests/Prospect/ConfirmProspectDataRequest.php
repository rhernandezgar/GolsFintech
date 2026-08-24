<?php

declare(strict_types=1);

namespace App\Http\Requests\Prospect;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P2 (paso 2): confirmacion de los datos capturados antes de avanzar a P3.
 *
 * El cuerpo esta vacio a proposito: el prospecto ya vive en la base con los
 * datos que se le mostraron y la accion es solo un cambio de estado
 * (`data_captured -> data_confirmed`). Que sea un endpoint aparte, y no un
 * campo del PATCH anterior, permite que la bitacora tenga su propio evento
 * `prospect.data_confirmed` con marca de tiempo separada de la captura y sirva
 * como sostén probatorio si mas tarde surge un repudio.
 */
final class ConfirmProspectDataRequest extends FormRequest
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
