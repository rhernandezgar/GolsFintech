<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/** Estado de la simulacion presentada en P5 (credit_simulations.simulation_status). */
enum SimulationStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
