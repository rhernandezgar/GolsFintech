<?php

declare(strict_types=1);

namespace App\Application\UseCase\Credit;

use App\Application\DTO\CreditOfferOutput;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Credit\Term;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\ProspectRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P5: genera la simulacion del credito (RF-05 a RF-07).
 *
 * El plazo llega del cliente y se convierte con Term::fromMonths, que lo contrasta
 * contra el catalogo autorizado del dominio: ese es el control que faltaba en
 * VUL-02. El resto de las condiciones las calcula el motor en el servidor; nada de
 * lo que envie el navegador influye en el monto, la tasa ni el pago.
 */
final readonly class SimulateCredit
{
    public function __construct(
        private ProspectRepository $prospects,
        private CreditRulesEngine $rulesEngine,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        Uuid $prospectPublicId,
        int $requestedTermMonths,
        AuditContext $context,
        DateTimeImmutable $now,
    ): CreditOfferOutput {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        if (! $prospect->hasConfirmedData()) {
            throw new InvalidStateTransitionException('Los datos del prospecto no estan confirmados.');
        }

        $term = Term::fromMonths($requestedTermMonths);
        $offer = $this->rulesEngine->evaluateForProspect($prospect, $term);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditSimulationGenerated,
            affectedEntity: 'CreditSimulation',
            affectedEntityId: null,
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'credit_type' => $offer->creditType->value,
                'term_months' => $offer->term->months,
                'proposed_amount' => $offer->proposedAmount->toDecimalString(),
                'estimated_monthly_payment' => $offer->estimatedMonthlyPayment->toDecimalString(),
                'annual_rate' => $offer->annualRate->toPercentageString(),
            ],
        ));

        return CreditOfferOutput::fromOffer($offer);
    }
}
