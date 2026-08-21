<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Respuesta del puerto IdentityValidator, ya normalizada y libre de datos
 * personales en claro: el detalle tecnico del proveedor se enmascara antes de
 * llegar aqui (regla de seguridad 1 y VUL-04).
 *
 * El folio de verificacion permite dar seguimiento en soporte sin pedirle al
 * prospecto que repita su CURP por un canal no seguro (P4).
 */
final readonly class IdentityValidationResult
{
    /** @param array<string, mixed> $maskedProviderResponse */
    public function __construct(
        public string $verificationFolio,
        public VerificationStatus $ineStatus,
        public VerificationStatus $renapoStatus,
        public VerificationStatus $dataMatchStatus,
        public VerificationStatus $documentValidityStatus,
        public bool $fraudFlagged,
        public array $maskedProviderResponse = [],
    ) {
    }

    /**
     * Solo se considera verificada la identidad si INE y RENAPO responden
     * verificado, los datos coinciden y la evaluacion antifraude no la marco.
     * Cualquier otra combinacion deja la solicitud fuera del flujo automatico.
     */
    public function overallStatus(): OverallValidationStatus
    {
        if ($this->fraudFlagged) {
            return OverallValidationStatus::Rejected;
        }

        $checks = [$this->ineStatus, $this->renapoStatus, $this->dataMatchStatus, $this->documentValidityStatus];

        foreach ($checks as $check) {
            if ($check === VerificationStatus::NotVerified) {
                return OverallValidationStatus::Rejected;
            }
        }

        foreach ($checks as $check) {
            if ($check !== VerificationStatus::Verified) {
                return OverallValidationStatus::Pending;
            }
        }

        return OverallValidationStatus::Verified;
    }

    public function isVerified(): bool
    {
        return $this->overallStatus() === OverallValidationStatus::Verified;
    }
}
