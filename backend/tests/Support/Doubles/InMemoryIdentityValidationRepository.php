<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\RecordedIdentityValidation;
use App\Domain\Port\IdentityValidationRepository;
use DateTimeImmutable;

/**
 * Doble en memoria del repositorio de validaciones de identidad.
 *
 * Reproduce dos comportamientos que las pruebas del caso de uso necesitan
 * observar: identificadores en orden y `attempts` creciendo por prospecto —no
 * por fila—, tal como hace el adaptador Eloquent.
 */
final class InMemoryIdentityValidationRepository implements IdentityValidationRepository
{
    /** @var list<array{0: int, 1: RecordedIdentityValidation}> */
    private array $records = [];

    /** @var array<int, int> */
    private array $attemptsByProspect = [];

    private int $nextId = 1;

    public function save(
        int $prospectId,
        ?int $identityDocumentId,
        IdentityValidationResult $result,
        DateTimeImmutable $validatedAt,
    ): RecordedIdentityValidation {
        $attempts = ($this->attemptsByProspect[$prospectId] ?? 0) + 1;
        $this->attemptsByProspect[$prospectId] = $attempts;

        $recorded = new RecordedIdentityValidation(
            id: $this->nextId++,
            result: $result,
            attempts: $attempts,
            validatedAt: $validatedAt,
        );

        // Se guarda con el prospectId en una tupla propia para poder buscar por
        // prospecto sin cargar el resultado con esa referencia.
        $this->records[] = [$prospectId, $recorded];

        return $recorded;
    }

    public function findLatestFor(int $prospectId): ?RecordedIdentityValidation
    {
        for ($i = count($this->records) - 1; $i >= 0; $i--) {
            [$owner, $recorded] = $this->records[$i];
            if ($owner === $prospectId) {
                return $recorded;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->records);
    }
}
