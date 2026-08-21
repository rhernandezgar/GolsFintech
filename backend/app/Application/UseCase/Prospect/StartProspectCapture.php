<?php

declare(strict_types=1);

namespace App\Application\UseCase\Prospect;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use DateTimeImmutable;

/**
 * P1: el prospecto elige capturar sus datos manualmente o subir su identificacion
 * (RF-01) y acepta el aviso de privacidad.
 *
 * El caso de uso solo orquesta: la regla de que se puede o no hacer esta en la
 * entidad, y el como se guarda, en el adaptador.
 */
final readonly class StartProspectCapture
{
    public function __construct(
        private ProspectRepository $prospects,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        CaptureMethod $captureMethod,
        AuditContext $context,
        DateTimeImmutable $now,
    ): Prospect {
        $prospect = Prospect::start($captureMethod, $now);
        $prospect = $this->prospects->save($prospect);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::ProspectStarted,
            affectedEntity: 'Prospect',
            affectedEntityId: $prospect->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'capture_method' => $captureMethod->value,
                'privacy_notice_accepted_at' => $now->format(DateTimeImmutable::ATOM),
            ],
        ));

        return $prospect;
    }
}
