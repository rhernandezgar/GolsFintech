<?php

declare(strict_types=1);

namespace App\Application\UseCase\Credit;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Customer\RegisteredCustomer;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\CardIssuer;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Port\ProspectRepository;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * P6: aceptacion de la oferta y alta del cliente (RF-08 a RF-10).
 *
 * Se orquesta aqui porque son SEIS escrituras contra tres tablas del negocio
 * ademas de la bitacora, y todas dependen unas de otras. El caso de uso las
 * ordena y decide que se hace si la ultima falla.
 *
 * ### Verificacion de propiedad (CWE-639)
 * La simulacion llega por su UUID publico, pero eso no basta: hay que confirmar
 * que **pertenece al prospecto que trae el token**. El use case carga la
 * solicitud del prospecto autenticado y exige que la simulacion pertenezca a
 * ella. Sin esa comprobacion, un token X podria aceptar la oferta del prospecto
 * Y con solo adivinar el UUID.
 *
 * ### Asimetria transaccional (Fase 2)
 * El registro del cliente escribe cliente y linea en UNA sola transaccion
 * (`CustomerRegistry::register`). La emision de la tarjeta va aparte, fuera de
 * esa transaccion, y si el emisor falla el credito ya esta autorizado: se emite
 * `CardIssuanceFailed` y se devuelve el cliente sin tarjeta, en un estado
 * recuperable, en vez de perder todo. Ese es el argumento con el que la Fase 2
 * descarto microservicios; partirlo lo contradiria.
 *
 * ### Un evento por cada escritura (RF-13)
 * Cada operacion que escribe deja su evento: `CreditSimulationAccepted`,
 * `CreditApplicationApproved`, `CustomerCreated`, `CreditLineOpened`, y luego
 * `CardIssued` o `CardIssuanceFailed`. Sin esa cobertura la bitacora no
 * reconstruiria lo que se le mostro al cliente en P6.
 */
final readonly class AcceptCreditOffer
{
    public function __construct(
        private ProspectRepository $prospects,
        private CreditApplicationRepository $applications,
        private CustomerRegistry $customers,
        private CardIssuer $cardIssuer,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        Uuid $simulationPublicId,
        Uuid $prospectPublicId,
        string $contractVersion,
        AuditContext $context,
        DateTimeImmutable $now,
    ): RegisteredCustomer {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        $simulation = $this->applications->findSimulationByPublicId($simulationPublicId);

        if ($simulation === null) {
            throw new RuntimeException('La simulacion indicada no existe.');
        }

        $application = $this->applications->findByProspectId((int) $prospect->id());

        if ($application === null || $application->id() !== $simulation->creditApplicationId()) {
            // CWE-639: sin este control, adivinar el UUID de la simulacion
            // permitiria aceptar la oferta ajena. Aqui se cierra.
            throw new RuntimeException('La simulacion no pertenece al prospecto autenticado.');
        }

        $simulation->accept($now);
        $simulation = $this->applications->saveSimulation($simulation);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditSimulationAccepted,
            affectedEntity: 'CreditSimulation',
            affectedEntityId: $simulation->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'simulation_folio' => $simulation->simulationFolio()->value,
                'proposed_amount' => $simulation->offer()->proposedAmount->toDecimalString(),
                'term_months' => $simulation->offer()->term->months,
            ],
        ));

        $application->approve($now);
        $application = $this->applications->save($application);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditApplicationApproved,
            affectedEntity: 'CreditApplication',
            affectedEntityId: $application->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'application_folio' => $application->applicationFolio()->value,
                'credit_type' => $application->creditType()?->value,
            ],
        ));

        $registration = new NewCustomerRegistration(
            prospectId: (int) $prospect->id(),
            creditApplicationId: (int) $application->id(),
            creditSimulationId: (int) $simulation->id(),
            fullName: (string) $prospect->fullName(),
            customerNumber: Folio::generate('CU', $now),
            contractFolio: Folio::generate('CTR', $now),
            contractVersion: $contractVersion,
            consentAt: $now,
            authorizedAmount: $simulation->offer()->proposedAmount,
            annualRate: $simulation->offer()->annualRate,
            termMonths: $simulation->offer()->term->months,
        );

        $customer = $this->customers->register($registration);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CustomerCreated,
            affectedEntity: 'Customer',
            affectedEntityId: $customer->customerId,
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'customer_number' => $customer->customerNumber,
                'contract_folio' => $customer->contractFolio,
                'contract_version' => $contractVersion,
            ],
        ));

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditLineOpened,
            affectedEntity: 'CreditLine',
            affectedEntityId: $customer->creditLineId,
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'authorized_amount' => $customer->authorizedAmount,
                'currency' => $customer->currency,
            ],
        ));

        return $this->attachCardOrRecordFailure($customer, $prospect->id(), $context, $now);
    }

    private function attachCardOrRecordFailure(
        RegisteredCustomer $customer,
        ?int $prospectId,
        AuditContext $context,
        DateTimeImmutable $now,
    ): RegisteredCustomer {
        try {
            $issued = $this->cardIssuer->issue($customer->customerId, $customer->creditLineId);
            $withCard = $this->customers->attachCard($customer, $issued, $now);

            $this->auditLogger->append(new AuditEvent(
                eventType: AuditEventType::CardIssued,
                affectedEntity: 'Card',
                affectedEntityId: null,
                prospectId: $prospectId,
                context: $context,
                eventAt: $now,
                metadata: [
                    // Solo los ultimos cuatro y la marca: el token NO se
                    // registra en la bitacora (regla de seguridad 2, PCI DSS).
                    'last_four' => $withCard->cardLastFour,
                    'brand' => $withCard->cardBrand,
                    'card_status' => $withCard->cardStatus,
                ],
            ));

            return $withCard;
        } catch (Throwable $e) {
            // El credito ya esta autorizado y la linea abierta: no se cae la
            // solicitud entera. El evento deja constancia y el expediente queda
            // en un estado recuperable, sin tarjeta, que la pantalla de P6 dice.
            $this->auditLogger->append(new AuditEvent(
                eventType: AuditEventType::CardIssuanceFailed,
                affectedEntity: 'Card',
                affectedEntityId: null,
                prospectId: $prospectId,
                context: $context,
                eventAt: $now,
                metadata: [
                    'reason_class' => $e::class,
                ],
            ));

            return $customer;
        }
    }
}
