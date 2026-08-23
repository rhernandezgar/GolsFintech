<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\Customer\LookupCustomer;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Card\CardBrand;
use App\Domain\Card\IssuedCard;
use App\Domain\Credit\AnnualRate;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Doubles\InMemoryAuditLogger;
use Tests\Support\Doubles\InMemoryCustomerRegistry;

/**
 * P7: la consulta del cliente es en si misma un evento auditable (RS-06.a).
 *
 * Se comprueban las dos ramas: la que encuentra al cliente escribe
 * `customer.looked_up` con actor e IP y devuelve la vista de P7; la que no
 * encuentra escribe `auth.authorization_denied` con `reason=customer_not_found`,
 * de modo que un barrido por numero deje traza (riesgo R-01).
 */
final class LookupCustomerTest extends TestCase
{
    private InMemoryCustomerRegistry $customers;

    private InMemoryAuditLogger $audit;

    protected function setUp(): void
    {
        $this->customers = new InMemoryCustomerRegistry;
        $this->audit = new InMemoryAuditLogger;
    }

    private function useCase(): LookupCustomer
    {
        return new LookupCustomer($this->customers, $this->audit);
    }

    private function context(): AuditContext
    {
        return new AuditContext('user:soporte', '198.51.100.42');
    }

    private function registeredCustomer(Folio $number): void
    {
        $now = new DateTimeImmutable('2026-08-22 12:00:00');

        $registration = new NewCustomerRegistration(
            prospectId: 1,
            creditApplicationId: 1,
            creditSimulationId: 1,
            fullName: 'Ana Perez Lopez',
            customerNumber: $number,
            contractFolio: Folio::generate('CTR', $now),
            contractVersion: 'v1.0',
            consentAt: $now,
            authorizedAmount: Money::fromDecimalString('50000.00'),
            annualRate: AnnualRate::fromDecimalString('0.2850'),
            termMonths: 24,
        );

        $customer = $this->customers->register($registration);
        $card = new IssuedCard('tok_visa_XYZ', '4242', CardBrand::Visa, 12, 2030);
        $this->customers->attachCard($customer, $card, $now);
    }

    public function test_finding_the_customer_emits_customer_looked_up_and_returns_the_view(): void
    {
        $number = Folio::generate('CU', new DateTimeImmutable('2026-08-22 12:00:00'));
        $this->registeredCustomer($number);

        $customer = $this->useCase()->execute(
            $number,
            $this->context(),
            new DateTimeImmutable('2026-08-22 12:05:00'),
        );

        $this->assertSame($number->value, $customer->customerNumber);
        $this->assertSame('4242', $customer->cardLastFour);
        $this->assertSame([AuditEventType::CustomerLookedUp->value], $this->audit->eventTypes());

        $event = $this->audit->events()[0];
        $this->assertSame('user:soporte', $event->context->actor);
        $this->assertSame('198.51.100.42', $event->context->ipAddress);
        // Los ultimos cuatro no son PAN: son lo que el cliente reconoce como
        // suyo, y por eso viajan en la metadata.
        $this->assertSame('4242', $event->metadata['card_last_four']);
    }

    public function test_a_missing_customer_leaves_authorization_denied_and_throws(): void
    {
        $number = Folio::generate('CU', new DateTimeImmutable('2026-08-22 12:00:00'));

        try {
            $this->useCase()->execute(
                $number,
                $this->context(),
                new DateTimeImmutable('2026-08-22 12:05:00'),
            );
            $this->fail('Se esperaba una excepcion cuando no existe el cliente.');
        } catch (RuntimeException) {
            // La excepcion se propaga: al cliente se le muestra un mensaje
            // generico; lo que este test fija es el rastro auditable.
        }

        $this->assertSame([AuditEventType::AuthorizationDenied->value], $this->audit->eventTypes());
        $event = $this->audit->events()[0];
        $this->assertSame('customer_not_found', $event->metadata['reason']);
        $this->assertSame($number->value, $event->metadata['customer_number']);
    }
}
