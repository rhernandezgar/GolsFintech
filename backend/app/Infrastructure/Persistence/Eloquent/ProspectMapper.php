<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\Curp;
use App\Domain\Identity\Rfc;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\CaptureStatus;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Email;
use App\Domain\Shared\Money;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Shared\Uuid;
use App\Infrastructure\Security\PiiHasher;
use DateTimeImmutable;

/**
 * Traduce entre la entidad de dominio y la fila de la tabla. Toda la fealdad de la
 * persistencia —nombres de columna, cifrado, hashes de busqueda— queda aqui, que es
 * exactamente el trabajo de un adaptador.
 */
final readonly class ProspectMapper
{
    public function __construct(private PiiHasher $hasher)
    {
    }

    /** @return array<string, mixed> */
    public function toAttributes(Prospect $prospect): array
    {
        $curp = $prospect->curp();
        $rfc = $prospect->rfc();
        $income = $prospect->monthlyIncome();

        return [
            'public_id' => $prospect->publicId()->value,
            'full_name' => $prospect->fullName(),
            'curp' => $curp?->value,
            'curp_hash' => $curp === null ? null : $this->hasher->hash($curp->value),
            'rfc' => $rfc?->value,
            'rfc_hash' => $rfc === null ? null : $this->hasher->hash($rfc->value),
            'age' => $prospect->age(),
            'sex' => $prospect->sex()?->value,
            'address' => $prospect->address(),
            'geographic_location' => $prospect->geographicLocation(),
            'business_type' => $prospect->businessType(),
            'monthly_income' => $income?->toDecimalString(),
            'email' => (string) ($prospect->email() ?? '') ?: null,
            'phone' => (string) ($prospect->phone() ?? '') ?: null,
            'capture_method' => $prospect->captureMethod()->value,
            'capture_status' => $prospect->captureStatus()->value,
            'privacy_notice_accepted_at' => $prospect->privacyNoticeAcceptedAt(),
        ];
    }

    public function toDomain(ProspectRecord $record): Prospect
    {
        $acceptedAt = $record->privacy_notice_accepted_at;

        return Prospect::reconstitute(
            id: (int) $record->id,
            publicId: Uuid::fromString((string) $record->public_id),
            fullName: $record->full_name,
            curp: $record->curp === null ? null : Curp::fromString((string) $record->curp),
            rfc: $record->rfc === null ? null : Rfc::fromString((string) $record->rfc),
            age: $record->age === null ? null : (int) $record->age,
            sex: $record->sex === null ? null : Sex::from((string) $record->sex),
            address: $record->address,
            geographicLocation: $record->geographic_location,
            businessType: $record->business_type,
            monthlyIncome: $record->monthly_income === null
                ? null
                : Money::fromDecimalString((string) $record->monthly_income),
            email: $record->email === null ? null : Email::fromString((string) $record->email),
            phone: $record->phone === null ? null : PhoneNumber::fromString((string) $record->phone),
            captureMethod: CaptureMethod::from((string) $record->capture_method),
            captureStatus: CaptureStatus::from((string) $record->capture_status),
            privacyNoticeAcceptedAt: $acceptedAt === null
                ? null
                : new DateTimeImmutable($acceptedAt->format('Y-m-d H:i:s')),
        );
    }
}
