<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Identity\IdentityDocument;
use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Prospect\Prospect;

/**
 * Puerto de validacion de identidad contra INE y RENAPO. Cambiar de proveedor de
 * eKYC debe ser implementar otro adaptador, no reescribir el motor de reglas.
 */
interface IdentityValidator
{
    public function validate(Prospect $prospect, ?IdentityDocument $document): IdentityValidationResult;
}
