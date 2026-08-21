<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

/** Desenlace que simula el proveedor de eKYC para una identidad. */
enum IdentityScenario: string
{
    /** INE y RENAPO verifican, los datos coinciden y no hay marca antifraude. */
    case Verified = 'verified';

    /** RENAPO no reconoce la CURP: identidad no verificada (rechazo). */
    case Rejected = 'rejected';

    /** El proveedor no responde (riesgo R-03): ni verificado ni rechazado. */
    case Unavailable = 'unavailable';

    /** La evaluacion antifraude marca la solicitud. */
    case Fraud = 'fraud';
}
