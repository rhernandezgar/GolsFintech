<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Credit\AnnualRate;
use App\Domain\Credit\ApplicationStatus;
use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditOffer;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Credit\CreditType;
use App\Domain\Credit\SimulationStatus;
use App\Domain\Credit\Term;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Adaptador Eloquent de la solicitud de credito y sus simulaciones.
 *
 * La simulacion no guarda el tipo de credito ni el ingreso validado: viven en la
 * solicitud, que es su duena. Al reconstituir la oferta se leen de alli, de modo
 * que no puedan quedar dos versiones del mismo dato contradiciendose.
 */
final readonly class EloquentCreditApplicationRepository implements CreditApplicationRepository
{
    public function save(CreditApplication $application): CreditApplication
    {
        $record = CreditApplicationRecord::query()
            ->firstOrNew(['public_id' => $application->publicId()->value]);

        $record->fill([
            'prospect_id' => $application->prospectId(),
            'identity_validation_id' => $application->identityValidationId(),
            'application_folio' => $application->applicationFolio()->value,
            'credit_type' => $application->creditType()?->value,
            'application_status' => $application->applicationStatus()->value,
            'validated_monthly_income' => $application->validatedMonthlyIncome()?->toDecimalString(),
            'payment_capacity' => $application->paymentCapacity()?->toDecimalString(),
            'rejection_reason_code' => $application->rejectionReasonCode(),
            'decided_at' => $application->decidedAt(),
        ])->save();

        $application->assignId((int) $record->id);

        return $application;
    }

    public function findByProspectId(int $prospectId): ?CreditApplication
    {
        $record = CreditApplicationRecord::query()
            ->where('prospect_id', $prospectId)
            ->orderByDesc('id')
            ->first();

        return $record === null ? null : $this->toApplication($record);
    }

    public function saveSimulation(CreditSimulation $simulation): CreditSimulation
    {
        $offer = $simulation->offer();

        $record = CreditSimulationRecord::query()
            ->firstOrNew(['public_id' => $simulation->publicId()->value]);

        $record->fill([
            'credit_application_id' => $simulation->creditApplicationId(),
            'simulation_folio' => $simulation->simulationFolio()->value,
            'proposed_amount' => $offer->proposedAmount->toDecimalString(),
            'annual_rate' => $offer->annualRate->toDecimalString(),
            'cat' => $offer->cat->toDecimalString(),
            'term_months' => $offer->term->months,
            'estimated_monthly_payment' => $offer->estimatedMonthlyPayment->toDecimalString(),
            'total_payable' => $offer->totalPayable->toDecimalString(),
            'simulation_status' => $simulation->simulationStatus()->value,
            'expires_at' => $simulation->expiresAt(),
            'decided_at' => $simulation->decidedAt(),
        ])->save();

        $simulation->assignId((int) $record->id);

        return $simulation;
    }

    public function findSimulationByPublicId(Uuid $publicId): ?CreditSimulation
    {
        $record = CreditSimulationRecord::query()->where('public_id', $publicId->value)->first();

        return $record === null ? null : $this->toSimulation($record);
    }

    public function findLatestSimulationFor(int $creditApplicationId): ?CreditSimulation
    {
        $record = CreditSimulationRecord::query()
            ->where('credit_application_id', $creditApplicationId)
            ->orderByDesc('id')
            ->first();

        return $record === null ? null : $this->toSimulation($record);
    }

    private function toApplication(CreditApplicationRecord $record): CreditApplication
    {
        return CreditApplication::reconstitute(
            id: (int) $record->id,
            publicId: Uuid::fromString((string) $record->public_id),
            prospectId: (int) $record->prospect_id,
            identityValidationId: $record->identity_validation_id === null ? null : (int) $record->identity_validation_id,
            applicationFolio: Folio::fromString((string) $record->application_folio),
            creditType: $record->credit_type === null ? null : CreditType::from((string) $record->credit_type),
            applicationStatus: ApplicationStatus::from((string) $record->application_status),
            validatedMonthlyIncome: $record->validated_monthly_income === null
                ? null : Money::fromDecimalString((string) $record->validated_monthly_income),
            paymentCapacity: $record->payment_capacity === null
                ? null : Money::fromDecimalString((string) $record->payment_capacity),
            rejectionReasonCode: $record->rejection_reason_code,
            decidedAt: $record->decided_at === null
                ? null : DateTimeImmutable::createFromInterface($record->decided_at),
        );
    }

    private function toSimulation(CreditSimulationRecord $record): CreditSimulation
    {
        $application = CreditApplicationRecord::query()->findOrFail($record->credit_application_id);

        $offer = new CreditOffer(
            creditType: CreditType::from((string) $application->credit_type),
            validatedMonthlyIncome: Money::fromDecimalString((string) $application->validated_monthly_income),
            paymentCapacity: Money::fromDecimalString((string) $application->payment_capacity),
            proposedAmount: Money::fromDecimalString((string) $record->proposed_amount),
            annualRate: AnnualRate::fromDecimalString((string) $record->annual_rate),
            cat: AnnualRate::fromDecimalString((string) $record->cat),
            term: Term::fromMonths((int) $record->term_months),
            estimatedMonthlyPayment: Money::fromDecimalString((string) $record->estimated_monthly_payment),
            totalPayable: Money::fromDecimalString((string) $record->total_payable),
        );

        return CreditSimulation::reconstitute(
            id: (int) $record->id,
            publicId: Uuid::fromString((string) $record->public_id),
            creditApplicationId: (int) $record->credit_application_id,
            simulationFolio: Folio::fromString((string) $record->simulation_folio),
            offer: $offer,
            simulationStatus: SimulationStatus::from((string) $record->simulation_status),
            expiresAt: DateTimeImmutable::createFromInterface($record->expires_at),
            decidedAt: $record->decided_at === null
                ? null : DateTimeImmutable::createFromInterface($record->decided_at),
        );
    }
}
