<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Shared\Uuid;

/**
 * Persistencia de la solicitud de credito y de sus simulaciones.
 *
 * La simulacion se guarda, no se recalcula al vuelo, porque el diseno exige que
 * tenga folio y marca de tiempo: sin persistirla no hay forma de impedir que se
 * acepten condiciones caducadas ni de acreditar despues que fueron esas y no
 * otras las que se ofrecieron (Fase 2 §P5).
 */
interface CreditApplicationRepository
{
    public function save(CreditApplication $application): CreditApplication;

    /** La solicitud viva del prospecto; null si todavia no ha abierto ninguna. */
    public function findByProspectId(int $prospectId): ?CreditApplication;

    public function saveSimulation(CreditSimulation $simulation): CreditSimulation;

    public function findSimulationByPublicId(Uuid $publicId): ?CreditSimulation;

    public function findLatestSimulationFor(int $creditApplicationId): ?CreditSimulation;
}
