<?php

declare(strict_types=1);

namespace App\Application\UseCase\Credit;

use App\Application\DTO\CreditOfferOutput;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Credit\ApplicationStatus;
use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditOffer;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Credit\Term;
use App\Domain\Exception\IdentityNotVerifiedException;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Port\ProspectRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P5: genera la simulacion del credito y la PERSISTE (RF-05 a RF-07).
 *
 * Persistirla es lo que permite que P6 la acepte por su UUID: sin fila, el
 * cliente no puede referenciar la oferta que se le mostro. Ademas la simulacion
 * nace con **vigencia propia** —el TTL viaja como entero en el constructor y
 * lo fija el proveedor de servicios con la clave `credit.simulation_ttl_seconds`
 * (30 minutos por defecto)—: la Fase 2 dice que la simulacion es informativa y
 * no vinculante hasta su autorizacion, y aceptar una oferta calculada con
 * parametros que ya cambiaron es un defecto real. La caducidad se evalua
 * despues en `AcceptCreditOffer` contra el reloj del servidor, no del cliente.
 *
 * ### La cadena de estado de la solicitud
 *
 * La simulacion es de una solicitud, y su repositorio la reconstruye leyendo
 * `credit_type`, `validated_monthly_income` y `payment_capacity` de la
 * solicitud. Por eso la solicitud debe llegar al menos a `pre_approved` antes
 * de que la simulacion se persista, y para eso hace falta una validacion de
 * identidad **verificada** (Fase 2, P4 antes de P5). Si no hay, se rechaza con
 * `IdentityNotVerifiedException` en vez de simular con datos vacios.
 *
 * ### Plazo
 *
 * El plazo llega del cliente y se convierte con `Term::fromMonths`, que lo
 * contrasta contra el catalogo autorizado del dominio: ese es el control que
 * faltaba en VUL-02. El resto de las condiciones las calcula el motor en el
 * servidor; nada de lo que envie el navegador influye en el monto, la tasa ni
 * el pago.
 */
final readonly class SimulateCredit
{
    public function __construct(
        private ProspectRepository $prospects,
        private IdentityValidationRepository $validations,
        private CreditApplicationRepository $applications,
        private CreditRulesEngine $rulesEngine,
        private AuditLogger $auditLogger,
        private int $simulationTtlSeconds,
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

        $validation = $this->validations->findLatestFor((int) $prospect->id());

        if ($validation === null || ! $validation->result->isVerified()) {
            throw new IdentityNotVerifiedException(
                'No hay una validacion de identidad verificada para el prospecto.'
            );
        }

        $term = Term::fromMonths($requestedTermMonths);
        $offer = $this->rulesEngine->evaluateForProspect($prospect, $term);

        $application = $this->applications->findByProspectId((int) $prospect->id())
            ?? CreditApplication::open((int) $prospect->id(), null, $now);

        $this->advanceApplicationToPreApproved($application, $validation->id, $offer);
        $application = $this->applications->save($application);

        $simulation = CreditSimulation::propose(
            creditApplicationId: (int) $application->id(),
            offer: $offer,
            proposedAt: $now,
            expiresAt: $now->modify(sprintf('+%d seconds', $this->simulationTtlSeconds)),
        );
        $simulation = $this->applications->saveSimulation($simulation);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditSimulationGenerated,
            affectedEntity: 'CreditSimulation',
            affectedEntityId: $simulation->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'simulation_folio' => $simulation->simulationFolio()->value,
                'simulation_public_id' => $simulation->publicId()->value,
                'credit_type' => $offer->creditType->value,
                'term_months' => $offer->term->months,
                'proposed_amount' => $offer->proposedAmount->toDecimalString(),
                'estimated_monthly_payment' => $offer->estimatedMonthlyPayment->toDecimalString(),
                'annual_rate' => $offer->annualRate->toPercentageString(),
                'expires_at' => $simulation->expiresAt()->format(DateTimeImmutable::ATOM),
            ],
        ));

        return CreditOfferOutput::fromSimulation($simulation);
    }

    /**
     * La solicitud llega en cualquiera de tres estados aceptables: nueva
     * (draft), en revision (under_review) o ya pre-aprobada (con otra
     * simulacion anterior). Se avanza lo minimo para que la fila pueda
     * albergar una simulacion sin perder la que ya tenia.
     */
    private function advanceApplicationToPreApproved(
        CreditApplication $application,
        int $identityValidationId,
        CreditOffer $offer,
    ): void {
        $status = $application->applicationStatus();

        if ($status === ApplicationStatus::Draft) {
            $application->submitForReview($identityValidationId);
            $application->preApprove($offer);

            return;
        }

        if ($status === ApplicationStatus::UnderReview) {
            $application->preApprove($offer);

            return;
        }

        if ($status === ApplicationStatus::PreApproved) {
            // Ya esta pre-aprobada; una simulacion nueva es solo otro
            // recalculo. Los tres campos que la simulacion lee estan.
            return;
        }

        // approved / rejected / expired: la solicitud esta cerrada y no
        // admite mas simulaciones.
        throw new InvalidStateTransitionException(
            sprintf('La solicitud esta en estado %s y no admite mas simulaciones.', $status->value)
        );
    }
}
