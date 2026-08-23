<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Domain\Card\CardBrand;
use App\Domain\Card\IssuedCard;
use App\Domain\Credit\AnnualRate;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Customer\RegisteredCustomer;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Alta del cliente y adjuncion de la tarjeta.
 *
 * Lo que hay que fijar es la **atomicidad asimetrica** que el disenio pide y
 * que es el argumento por el que la Fase 2 descarto microservicios:
 *
 * - `register` escribe cliente y linea en UNA transaccion. Un cliente sin linea
 *   es un estado que el negocio no contempla, y si la escritura de la linea
 *   falla la del cliente tiene que volver atras. Esta prueba fuerza un fallo
 *   sobre la linea y comprueba que no queda cliente.
 * - `attachCard` corre APARTE, sin transaccion que abarque las tres tablas. El
 *   emisor es un tercero: sostener bloqueos de base de datos durante la latencia
 *   de la red es peor que quedarse sin tarjeta un rato. Si la escritura de la
 *   tarjeta falla, cliente y linea siguen ahi y el expediente queda en un
 *   estado recuperable. Esta prueba tambien fuerza el fallo y lo comprueba.
 *
 * Del PAN completo: `IssuedCard` no tiene siquiera un campo donde ponerlo, asi
 * que no hay forma de que llegue a la base. La prueba lo comprueba mirando la
 * fila y buscando el token, los ultimos cuatro y NADA en formato de PAN.
 */
