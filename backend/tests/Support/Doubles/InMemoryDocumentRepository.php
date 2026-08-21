<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\DocumentRepository;
use App\Domain\Shared\Uuid;

/** Doble en memoria del repositorio de documentos de identidad. */
final class InMemoryDocumentRepository implements DocumentRepository
{
    /** @var array<int, IdentityDocument> */
    private array $documents = [];

    private int $nextId = 1;

    public function save(IdentityDocument $document): IdentityDocument
    {
        if ($document->id() === null) {
            $document->assignId($this->nextId++);
        }

        /** @var int $id */
        $id = $document->id();
        $this->documents[$id] = $document;

        return $document;
    }

    public function findByPublicId(Uuid $publicId): ?IdentityDocument
    {
        foreach ($this->documents as $document) {
            if ($document->publicId()->value === $publicId->value) {
                return $document;
            }
        }

        return null;
    }

    /** @return list<IdentityDocument> */
    public function findByProspectId(int $prospectId): array
    {
        return array_values(array_filter(
            $this->documents,
            static fn (IdentityDocument $document): bool => $document->prospectId() === $prospectId
        ));
    }

    public function count(): int
    {
        return count($this->documents);
    }
}
