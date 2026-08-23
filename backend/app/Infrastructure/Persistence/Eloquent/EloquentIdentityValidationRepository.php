<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\RecordedIdentityValidation;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Adaptador Eloquent de identity_validations.
 *
 * `attempts` cuenta cuantas veces se ha consultado a los proveedores por este
 * prospecto. Interesa por dos motivos: cada consulta a INE o RENAPO cuesta, y un
 * numero alto sobre el mismo expediente es la senal de que alguien esta
 * probando datos hasta que alguno pase (riesgo R-01).
 */
final readonly class EloquentIdentityValidationRepository implements IdentityValidationRepository
{
    public function save(
        int $prospectId,
        ?int $identityDocumentId,
        IdentityValidationResult $result,
        DateTimeImmutable $validatedAt,
    ): RecordedIdentityValidation {
        $attempts = IdentityValidationRecord::query()->where('prospect_id', $prospectId)->max('attempts');

        $record = IdentityValidationRecord::query()->create([
            'public_id' => Uuid::generate()->value,
            'prospect_id' => $prospectId,
            'identity_document_id' => $identityDocumentId,
            'verification_folio' => $result->verificationFolio,
            'ine_status' => $result->ineStatus->value,
            'renapo_status' => $result->renapoStatus->value,
            'data_match_status' => $result->dataMatchStatus->value,
            'document_validity_status' => $result->documentValidityStatus->value,
            'fraud_evaluation_status' => $result->fraudFlagged ? 'flagged' : 'passed',
            'overall_status' => $result->overallStatus()->value,
            // Ya enmascarada por el adaptador del proveedor (VUL-04).
            'provider_response' => $result->maskedProviderResponse,
            'attempts' => ((int) $attempts) + 1,
            'validated_at' => $validatedAt,
        ]);

        return new RecordedIdentityValidation(
            id: (int) $record->id,
            result: $result,
            attempts: (int) $record->attempts,
            validatedAt: $validatedAt,
        );
    }

    public function findLatestFor(int $prospectId): ?RecordedIdentityValidation
    {
        $record = IdentityValidationRecord::query()
            ->where('prospect_id', $prospectId)
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return null;
        }

        return new RecordedIdentityValidation(
            id: (int) $record->id,
            result: new IdentityValidationResult(
                verificationFolio: (string) $record->verification_folio,
                ineStatus: VerificationStatus::from((string) $record->ine_status),
                renapoStatus: VerificationStatus::from((string) $record->renapo_status),
                dataMatchStatus: VerificationStatus::from((string) $record->data_match_status),
                documentValidityStatus: VerificationStatus::from((string) $record->document_validity_status),
                fraudFlagged: $record->fraud_evaluation_status === 'flagged',
                maskedProviderResponse: $record->provider_response ?? [],
            ),
            attempts: (int) $record->attempts,
            validatedAt: DateTimeImmutable::createFromInterface($record->validated_at),
        );
    }
}
