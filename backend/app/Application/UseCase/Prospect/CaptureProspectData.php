<?php

declare(strict_types=1);

namespace App\Application\UseCase\Prospect;

use App\Application\DTO\ProspectDataInput;
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
 * P2: captura manual de datos, o confirmacion de los datos que extrajo el OCR.
 *
 * Cada dato entra convirtiendose en su objeto de valor, asi que la CURP y el RFC
 * quedan validados con digito verificador antes de tocar la base de datos. La
 * bitacora recibe unicamente la CURP enmascarada (regla de seguridad 1).
 */
final readonly class CaptureProspectData
{
    public function __construct(
        private ProspectRepository $prospects,
        private AuditLogger $auditLogger,
    ) {
    }

    public function execute(
        Uuid $prospectPublicId,
        ProspectDataInput $input,
        AuditContext $context,
        DateTimeImmutable $now,
    ): Prospect {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        $curp = Curp::fromString($input->curp);

        // Una CURP ya registrada por otro prospecto no es un duplicado inocente: se
        // corta aqui y el detalle queda del lado del servidor.
        $existing = $this->prospects->findByCurp($curp);

        if ($existing !== null && ! $existing->publicId()->equals($prospect->publicId())) {
            throw new InvalidCurpException('La CURP ya esta registrada en otra solicitud.');
        }

        $prospect->captureData(
            fullName: $input->fullName,
            curp: $curp,
            rfc: $input->rfc === null || trim($input->rfc) === '' ? null : Rfc::fromString($input->rfc),
            age: $input->age,
            sex: Sex::from($input->sex),
            monthlyIncome: Money::fromDecimalString($input->monthlyIncome),
            address: $input->address,
            geographicLocation: $input->geographicLocation,
            businessType: $input->businessType,
            email: $input->email === null || trim($input->email) === '' ? null : Email::fromString($input->email),
            phone: $input->phone === null || trim($input->phone) === '' ? null : PhoneNumber::fromString($input->phone),
        );

        $prospect = $this->prospects->save($prospect);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::ProspectDataCaptured,
            affectedEntity: 'Prospect',
            affectedEntityId: $prospect->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            // La bitacora identifica por prospect_id, no por CURP: no se escribe el
            // identificador ni siquiera enmascarado, solo el hecho de que existe.
            metadata: [
                'has_curp' => true,
                'has_rfc' => $prospect->rfc() !== null,
                'capture_method' => $prospect->captureMethod()->value,
            ],
        ));

        return $prospect;
    }
}
