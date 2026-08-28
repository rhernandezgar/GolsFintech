<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Identity\Curp;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\ApplicationSnapshot;
use App\Domain\Prospect\CaptureStatus;
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

    /**
     * Mismo criterio que el adaptador de Eloquent: la ACTIVA tiene prioridad.
     *
     * Desde VUL-17 puede haber varias con la misma CURP —una viva y las
     * abandonadas— y devolver cualquiera haria que el doble y la base
     * respondieran distinto, que es la peor propiedad que puede tener un doble:
     * la prueba pasaria y la aplicacion fallaria.
     */
    public function findByCurp(Curp $curp): ?Prospect
    {
        $abandoned = null;

        foreach ($this->prospects as $prospect) {
            if ($prospect->curp()?->value !== $curp->value) {
                continue;
            }

            if ($prospect->captureStatus() !== CaptureStatus::Abandoned) {
                return $prospect;
            }

            $abandoned = $prospect;
        }

        return $abandoned;
    }

    /**
     * Datos que el doble no puede deducir de un `Prospect` en memoria: si la
     * CURP llego a ser cliente y cuando se la rechazo. Se inyectan desde la
     * prueba, indexados por CURP.
     *
     * @var array<string, bool>
     */
    public array $customersByCurp = [];

    /** @var array<string, list<\DateTimeImmutable>> */
    public array $rejectedValidationsByCurp = [];

    /** Ultima actividad simulada, indexada por CURP. */
    public array $lastActivityByCurp = [];

    public function findApplicationByCurp(Curp $curp): ?ApplicationSnapshot
    {
        $prospect = $this->findByCurp($curp);

        if ($prospect === null) {
            return null;
        }

        return new ApplicationSnapshot(
            prospectPublicId: $prospect->publicId(),
            captureStatus: $prospect->captureStatus(),
            lastActivityAt: $this->lastActivityByCurp[$curp->value] ?? new \DateTimeImmutable,
            belongsToCustomer: $this->customersByCurp[$curp->value] ?? false,
            rejectedValidationsAt: $this->rejectedValidationsByCurp[$curp->value] ?? [],
        );
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
