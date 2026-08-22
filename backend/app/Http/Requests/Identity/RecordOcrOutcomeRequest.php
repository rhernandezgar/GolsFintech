<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Resultado que envia el worker. La autorizacion no se decide aqui: la impone el
 * middleware `client` de Passport sobre la ruta (token de client_credentials con
 * el scope 'ocr-result'), porque quien llama es un proceso y no una persona.
 */
final class RecordOcrOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document_public_id' => ['required', 'uuid'],
            'job_ref' => ['required', 'string', 'max:128'],
            'status' => ['required', 'string', 'in:extracted,failed'],
            'attempt' => ['required', 'integer', 'min:1', 'max:10'],
            'result' => ['nullable', 'array'],
            // Codigo cerrado, no texto libre: lo que el worker manda acaba en la
            // bitacora, y ahi no entra la respuesta cruda de un tercero.
            'reason' => ['nullable', 'string', 'in:unreadable,exhausted'],
        ];
    }
}
