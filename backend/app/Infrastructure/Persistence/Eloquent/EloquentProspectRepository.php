<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\Curp;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;
use App\Infrastructure\Security\PiiHasher;

/**
 * Adaptador de persistencia del prospecto sobre Eloquent.
 *
 * Todas las consultas van por el ORM con parametros ligados: nada de SQL
 * concatenado (regla de seguridad 5, prueba PT-04). La busqueda por CURP nunca
 * descifra la columna, compara contra curp_hash.
 */
final readonly class EloquentProspectRepository implements ProspectRepository
{
    public function __construct(
        private ProspectMapper $mapper,
        private PiiHasher $hasher,
    ) {}

    public function save(Prospect $prospect): Prospect
    {
        $attributes = $this->mapper->toAttributes($prospect);

        $record = ProspectRecord::query()->updateOrCreate(
            ['public_id' => $attributes['public_id']],
            $attributes
        );

        $prospect->assignId((int) $record->id);

        return $prospect;
    }

    public function findByPublicId(Uuid $publicId): ?Prospect
    {
        $record = ProspectRecord::query()->where('public_id', $publicId->value)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByCurp(Curp $curp): ?Prospect
    {
        $record = ProspectRecord::query()->where('curp_hash', $this->hasher->hash($curp->value))->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function existsWithCurp(Curp $curp): bool
    {
        return ProspectRecord::query()->where('curp_hash', $this->hasher->hash($curp->value))->exists();
    }
}
