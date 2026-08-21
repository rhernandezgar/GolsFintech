<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Identity\Curp;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;

/**
 * Doble en memoria del repositorio de prospectos.
 *
 * Permite probar un caso de uso completo sin base de datos: la prueba deja de medir
 * MySQL y pasa a medir la regla de negocio, y corre en milisegundos.
 *
 * Asigna los identificadores en orden (1, 2, 3...) a proposito: un doble que
 * devolviera identificadores al azar haria que dos ejecuciones de la misma prueba
 * no fueran comparables.
 */
final class InMemoryProspectRepository implements ProspectRepository
{
    /** @var array<int, Prospect> */
    private array $prospects = [];

    private int $nextId = 1;

    public function save(Prospect $prospect): Prospect
    {
        if ($prospect->id() === null) {
            $prospect->assignId($this->nextId++);
        }

        /** @var int $id */
        $id = $prospect->id();
        $this->prospects[$id] = $prospect;

        return $prospect;
    }

    public function findByPublicId(Uuid $publicId): ?Prospect
    {
        foreach ($this->prospects as $prospect) {
            if ($prospect->publicId()->value === $publicId->value) {
                return $prospect;
            }
        }

        return null;
    }

    public function findByCurp(Curp $curp): ?Prospect
    {
        foreach ($this->prospects as $prospect) {
            if ($prospect->curp()?->value === $curp->value) {
                return $prospect;
            }
        }

        return null;
    }

    public function existsWithCurp(Curp $curp): bool
    {
        return $this->findByCurp($curp) !== null;
    }

    /** @return list<Prospect> */
    public function all(): array
    {
        return array_values($this->prospects);
    }

    public function count(): int
    {
        return count($this->prospects);
    }
}
