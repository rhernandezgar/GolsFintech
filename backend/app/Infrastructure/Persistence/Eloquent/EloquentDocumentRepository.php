<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\DocumentRepository;
use App\Domain\Shared\Uuid;

/** Adaptador de persistencia de la identificacion oficial sobre Eloquent. */
final readonly class EloquentDocumentRepository implements DocumentRepository
{
    public function __construct(private IdentityDocumentMapper $mapper) {}

    public function save(IdentityDocument $document): IdentityDocument
    {
        $attributes = $this->mapper->toAttributes($document);

        $record = IdentityDocumentRecord::query()->updateOrCreate(
            ['public_id' => $attributes['public_id']],
            $attributes
        );

        $document->assignId((int) $record->id);

        return $document;
    }

    public function findByPublicId(Uuid $publicId): ?IdentityDocument
    {
        $record = IdentityDocumentRecord::query()->where('public_id', $publicId->value)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByProspectId(int $prospectId): array
    {
        return IdentityDocumentRecord::query()
            ->where('prospect_id', $prospectId)
            ->orderBy('id')
            ->get()
            ->map(fn (IdentityDocumentRecord $record): IdentityDocument => $this->mapper->toDomain($record))
            ->all();
    }
}
