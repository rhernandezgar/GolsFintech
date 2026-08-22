<?php

declare(strict_types=1);

namespace App\Http\Requests\Prospect;

use App\Domain\Prospect\CaptureMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P1: eleccion del metodo de captura y aceptacion del aviso de privacidad.
 *
 * Es el unico punto del recorrido del prospecto que no exige token, porque es
 * donde el token se emite. Por eso authorize() devuelve true: la puerta de
 * entrada no es un permiso, son el throttle y el CAPTCHA, y el CAPTCHA se
 * comprueba en el controlador —no aqui— para poder registrar el rechazo.
 */
final class StartProspectCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capture_method' => ['required', 'string', Rule::in(array_column(CaptureMethod::cases(), 'value'))],

            // `accepted` y no `boolean`: un false explicito tiene que fallar.
            // Sin aviso aceptado no se recaba ningun dato (LFPDPPP, RS-09).
            'privacy_notice_accepted' => ['required', 'accepted'],

            'captcha_token' => ['required', 'string', 'max:4096'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'capture_method.required' => 'Elige como quieres proporcionar tus datos.',
            'capture_method.in' => 'Elige como quieres proporcionar tus datos.',
            'privacy_notice_accepted.required' => 'Debes aceptar el aviso de privacidad para continuar.',
            'privacy_notice_accepted.accepted' => 'Debes aceptar el aviso de privacidad para continuar.',
            'captcha_token.required' => 'No pudimos verificar que la solicitud viene de una persona.',
        ];
    }
}
