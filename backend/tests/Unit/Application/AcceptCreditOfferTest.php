<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\Credit\AcceptCreditOffer;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Credit\AnnualRate;
use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditOffer;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Credit\CreditType;
use App\Domain\Credit\Term;
use App\Domain\Exception\CreditSimulationExpiredException;
use App\Domain\Identity\Curp;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Doubles\FakeCardIssuer;
use Tests\Support\Doubles\InMemoryAuditLogger;
use Tests\Support\Doubles\InMemoryCreditApplicationRepository;
use Tests\Support\Doubles\InMemoryCustomerRegistry;
use Tests\Support\Doubles\InMemoryProspectRepository;

/**
 * P6: aceptacion de la oferta.
 *
 * Se comprueban las tres invariantes que sostienen el paso:
 *
 * 1. Un token X no puede aceptar la oferta del prospecto Y aunque adivine el
 *    UUID de la simulacion (CWE-639).
 * 2. Cada escritura deja su evento en bitacora, en el orden que corresponde
 *    (RF-13). La secuencia con exito es:
 *      credit_simulation.accepted -> credit_application.approved ->
 *      customer.created -> credit_line.opened -> card.issued
 *    y con fallo del emisor termina en `card.issuance_failed`.
 * 3. Si el emisor de tarjetas falla, cliente y linea siguen creados: el credito
 *    ya autorizado no se pierde por un fallo del tercero (Fase 2, asimetria).
 */
final class AcceptCreditOfferTest extends TestCase
{
    private InMemoryProspectRepository $prospects;

    private InMemoryCreditApplicationRepository $applications;

    private InMemoryCustomerRegistry $customers;

    private FakeCardIssuer $cardIssuer;

    private InMemoryAuditLogger $audit;

    protected function setUp(): void
    {
        $this->prospects = new InMemoryProspectRepository;
        $this->applications = new InMemoryCreditApplicationRepository;
        $this->customers = new InMemoryCustomerRegistry;
        $this->cardIssuer = new FakeCardIssuer;
        $this->audit = new InMemoryAuditLogger;
    }

    private function useCase(): AcceptCreditOffer
    {
        return new AcceptCreditOffer(
            $this->prospects,
            $this->applications,
            $this->customers,
            $this->cardIssuer,
            $this->audit,
        );
    }

    private function context(): AuditContext
    {
        return new AuditContext('user:test', '198.51.100.7');
    }

