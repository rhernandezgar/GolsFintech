<?php

declare(strict_types=1);

namespace App\Application\UseCase\Prospect;

use App\Application\DTO\ProspectDataPatch;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Exception\ProspectAlreadyCustomerException;
use App\Domain\Exception\ProspectApplicationInProgressException;
use App\Domain\Exception\ProspectRetryLimitReachedException;
use App\Domain\Identity\Curp;
use App\Domain\Identity\Rfc;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\ApplicationSnapshot;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\ReapplicationOutcome;
use App\Domain\Prospect\ReapplicationPolicy;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Email;
use App\Domain\Shared\Money;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P2 (PATCH): actualizacion parcial del expediente del prospecto.
 *
 * Se diferencia de `CaptureProspectData` en dos cosas: acepta cualquier
 * subconjunto de campos —el prospecto llena el formulario por pasos— y no
 * exige que los cinco obligatorios esten al final de esta llamada. Eso es
 * cosa de `ConfirmProspectData`, que es la transicion que abre P4.
 *
 * La validacion estricta —CURP con digito, RFC con estructura, telefono con
 * formato, correo con parser en modo strict— aplica a los campos **presentes**.
 * Si CURP no llega, no se valida CURP; si llega, se valida entera. Es lo que
 * pide la regla de seguridad 4 y evita a la vez que el prospecto tenga que
 * dictar todos los datos de golpe.
 *
 * Cada llamada deja su evento `prospect.data_captured` en la bitacora. La
 * metadata registra la lista de campos actualizados y si la CURP y el RFC
 * existen —nunca su valor, ni siquiera enmascarado— (regla 1).
 */
final readonly class UpdateProspectDraft
{
    public function __construct(
        private ProspectRepository $prospects,
        private AuditLogger $auditLogger,
        private ReapplicationPolicy $reapplication,
    ) {}

    public function execute(
        Uuid $prospectPublicId,
        ProspectDataPatch $patch,
        AuditContext $context,
        DateTimeImmutable $now,
    ): Prospect {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        $curp = $this->blankToNull($patch->curp) === null ? null : Curp::fromString((string) $patch->curp);

        if ($curp !== null) {
            $this->assertCurpCanApply($curp, $prospect, $context, $now);
        }

        $rfc = $this->blankToNull($patch->rfc) === null ? null : Rfc::fromString((string) $patch->rfc);
        $sex = $this->blankToNull($patch->sex) === null ? null : Sex::from((string) $patch->sex);
        $income = $this->blankToNull($patch->monthlyIncome) === null
            ? null
            : Money::fromDecimalString((string) $patch->monthlyIncome);
        $email = $this->blankToNull($patch->email) === null ? null : Email::fromString((string) $patch->email);
        $phone = $this->blankToNull($patch->phone) === null ? null : PhoneNumber::fromString((string) $patch->phone);

        $prospect->mergePartialData(
            fullName: $this->blankToNull($patch->fullName),
            curp: $curp,
            rfc: $rfc,
            age: $patch->age,
            sex: $sex,
            monthlyIncome: $income,
            address: $this->blankToNull($patch->address),
            geographicLocation: $this->blankToNull($patch->geographicLocation),
            businessType: $this->blankToNull($patch->businessType),
            email: $email,
            phone: $phone,
        );

        $prospect = $this->prospects->save($prospect);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::ProspectDataCaptured,
            affectedEntity: 'Prospect',
            affectedEntityId: $prospect->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'has_curp' => $prospect->curp() !== null,
                'has_rfc' => $prospect->rfc() !== null,
                'capture_method' => $prospect->captureMethod()->value,
                'updated_fields' => $this->fieldsPresent($patch),
            ],
        ));

        return $prospect;
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return list<string> */
    private function fieldsPresent(ProspectDataPatch $patch): array
    {
        $present = [];
        foreach ([
            'full_name' => $patch->fullName,
            'curp' => $patch->curp,
            'rfc' => $patch->rfc,
            'age' => $patch->age,
            'sex' => $patch->sex,
            'monthly_income' => $patch->monthlyIncome,
            'address' => $patch->address,
            'geographic_location' => $patch->geographicLocation,
            'business_type' => $patch->businessType,
            'email' => $patch->email,
            'phone' => $patch->phone,
        ] as $field => $value) {
            if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                $present[] = $field;
            }
        }

        return $present;
    }

    /**
     * Aplica la politica de reintento sobre la CURP capturada (VUL-17).
     *
     * Antes esto era un `existsWithCurp` que lanzaba `InvalidCurpException`, y
     * tenia dos problemas encadenados: le decia «tu CURP no es valida» a alguien
     * cuya CURP era perfectamente valida, y convertia cualquier intento fallido
     * en un veto permanente. Ahora la regla es «una CURP, una solicitud ACTIVA
     * a la vez» y cada desenlace tiene su excepcion y su mensaje.
     *
     * El expediente PROPIO nunca estorba: se compara por identificador publico
     * antes de nada, porque reescribir la misma CURP en el mismo expediente es
     * lo que hace cualquiera que corrige una letra.
     */
    private function assertCurpCanApply(
        Curp $curp,
        Prospect $prospect,
        AuditContext $context,
        DateTimeImmutable $now,
    ): void {
        $existing = $this->prospects->findApplicationByCurp($curp);

        if ($existing === null || $existing->prospectPublicId->equals($prospect->publicId())) {
            return;
        }

        $decision = $this->reapplication->decide($existing, $now);

        match ($decision->outcome) {
            ReapplicationOutcome::BlockAlreadyCustomer => throw new ProspectAlreadyCustomerException,
            ReapplicationOutcome::BlockInProgress => throw new ProspectApplicationInProgressException(
                (int) $decision->retryAfterMinutes
            ),
            ReapplicationOutcome::BlockRetryLimit => throw new ProspectRetryLimitReachedException(
                (int) $decision->retryAfterMinutes
            ),
            ReapplicationOutcome::AbandonPreviousThenAllow => $this->abandonPrevious($existing, $context, $now),
            ReapplicationOutcome::Allow => null,
        };
    }

    /**
     * Cierra el expediente caducado y lo deja registrado.
     *
     * El evento NO es opcional: sin el, un expediente con datos personales
     * cambia de estado sin que nadie pueda acreditar cuando ni por que, y la
     * retencion acotada que pide RS-09 (LFPDPPP, finalidad y proporcionalidad)
     * deja de ser demostrable.
     */
    private function abandonPrevious(
        ApplicationSnapshot $existing,
        AuditContext $context,
        DateTimeImmutable $now,
    ): void {
        $previous = $this->prospects->findByPublicId($existing->prospectPublicId);

        if ($previous === null) {
            return;
        }

        $previous->abandon();
        $this->prospects->save($previous);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::ProspectAbandoned,
            affectedEntity: 'Prospect',
            affectedEntityId: $previous->id(),
            prospectId: $previous->id(),
            context: $context,
            eventAt: $now,
            // Sin CURP: el motivo y la antiguedad bastan para auditar la
            // caducidad, y el dato personal no aporta nada aqui (regla 1).
            metadata: [
                'reason' => 'in_progress_window_expired',
                'last_activity_at' => $existing->lastActivityAt->format(DateTimeImmutable::ATOM),
            ],
        ));
    }
}
