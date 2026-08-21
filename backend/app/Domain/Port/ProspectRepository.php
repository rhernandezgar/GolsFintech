<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Identity\Curp;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;

/**
 * Puerto de persistencia del prospecto. El dominio declara QUE necesita; el como
 * (Eloquent, MySQL) vive en Infrastructure y es sustituible.
 */
interface ProspectRepository
{
    /** Inserta o actualiza y devuelve la entidad ya con su id asignado. */
    public function save(Prospect $prospect): Prospect;

    public function findByPublicId(Uuid $publicId): ?Prospect;

    /**
     * Busqueda por CURP sin descifrar la columna: el adaptador compara contra el
     * hash determinista, que es justo para lo que existe curp_hash.
     */
    public function findByCurp(Curp $curp): ?Prospect;

    public function existsWithCurp(Curp $curp): bool;
}
