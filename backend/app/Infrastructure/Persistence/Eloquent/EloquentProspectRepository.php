<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\Curp;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\ApplicationSnapshot;
use App\Domain\Prospect\CaptureStatus;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;
use App\Infrastructure\Security\PiiHasher;
use DateTimeImmutable;

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
        $record = $this->recordForCurp($curp);

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    /**
     * La fila que representa a esa CURP, dando SIEMPRE prioridad a la activa.
     *
     * Desde que la unicidad se acota a las solicitudes activas (VUL-17) puede
     * haber varias filas con el mismo `curp_hash`: una viva y todas las
     * abandonadas que se hayan acumulado. Un `first()` a secas devolvia la de
     * menor id —normalmente una abandonada— y con eso la politica de reintento
     * concluia que no habia nada que estorbara, dejaba pasar la captura y el
     * INSERT chocaba contra el indice unico. El sintoma era un 500; la causa,
     * mirar el expediente equivocado.
     *
     * Entre abandonadas se toma la mas reciente, que es la que conserva el
     * historial util.
     */
    private function recordForCurp(Curp $curp): ?ProspectRecord
    {
        return ProspectRecord::query()
            ->where('curp_hash', $this->hasher->hash($curp->value))
            ->orderByRaw("CASE WHEN capture_status = 'abandoned' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->first();
    }

    public function existsWithCurp(Curp $curp): bool
    {
        return ProspectRecord::query()->where('curp_hash', $this->hasher->hash($curp->value))->exists();
    }

    public function findApplicationByCurp(Curp $curp): ?ApplicationSnapshot
    {
        $record = $this->recordForCurp($curp);

        if ($record === null) {
            return null;
        }

        // `updated_at` y no `created_at`: la ventana mide inactividad, y un
        // expediente que se estuvo llenando hace un minuto no esta abandonado
        // por haberse abierto hace media hora.
        $lastActivity = $record->updated_at ?? $record->created_at;

        return new ApplicationSnapshot(
            prospectPublicId: Uuid::fromString($record->public_id),
            captureStatus: CaptureStatus::from($record->capture_status),
            lastActivityAt: DateTimeImmutable::createFromInterface($lastActivity),
            belongsToCustomer: $this->belongsToCustomer((int) $record->id),
            rejectedValidationsAt: $this->rejectedValidationsFor((int) $record->id),
        );
    }

    /**
     * La CURP es de un cliente si su expediente llego a producir uno. Se mira
     * por la solicitud de credito, que es la que enlaza prospecto y cliente.
     */
    private function belongsToCustomer(int $prospectId): bool
    {
        $applicationIds = CreditApplicationRecord::query()
            ->where('prospect_id', $prospectId)
            ->pluck('id');

        if ($applicationIds->isEmpty()) {
            return false;
        }

        return CustomerRecord::query()
            ->whereIn('credit_application_id', $applicationIds)
            ->exists();
    }

    /**
     * Fechas de los rechazos de identidad, sin filtrar por ventana: cuantos
     * cuentan lo decide la politica.
     *
     * @return list<DateTimeImmutable>
     */
    private function rejectedValidationsFor(int $prospectId): array
    {
        return IdentityValidationRecord::query()
            ->where('prospect_id', $prospectId)
            ->where('overall_status', 'rejected')
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(static fn ($at): DateTimeImmutable => DateTimeImmutable::createFromInterface($at))
            ->values()
            ->all();
    }
}
