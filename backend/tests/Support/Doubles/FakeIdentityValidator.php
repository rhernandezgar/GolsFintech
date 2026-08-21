<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Port\IdentityValidator;
use App\Domain\Prospect\Prospect;

/**
 * Doble programable del puerto de validacion de identidad.
 *
 * `willReject()` es el que permite a T10 comprobar que el rechazo llega al cliente
 * como mensaje generico —sin nombre de proveedor, sin detalle tecnico y sin la
 * CURP—, y `willBeUnavailable()` distingue el caso en que INE no contesta, que no
 * es un rechazo (riesgo R-03).
 */
final class FakeIdentityValidator implements IdentityValidator
{
    /** @var list<Prospect> */
    private array $validated = [];

    private string $outcome = 'verified';

    private bool $throwUnavailable = false;

    public function validate(Prospect $prospect, ?IdentityDocument $document): IdentityValidationResult
    {
        if ($this->throwUnavailable) {
            throw ExternalServiceUnavailableException::forService('identity', 'proveedor caido (doble de prueba)');
        }

        $this->validated[] = $prospect;
        $folio = sprintf('VF-TEST-%06d', count($this->validated));

        $documentValidity = $document === null ? VerificationStatus::Pending : VerificationStatus::Verified;

        return match ($this->outcome) {
            'rejected' => new IdentityValidationResult(
                verificationFolio: $folio,
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::NotVerified,
                dataMatchStatus: VerificationStatus::NotVerified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
            ),
            'unavailable' => new IdentityValidationResult(
                verificationFolio: $folio,
                ineStatus: VerificationStatus::Unavailable,
                renapoStatus: VerificationStatus::Unavailable,
                dataMatchStatus: VerificationStatus::Pending,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
            ),
            'fraud' => new IdentityValidationResult(
                verificationFolio: $folio,
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::Verified,
                dataMatchStatus: VerificationStatus::Verified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: true,
            ),
            default => new IdentityValidationResult(
                verificationFolio: $folio,
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::Verified,
                dataMatchStatus: VerificationStatus::Verified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
            ),
        };
    }

    public function willVerify(): self
    {
        $this->outcome = 'verified';

        return $this;
    }

    /** eKYC rechaza: RENAPO no reconoce la CURP. */
    public function willReject(): self
    {
        $this->outcome = 'rejected';

        return $this;
    }

    /** El proveedor no contesta: ni verificado ni rechazado. */
    public function willBeUnavailable(): self
    {
        $this->outcome = 'unavailable';

        return $this;
    }

    public function willFlagFraud(): self
    {
        $this->outcome = 'fraud';

        return $this;
    }

    /** El proveedor falla de forma dura, con excepcion en lugar de resultado. */
    public function willThrowUnavailable(): self
    {
        $this->throwUnavailable = true;

        return $this;
    }

    /** @return list<Prospect> */
    public function validatedProspects(): array
    {
        return $this->validated;
    }
}