    private function storedProspect(string $curp = 'HEGG560427MVZRRL04'): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-22 11:00:00'));
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString($curp),
            rfc: null,
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
        );
        $prospect->confirmData();

        return $this->prospects->save($prospect);
    }

    private function offer(): CreditOffer
    {
        return new CreditOffer(
            creditType: CreditType::Personal,
            validatedMonthlyIncome: Money::fromDecimalString('18000.00'),
            paymentCapacity: Money::fromDecimalString('5400.00'),
            proposedAmount: Money::fromDecimalString('50000.00'),
            annualRate: AnnualRate::fromDecimalString('0.2850'),
            cat: AnnualRate::fromDecimalString('0.3200'),
            term: Term::fromMonths(24),
            estimatedMonthlyPayment: Money::fromDecimalString('2760.55'),
            totalPayable: Money::fromDecimalString('66253.20'),
        );
    }

    private function storedApplicationWithSimulation(
        Prospect $prospect,
        ?DateTimeImmutable $expiresAt = null,
    ): CreditSimulation {
        $application = CreditApplication::open((int) $prospect->id(), null, new DateTimeImmutable('2026-08-22 11:30:00'));
        $application->submitForReview(identityValidationId: 1);
        $application->preApprove($this->offer());
        $this->applications->save($application);

        $simulation = CreditSimulation::propose(
            creditApplicationId: (int) $application->id(),
            offer: $this->offer(),
            proposedAt: new DateTimeImmutable('2026-08-22 11:45:00'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('2026-08-22 12:15:00'),
        );

        return $this->applications->saveSimulation($simulation);
    }

    public function test_the_happy_path_emits_the_five_events_in_order(): void
    {
        $prospect = $this->storedProspect();
        $simulation = $this->storedApplicationWithSimulation($prospect);

        $customer = $this->useCase()->execute(
            $simulation->publicId(),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        $this->assertNotNull($customer->cardLastFour);
        $this->assertSame(
            [
                AuditEventType::CreditSimulationAccepted->value,
                AuditEventType::CreditApplicationApproved->value,
                AuditEventType::CustomerCreated->value,
                AuditEventType::CreditLineOpened->value,
                AuditEventType::CardIssued->value,
            ],
            $this->audit->eventTypes(),
        );
        $this->assertSame(1, $this->customers->customerCount());
        $this->assertSame(1, $this->customers->cardCount());
    }

    public function test_a_token_from_another_prospect_cannot_accept_the_simulation(): void
    {
        // El dueno de la simulacion es "owner". "stranger" tiene otro UUID de
        // prospecto pero prueba a mandar el UUID de la simulacion ajena.
        $owner = $this->storedProspect('HEGG560427MVZRRL04');
        // XEXX... son CURPs de test que ya usa SimulatedAdaptersTest.
        $stranger = $this->storedProspect('XEXX010101HNEXXXA4');
        $simulation = $this->storedApplicationWithSimulation($owner);

        $this->expectException(RuntimeException::class);
        $this->useCase()->execute(
            $simulation->publicId(),
            $stranger->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }

    public function test_an_expired_simulation_is_not_accepted(): void
    {
        $prospect = $this->storedProspect();
        $simulation = $this->storedApplicationWithSimulation(
            $prospect,
            expiresAt: new DateTimeImmutable('2026-08-22 11:59:00'),
        );

        // Excepcion propia: caducar no es un error de flujo, es una regla de
        // negocio con su propio codigo estable para la API.
        $this->expectException(CreditSimulationExpiredException::class);
        $this->useCase()->execute(
            $simulation->publicId(),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }

    public function test_card_issuance_failure_leaves_customer_and_line_and_records_the_event(): void
    {
        $prospect = $this->storedProspect();
        $simulation = $this->storedApplicationWithSimulation($prospect);
        // Se hace fallar el emisor externo. La atomicidad asimetrica del
        // disenio impide que el credito ya autorizado se pierda por eso.
        $this->cardIssuer->willFail();

        $customer = $this->useCase()->execute(
            $simulation->publicId(),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        // Cliente y linea siguen ahi; la tarjeta no.
        $this->assertSame(1, $this->customers->customerCount());
        $this->assertSame(0, $this->customers->cardCount());
        $this->assertNull($customer->cardLastFour);

        $types = $this->audit->eventTypes();
        $last = end($types);
        $this->assertSame(AuditEventType::CardIssuanceFailed->value, $last);
        $this->assertContains(AuditEventType::CustomerCreated->value, $types);
        $this->assertContains(AuditEventType::CreditLineOpened->value, $types);
    }

    public function test_the_card_issued_event_never_carries_the_token(): void
    {
        $prospect = $this->storedProspect();
        $simulation = $this->storedApplicationWithSimulation($prospect);

        $this->useCase()->execute(
            $simulation->publicId(),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        $events = $this->audit->events();
        $card = end($events);
        $this->assertSame(AuditEventType::CardIssued, $card->eventType);
        // El token no viaja al log ni por descuido: solo los ultimos cuatro y
        // la marca (regla de seguridad 2, PCI DSS).
        $this->assertArrayNotHasKey('tokenized_card_number', $card->metadata);
        $this->assertArrayNotHasKey('token', $card->metadata);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) ($card->metadata['last_four'] ?? ''));
    }

    public function test_a_missing_simulation_is_refused(): void
    {
        $prospect = $this->storedProspect();

        $this->expectException(RuntimeException::class);
        $this->useCase()->execute(
            Uuid::generate(),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }

    public function test_a_missing_prospect_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->useCase()->execute(
            Uuid::generate(),
            Uuid::generate(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }
}
