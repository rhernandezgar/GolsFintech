<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Identity\IdentityDocument;
use App\Domain\Shared\Uuid;

/** Puerto de persistencia de la identificacion oficial cargada. */
interface DocumentRepository
{
    public function save(IdentityDocument $document): IdentityDocument;

    public function findByPublicId(Uuid $publicId): ?IdentityDocument;

    /** @return list<IdentityDocument> */
    public function findByProspectId(int $prospectId): array;
}
