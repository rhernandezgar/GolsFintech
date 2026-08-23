<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\RecordedIdentityValidation;
use DateTimeImmutable;

/**
 * Persistencia del resultado de la validacion contra INE y RENAPO.
 *
 * Se guarda el resultado, no la respuesta cruda del proveedor: lo que entra en
 * `provider_response` ya viene enmascarado desde el adaptador (VUL-04).
 */
interface IdentityValidationRepository
{
    public function save(
        int $prospectId,
        ?int $identityDocumentId,
        IdentityValidationResult $result,
        DateTimeImmutable $validatedAt,
    ): RecordedIdentityValidation;

    public function findLatestFor(int $prospectId): ?RecordedIdentityValidation;
}
