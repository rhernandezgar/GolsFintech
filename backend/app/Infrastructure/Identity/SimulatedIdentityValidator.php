<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\IdentityDocument;
use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Port\IdentityValidator;
use App\Domain\Prospect\Prospect;
use DateTimeImmutable;

/**
 * Adaptador de validacion de identidad simulado (RF-04, P4).
 *
 * No consulta a INE ni a RENAPO: no hay convenio, y el entorno es de desarrollo.
 * Reproduce lo que hace el entorno sandbox de cualquier proveedor de eKYC:
 * identidades de laboratorio con desenlace fijo y conocido de antemano.
 *
 * Como se elige el desenlace, en este orden:
 *   1. `adapters.identity.simulated.force_scenario`, si esta definido.
 *   2. La CURP del prospecto, si figura entre las CURP de laboratorio.
 *   3. Verificado.
 *
 * Las CURP de laboratorio empiezan por XEXX —el prefijo generico oficial para
 * persona extranjera— y por tanto no pueden ser la CURP de una persona real.
 *
 * Determinista a proposito: el mismo prospecto obtiene siempre el mismo folio y el
 * mismo resultado. Un simulador que respondiera al azar volveria intermitentes las
 * pruebas que dependen de el, que es justo lo contrario de lo que se necesita.
 */
final readonly class SimulatedIdentityValidator implements IdentityValidator
{
    /** @param array<string, list<string>> $sandboxCurps escenario => CURP reservadas */
    public function __construct(
        private ?IdentityScenario $forcedScenario = null,
        private array $sandboxCurps = [],
        private ?DateTimeImmutable $reference = null,
    ) {
    }

    public function validate(Prospect $prospect, ?IdentityDocument $document): IdentityValidationResult
    {
        $scenario = $this->scenarioFor($prospect);

        // La indisponibilidad del proveedor se reporta como resultado, no como
        // excepcion: el prospecto no tiene la culpa de que INE no conteste y su
        // solicitud queda pendiente, no rechazada (VerificationStatus::Unavailable).
        $documentValidity = $document === null
            ? VerificationStatus::Pending
            : VerificationStatus::Verified;

        return match ($scenario) {
            IdentityScenario::Verified => new IdentityValidationResult(
                verificationFolio: $this->folioFor($prospect),
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::Verified,
                dataMatchStatus: VerificationStatus::Verified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
                maskedProviderResponse: $this->providerResponse($scenario, 'identidad confirmada'),
            ),
            IdentityScenario::Rejected => new IdentityValidationResult(
                verificationFolio: $this->folioFor($prospect),
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::NotVerified,
                dataMatchStatus: VerificationStatus::NotVerified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
                maskedProviderResponse: $this->providerResponse($scenario, 'la CURP no fue reconocida'),
            ),
            IdentityScenario::Unavailable => new IdentityValidationResult(
                verificationFolio: $this->folioFor($prospect),
                ineStatus: VerificationStatus::Unavailable,
                renapoStatus: VerificationStatus::Unavailable,
                dataMatchStatus: VerificationStatus::Pending,
                documentValidityStatus: $documentValidity,
                fraudFlagged: false,
                maskedProviderResponse: $this->providerResponse($scenario, 'el proveedor no respondio'),
            ),
            IdentityScenario::Fraud => new IdentityValidationResult(
                verificationFolio: $this->folioFor($prospect),
                ineStatus: VerificationStatus::Verified,
                renapoStatus: VerificationStatus::Verified,
                dataMatchStatus: VerificationStatus::Verified,
                documentValidityStatus: $documentValidity,
                fraudFlagged: true,
                maskedProviderResponse: $this->providerResponse($scenario, 'marcada por la evaluacion antifraude'),
            ),
        };
    }

    public function scenarioFor(Prospect $prospect): IdentityScenario
    {
        if ($this->forcedScenario !== null) {
            return $this->forcedScenario;
        }

        $curp = $prospect->curp()?->value;

        if ($curp === null) {
            return IdentityScenario::Verified;
        }

        foreach ($this->sandboxCurps as $scenario => $curps) {
            if (in_array($curp, $curps, true)) {
                return IdentityScenario::from($scenario);
            }
        }

        return IdentityScenario::Verified;
    }

    /**
     * Folio de verificacion con el formato de P4 (VF-<anio>-<6 digitos>), derivado
     * del identificador publico del prospecto: mismo prospecto, mismo folio, y sin
     * que el folio revele ningun dato personal.
     */
    private function folioFor(Prospect $prospect): string
    {
        $digits = substr(preg_replace('/\D/', '', hash('sha256', $prospect->publicId()->value)) ?? '', 0, 6);

        return sprintf(
            'VF-%s-%s',
            ($this->reference ?? new DateTimeImmutable())->format('Y'),
            str_pad($digits, 6, '0')
        );
    }

    /**
     * Respuesta "del proveedor" ya normalizada. Nunca lleva la CURP ni el RFC: lo
     * que se guarda del proveedor es el desenlace, no los datos que se le enviaron
     * (regla de seguridad 1, VUL-04).
     *
     * @return array<string, mixed>
     */
    private function providerResponse(IdentityScenario $scenario, string $detail): array
    {
        return [
            'provider' => 'simulated',
            'scenario' => $scenario->value,
            'detail' => $detail,
        ];
    }
}
