<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Resultado de una comprobacion de identidad contra un proveedor externo
 * (identity_validations.ine_status y renapo_status).
 *
 * 'unavailable' es un estado propio y no un fallo: si INE o RENAPO no responden
 * (riesgo R-03) la solicitud no puede darse por verificada, pero tampoco debe
 * rechazarse al prospecto por una indisponibilidad ajena a el.
 */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case NotVerified = 'not_verified';
    case Unavailable = 'unavailable';
}
