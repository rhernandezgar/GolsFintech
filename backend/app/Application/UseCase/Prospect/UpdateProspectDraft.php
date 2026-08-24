<?php

declare(strict_types=1);

namespace App\Application\UseCase\Prospect;

use App\Application\DTO\ProspectDataPatch;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Exception\InvalidCurpException;
use App\Domain\Identity\Curp;
use App\Domain\Identity\Rfc;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\Prospect;
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
            $existing = $this->prospects->findByCurp($curp);

            if ($existing !== null && ! $existing->publicId()->equals($prospect->publicId())) {
                // Duplicidad de CURP en otro expediente: se corta con el mismo
                // criterio que `CaptureProspectData`, y el detalle queda del
                // lado del servidor.
                throw new InvalidCurpException('La CURP ya esta registrada en otra solicitud.');
            }
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
}
