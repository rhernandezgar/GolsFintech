<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validacion de servidor de la carga de la identificacion (P3).
 *
 * Regla no negociable 4: lo que valide el navegador se vuelve a validar aqui. El
 * cliente puede desactivar su propia validacion; esta no.
 *
 * Estas reglas NO sustituyen a las de `UploadedDocumentStore`: aqui se comprueba
 * lo que declara la peticion, y alli el contenido real del archivo. Un ejecutable
 * renombrado a `.jpg` con `Content-Type: image/jpeg` pasa esta capa y lo detiene
 * la otra, que es exactamente para lo que estan las dos.
 */
final class UploadIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::UploadOwnIdentityDocument) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // 5120 KB = 5 MB, el mismo limite que aplica el almacen.
            'document' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'document_type' => ['required', 'string', 'in:INE,passport,other'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // Mensajes al usuario final en espanol y sin detalle tecnico (regla 8).
        return [
            'document.required' => 'Adjunta tu identificacion oficial.',
            'document.max' => 'El archivo supera el tamano maximo permitido.',
            'document.mimetypes' => 'El tipo de archivo no esta permitido.',
            'document_type.in' => 'El tipo de documento no es valido.',
        ];
    }
}
