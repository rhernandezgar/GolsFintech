<?php

declare(strict_types=1);

namespace App\Application\UseCase\Prospect;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * El prospecto revisa lo capturado —o lo que extrajo el OCR— y lo da por bueno
 * (P2 y P4). Sin este paso no se ejecuta el motor de reglas: la simulacion se
 * calcula sobre datos confirmados, no sobre una captura a medias.
 */
final readonly class ConfirmProspectData
{
    public function __construct(
        private ProspectRepository $prospects,
        private AuditLogger $auditLogger,
    ) {
    }

    public function execute(Uuid $prospectPublicId, AuditContext $context, DateTimeImmutable $now): Prospect
    {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        $prospect->confirmData();
        $prospect = $this->prospects->save($prospect);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::ProspectDataConfirmed,
            affectedEntity: 'Prospect',
            affectedEntityId: $prospect->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: ['capture_method' => $prospect->captureMethod()->value],
        ));

        return $prospect;
    }
}
