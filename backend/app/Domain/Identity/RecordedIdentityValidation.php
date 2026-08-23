<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use DateTimeImmutable;

/**
 * Una validacion de identidad ya escrita, con su identificador.
 *
 * P4 la vuelve a mostrar tal cual se guardo en vez de repetir la consulta al
 * proveedor: cada llamada a INE o RENAPO cuesta y deja rastro, y recargar la
 * pantalla no es un motivo para volver a preguntar.
 */
final readonly class RecordedIdentityValidation
{
    public function __construct(
        public int $id,
        public IdentityValidationResult $result,
        public int $attempts,
        public DateTimeImmutable $validatedAt,
    ) {}
}