final class EloquentCustomerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): CustomerRegistry
    {
        return $this->app->make(CustomerRegistry::class);
    }

    private function makeRegistration(?int $creditSimulationId = null): NewCustomerRegistration
    {
        $prospectId = (int) DB::table('prospects')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identityValidationId = (int) DB::table('identity_validations')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'prospect_id' => $prospectId,
            'verification_folio' => 'VER-'.substr((string) Uuid::generate(), 0, 12),
            'ine_status' => 'verified',
            'renapo_status' => 'verified',
            'data_match_status' => 'verified',
            'document_validity_status' => 'verified',
            'fraud_evaluation_status' => 'passed',
            'overall_status' => 'verified',
            'attempts' => 1,
            'validated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $applicationId = (int) DB::table('credit_applications')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'prospect_id' => $prospectId,
            'identity_validation_id' => $identityValidationId,
            'application_folio' => 'APP-'.substr((string) Uuid::generate(), 0, 12),
            'credit_type' => 'personal',
            'application_status' => 'approved',
            'validated_monthly_income' => '18000.00',
            'payment_capacity' => '5400.00',
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $simulationId = $creditSimulationId ?? (int) DB::table('credit_simulations')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'credit_application_id' => $applicationId,
            'simulation_folio' => 'SIM-'.substr((string) Uuid::generate(), 0, 12),
            'proposed_amount' => '50000.00',
            'annual_rate' => '0.2850',
            'cat' => '0.3200',
            'term_months' => 24,
            'estimated_monthly_payment' => '2760.55',
            'total_payable' => '66253.20',
            'simulation_status' => 'accepted',
            'expires_at' => now()->addMinutes(15),
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $consentAt = new DateTimeImmutable('2026-08-22 12:00:00');

        return new NewCustomerRegistration(
            prospectId: $prospectId,
            creditApplicationId: $applicationId,
            creditSimulationId: $simulationId,
            fullName: 'Ana Perez Lopez',
            customerNumber: Folio::generate('CU', $consentAt),
            contractFolio: Folio::generate('CTR', $consentAt),
            contractVersion: 'v1.0',
            consentAt: $consentAt,
            authorizedAmount: Money::fromDecimalString('50000.00'),
            annualRate: AnnualRate::fromDecimalString('0.2850'),
            termMonths: 24,
        );
    }

    private function issuedCard(string $token = 'tok_visa_ABC1234'): IssuedCard
    {
        return new IssuedCard(
            tokenizedCardNumber: $token,
            lastFour: '4242',
            brand: CardBrand::Visa,
            expirationMonth: 12,
            expirationYear: 2030,
        );
    }

    public function test_register_writes_customer_and_line_and_returns_both_ids(): void
    {
        $registration = $this->makeRegistration();

        $customer = $this->registry()->register($registration);

        $this->assertGreaterThan(0, $customer->customerId);
        $this->assertGreaterThan(0, $customer->creditLineId);
        $this->assertSame($registration->customerNumber->value, $customer->customerNumber);
        $this->assertSame($registration->contractFolio->value, $customer->contractFolio);
        $this->assertSame('active', $customer->lineStatus);
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('credit_lines')->count());
        $this->assertSame(0, DB::table('cards')->count());
    }

    public function test_the_customer_row_carries_the_consent_stamp_and_the_contract_version(): void
    {
        $registration = $this->makeRegistration();

        $this->registry()->register($registration);

        $row = DB::table('customers')->first();
        $this->assertNotNull($row);
        $this->assertSame('v1.0', (string) $row->contract_version);
        // Prueba probatoria de consentimiento (P6): fecha y version del contrato
        // aceptado. Sin esto no habria soporte frente a un repudio.
        $this->assertSame('2026-08-22 12:00:00', (string) $row->consent_at);
        $this->assertSame($registration->contractFolio->value, (string) $row->contract_folio);
    }

    public function test_the_credit_line_opens_with_available_balance_equal_to_authorized_amount(): void
    {
        $registration = $this->makeRegistration();

        $this->registry()->register($registration);

        $row = DB::table('credit_lines')->first();
        $this->assertNotNull($row);
        $this->assertSame('50000.00', (string) $row->authorized_amount);
        // Al abrir la linea no hay disposiciones. Cambiar esto exige revisar
        // tambien la pantalla de P6 y la simulacion aceptada.
        $this->assertSame('50000.00', (string) $row->available_balance);
        $this->assertSame(24, (int) $row->term_months);
    }

    public function test_register_is_atomic_when_the_line_insert_fails(): void
    {
        // credit_simulation_id inexistente -> la linea revienta con FK; la
        // transaccion vuelve atras y el cliente NO queda escrito.
        $registration = $this->makeRegistration(creditSimulationId: 999_999);

        try {
            $this->registry()->register($registration);
            $this->fail('Se esperaba que la creacion de la linea fallara con FK.');
        } catch (Throwable $e) {
            // El motor detras es MySQL: la FK entre credit_lines.credit_simulation_id
            // y credit_simulations.id se dispara aqui. Si esta prueba pasara con
            // SQLite podriamos estar viendo la version de FK relajada que trae por
            // defecto, y por eso las pruebas de este archivo se corren contra MySQL.
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(0, DB::table('credit_lines')->count());
    }

    public function test_attach_card_writes_the_card_and_returns_the_customer_with_card_fields(): void
    {
        $registration = $this->makeRegistration();
        $customer = $this->registry()->register($registration);
        $card = $this->issuedCard('tok_visa_XYZ7890');

        $withCard = $this->registry()->attachCard($customer, $card, new DateTimeImmutable('2026-08-22 12:05:00'));

        $this->assertInstanceOf(RegisteredCustomer::class, $withCard);
        $this->assertSame('4242', $withCard->cardLastFour);
        $this->assertSame('visa', $withCard->cardBrand);
        $this->assertSame('12/30', $withCard->cardExpiration);
        // El estado inicial de la tarjeta es 'issued' —el default de la migracion—
        // hasta que un paso posterior la active. Mantenerlo aqui evita que el
        // adaptador imponga una transicion que no le corresponde.
        $this->assertSame('issued', $withCard->cardStatus);
        $this->assertSame(1, DB::table('cards')->count());
    }

    public function test_attach_card_never_stores_the_full_pan(): void
    {
        $registration = $this->makeRegistration();
        $customer = $this->registry()->register($registration);
        $card = $this->issuedCard('tok_visa_XYZ7890');

        $this->registry()->attachCard($customer, $card, new DateTimeImmutable('2026-08-22 12:05:00'));

        $row = DB::table('cards')->first();
        $this->assertNotNull($row);
        $this->assertSame('tok_visa_XYZ7890', (string) $row->tokenized_card_number);
        $this->assertSame('4242', (string) $row->last_four);
        // Ningun campo debe parecerse a un PAN completo (13 a 19 digitos).
        foreach ((array) $row as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b\d{13,19}\b/',
                (string) $value,
                'La fila de la tarjeta contiene algo que parece un PAN completo.'
            );
        }
    }

    public function test_find_by_customer_number_returns_customer_line_and_card(): void
    {
        $registration = $this->makeRegistration();
        $customer = $this->registry()->register($registration);
        $card = $this->issuedCard('tok_visa_LOOKUP');
        $this->registry()->attachCard($customer, $card, new DateTimeImmutable('2026-08-22 12:05:00'));

        $found = $this->registry()->findByCustomerNumber($registration->customerNumber);

        $this->assertNotNull($found);
        $this->assertSame($customer->customerId, $found->customerId);
        $this->assertSame('50000.00', $found->authorizedAmount);
        $this->assertSame('4242', $found->cardLastFour);
        $this->assertSame('visa', $found->cardBrand);
    }

    public function test_find_by_customer_number_returns_null_when_absent(): void
    {
        $this->assertNull(
            $this->registry()->findByCustomerNumber(
                Folio::generate('CU', new DateTimeImmutable('2026-08-22 12:00:00'))
            )
        );
    }

    public function test_find_by_customer_number_omits_the_card_when_never_attached(): void
    {
        $registration = $this->makeRegistration();
        $this->registry()->register($registration);

        $found = $this->registry()->findByCustomerNumber($registration->customerNumber);

        $this->assertNotNull($found);
        $this->assertNull($found->cardLastFour);
        $this->assertNull($found->cardStatus);
    }

    public function test_customer_and_line_survive_when_attach_card_fails(): void
    {
        $registration = $this->makeRegistration();
        $customer = $this->registry()->register($registration);

        // El UNIQUE de `tokenized_card_number` fuerza el fallo del segundo insert
        // sin necesidad de dobles ni de manipular la tabla al margen del adaptador.
        $card = $this->issuedCard('tok_visa_DUP');
        $this->registry()->attachCard($customer, $card, new DateTimeImmutable('2026-08-22 12:05:00'));

        try {
            $this->registry()->attachCard($customer, $card, new DateTimeImmutable('2026-08-22 12:06:00'));
            $this->fail('Se esperaba que el UNIQUE de tokenized_card_number rechazara la segunda tarjeta.');
        } catch (Throwable $e) {
            $this->assertNotSame(RuntimeException::class, $e::class);
        }

        // Punto clave: cliente y linea siguen exactamente como estaban, y solo
        // ha quedado la primera tarjeta. Perder el alta por un fallo del emisor
        // seria peor que quedarse sin tarjeta un rato.
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('credit_lines')->count());
        $this->assertSame(1, DB::table('cards')->count());
    }
}
