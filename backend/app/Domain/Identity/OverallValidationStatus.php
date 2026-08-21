<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/** Veredicto consolidado de la validacion (identity_validations.overall_status). */
enum OverallValidationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
