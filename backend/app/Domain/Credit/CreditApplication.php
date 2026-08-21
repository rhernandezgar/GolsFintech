<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Solicitud de credito (credit_applications).
 *
 * El motivo del rechazo se guarda como CODIGO interno (rejection_reason_code) y
 * nunca como texto libre: al prospecto se le muestra un mensaje generico y el
 * detalle se queda del lado del servidor (regla de seguridad 8, riesgo R-01).
 */
final class CreditApplication
{
    /** @var array<string, list<ApplicationStatus>> */
    private const TRANSITIONS = [
        'draft' => [ApplicationStatus::UnderReview, ApplicationStatus::Rejected, ApplicationStatus::Expired],
        'under_review' => [ApplicationStatus::PreApproved, ApplicationStatus::Rejected, ApplicationStatus::Expired],
        'pre_approved' => [ApplicationStatus::Approved, ApplicationStatus::Rejected, ApplicationStatus::Expired],
        'approved' => [],
        'rejected' => [],
        'expired' => [],
    ];

    private function __construct(
        private ?int $id,
        private readonly Uuid $publicId,
        private readonly int $prospectId,
        private ?int $identityValidationId,
        private readonly Folio $applicationFolio,
        private ?CreditType $creditType,
        private ApplicationStatus $applicationStatus,
        private ?Money $validatedMonthlyIncome,
        private ?Money $paymentCapacity,
        private ?string $rejectionReasonCode,
        private ?DateTimeImmutable $decidedAt,
    ) {}

    public static function open(int $prospectId, ?int $identityValidationId, DateTimeImmutable $openedAt): self
    {
        return new self(
            id: null,
            publicId: Uuid::generate(),
            prospectId: $prospectId,
            identityValidationId: $identityValidationId,
            applicationFolio: Folio::generate('APP', $openedAt),
            creditType: null,
            applicationStatus: ApplicationStatus::Draft,
            validatedMonthlyIncome: null,
            paymentCapacity: null,
            rejectionReasonCode: null,
            decidedAt: null,
        );
    }

    public static function reconstitute(
        int $id,
        Uuid $publicId,
        int $prospectId,
        ?int $identityValidationId,
        Folio $applicationFolio,
        ?CreditType $creditType,
        ApplicationStatus $applicationStatus,
        ?Money $validatedMonthlyIncome,
        ?Money $paymentCapacity,
        ?string $rejectionReasonCode,
        ?DateTimeImmutable $decidedAt,
    ): self {
        return new self($id, $publicId, $prospectId, $identityValidationId, $applicationFolio, $creditType,
            $applicationStatus, $validatedMonthlyIncome, $paymentCapacity, $rejectionReasonCode, $decidedAt);
    }

    public function submitForReview(int $identityValidationId): void
    {
        $this->transitionTo(ApplicationStatus::UnderReview);
        $this->identityValidationId = $identityValidationId;
    }

    /** Registra el resultado del motor de reglas sobre la solicitud. */
    public function preApprove(CreditOffer $offer): void
    {
        $this->transitionTo(ApplicationStatus::PreApproved);
        $this->creditType = $offer->creditType;
        $this->validatedMonthlyIncome = $offer->validatedMonthlyIncome;
        $this->paymentCapacity = $offer->paymentCapacity;
    }

    public function approve(DateTimeImmutable $decidedAt): void
    {
        $this->transitionTo(ApplicationStatus::Approved);
        $this->decidedAt = $decidedAt;
    }

    public function reject(string $reasonCode, DateTimeImmutable $decidedAt): void
    {
        $this->transitionTo(ApplicationStatus::Rejected);
        $this->rejectionReasonCode = substr($reasonCode, 0, 40);
        $this->decidedAt = $decidedAt;
    }

    public function expire(DateTimeImmutable $decidedAt): void
    {
        $this->transitionTo(ApplicationStatus::Expired);
        $this->decidedAt = $decidedAt;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicId(): Uuid
    {
        return $this->publicId;
    }

    public function prospectId(): int
    {
        return $this->prospectId;
    }

    public function identityValidationId(): ?int
    {
        return $this->identityValidationId;
    }

    public function applicationFolio(): Folio
    {
        return $this->applicationFolio;
    }

    public function creditType(): ?CreditType
    {
        return $this->creditType;
    }

    public function applicationStatus(): ApplicationStatus
    {
        return $this->applicationStatus;
    }

    public function validatedMonthlyIncome(): ?Money
    {
        return $this->validatedMonthlyIncome;
    }

    public function paymentCapacity(): ?Money
    {
        return $this->paymentCapacity;
    }

    public function rejectionReasonCode(): ?string
    {
        return $this->rejectionReasonCode;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    private function transitionTo(ApplicationStatus $target): void
    {
        if (! in_array($target, self::TRANSITIONS[$this->applicationStatus->value], true)) {
            throw new InvalidStateTransitionException(sprintf(
                'Transicion no permitida de %s a %s en la solicitud.',
                $this->applicationStatus->value,
                $target->value
            ));
        }

        $this->applicationStatus = $target;
    }
}
