<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Domain\Credit\AnnualRate;
use App\Domain\Credit\ApplicationStatus;
use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditOffer;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Credit\CreditType;
use App\Domain\Credit\SimulationStatus;
use App\Domain\Credit\Term;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adaptador de persistencia de la solicitud de credito y sus simulaciones.
 *
 * Los dos puntos frontera del disenio:
 * - la simulacion no guarda ni el tipo de credito ni el ingreso validado; viven
 *   en la solicitud, que es su duenia. Al reconstruir la oferta se leen desde
 *   alli para que no puedan quedar dos versiones del mismo dato;
 * - `save` es idempotente sobre `public_id`: reescribir la solicitud no crea
 *   una fila nueva ni la duplica.
 */
final class EloquentCreditApplicationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): CreditApplicationRepository
    {
        return $this->app->make(CreditApplicationRepository::class);
    }

    private function makeProspect(): int
    {
        return (int) DB::table('prospects')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_captured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openedApplication(int $prospectId): CreditApplication
    {
        return CreditApplication::open($prospectId, null, new DateTimeImmutable('2026-08-22 12:00:00'));
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

    public function test_saving_a_new_application_assigns_an_id_and_persists_the_status(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);

        $saved = $this->repository()->save($application);

        $this->assertNotNull($saved->id());
        $this->assertSame(ApplicationStatus::Draft, $saved->applicationStatus());
        $this->assertSame(1, DB::table('credit_applications')->count());
    }

    public function test_reconstitute_preserves_public_id_folio_status_and_amounts(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);
        $application->submitForReview(identityValidationId: $this->makeIdentityValidation($prospectId));
        $application->preApprove($this->offer());
        $saved = $this->repository()->save($application);

        $loaded = $this->repository()->findByProspectId($prospectId);

        $this->assertNotNull($loaded);
        $this->assertTrue($saved->publicId()->equals($loaded->publicId()));
        $this->assertSame($saved->applicationFolio()->value, $loaded->applicationFolio()->value);
        $this->assertSame(ApplicationStatus::PreApproved, $loaded->applicationStatus());
        $this->assertSame(CreditType::Personal, $loaded->creditType());
        $this->assertSame('18000.00', $loaded->validatedMonthlyIncome()?->toDecimalString());
        $this->assertSame('5400.00', $loaded->paymentCapacity()?->toDecimalString());
    }

    public function test_save_is_idempotent_on_public_id(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);

        $this->repository()->save($application);
        $application->submitForReview(identityValidationId: $this->makeIdentityValidation($prospectId));
        $this->repository()->save($application);

        $this->assertSame(1, DB::table('credit_applications')->count());
        $this->assertSame(
            ApplicationStatus::UnderReview,
            $this->repository()->findByProspectId($prospectId)?->applicationStatus()
        );
    }

    public function test_saving_a_simulation_persists_the_offer_fields(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);
        $application->submitForReview(identityValidationId: $this->makeIdentityValidation($prospectId));
        $application->preApprove($this->offer());
        $saved = $this->repository()->save($application);

        $simulation = CreditSimulation::propose(
            creditApplicationId: (int) $saved->id(),
            offer: $this->offer(),
            proposedAt: new DateTimeImmutable('2026-08-22 12:00:00'),
            expiresAt: new DateTimeImmutable('2026-08-22 12:15:00'),
        );

        $storedSimulation = $this->repository()->saveSimulation($simulation);

        $this->assertGreaterThan(0, $storedSimulation->id());
        $row = DB::table('credit_simulations')->first();
        $this->assertNotNull($row);
        $this->assertSame('50000.00', (string) $row->proposed_amount);
        $this->assertSame('0.2850', (string) $row->annual_rate);
        $this->assertSame(24, (int) $row->term_months);
        $this->assertSame(SimulationStatus::Proposed->value, (string) $row->simulation_status);
    }

    public function test_find_simulation_by_public_id_reconstitutes_the_offer_from_the_application(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);
        $application->submitForReview(identityValidationId: $this->makeIdentityValidation($prospectId));
        $application->preApprove($this->offer());
        $saved = $this->repository()->save($application);

        $simulation = CreditSimulation::propose(
            creditApplicationId: (int) $saved->id(),
            offer: $this->offer(),
            proposedAt: new DateTimeImmutable('2026-08-22 12:00:00'),
            expiresAt: new DateTimeImmutable('2026-08-22 12:15:00'),
        );
        $stored = $this->repository()->saveSimulation($simulation);

        $loaded = $this->repository()->findSimulationByPublicId($stored->publicId());

        $this->assertNotNull($loaded);
        $this->assertTrue($stored->publicId()->equals($loaded->publicId()));
        $this->assertSame(CreditType::Personal, $loaded->offer()->creditType);
        // El ingreso validado y la capacidad viajan por la solicitud, no por la
        // fila de la simulacion: si el join se rompiera, este bloque cambiaria.
        $this->assertSame('18000.00', $loaded->offer()->validatedMonthlyIncome->toDecimalString());
        $this->assertSame('5400.00', $loaded->offer()->paymentCapacity->toDecimalString());
        $this->assertSame('50000.00', $loaded->offer()->proposedAmount->toDecimalString());
        $this->assertSame(24, $loaded->offer()->term->months);
    }

    public function test_find_latest_simulation_returns_the_most_recent_by_id(): void
    {
        $prospectId = $this->makeProspect();
        $application = $this->openedApplication($prospectId);
        $application->submitForReview(identityValidationId: $this->makeIdentityValidation($prospectId));
        $application->preApprove($this->offer());
        $saved = $this->repository()->save($application);

        $older = $this->repository()->saveSimulation(CreditSimulation::propose(
            creditApplicationId: (int) $saved->id(),
            offer: $this->offer(),
            proposedAt: new DateTimeImmutable('2026-08-22 12:00:00'),
            expiresAt: new DateTimeImmutable('2026-08-22 12:15:00'),
        ));
        $newer = $this->repository()->saveSimulation(CreditSimulation::propose(
            creditApplicationId: (int) $saved->id(),
            offer: $this->offer(),
            proposedAt: new DateTimeImmutable('2026-08-22 12:01:00'),
            expiresAt: new DateTimeImmutable('2026-08-22 12:16:00'),
        ));

        $latest = $this->repository()->findLatestSimulationFor((int) $saved->id());

        $this->assertNotNull($latest);
        $this->assertSame($newer->id(), $latest->id());
        $this->assertNotSame($older->id(), $latest->id());
    }

    public function test_find_by_prospect_id_returns_null_when_there_is_no_application(): void
    {
        $prospectId = $this->makeProspect();

        $this->assertNull($this->repository()->findByProspectId($prospectId));
    }

    /**
     * Fila minima en `identity_validations` para poder anclar la solicitud a un
     * identity_validation_id valido. La FK es cascadeOnUpdate/restrictOnDelete y
     * no aceptaria una referencia inventada.
     */
    private function makeIdentityValidation(int $prospectId): int
    {
        return (int) DB::table('identity_validations')->insertGetId([
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
    }
}
