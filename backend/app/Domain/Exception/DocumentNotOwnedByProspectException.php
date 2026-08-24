<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * El documento indicado no pertenece al prospecto autenticado. Sin este
 * control, validar la identidad "propia" contra la identificacion oficial de
 * otro expediente seria un CWE-639.
 *
 * Es una excepcion propia y no `RuntimeException` para que la capa HTTP pueda
 * distinguirla y responder 403 con mensaje generico —el mismo que emiten las
 * politicas—: revelar si el documento existe le indica al atacante que su
 * intento acerto a otro expediente valido.
 */
final class DocumentNotOwnedByProspectException extends DomainException
{
    public function errorCode(): string
    {
        return 'DOCUMENT_NOT_OWNED';
    }

    public function userMessage(): string
    {
        return 'No tiene acceso a este recurso.';
    }
}
