<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Credit\CreditApplication;
use App\Domain\Credit\CreditSimulation;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Shared\Uuid;

/**
 * Doble en memoria del repositorio de solicitudes y simulaciones de credito.
 *
 * Como el adaptador Eloquent, `save` es idempotente sobre `public_id`: guardar
 * la misma solicitud dos veces no la duplica, sobrescribe la anterior.
 */
final class InMemoryCreditApplicationRepository implements CreditApplicationRepository
{
    /** @var array<int, CreditApplication> */
    private array $applications = [];

    /** @var array<int, CreditSimulation> */
    private array $simulations = [];

    private int $nextApplicationId = 1;

    private int $nextSimulationId = 1;

    public function save(CreditApplication $application): CreditApplication
    {
        if ($application->id() === null) {
            $existing = $this->findByPublicId($application->publicId());

            if ($existing !== null) {
                $application->assignId((int) $existing->id());
            } else {
                $application->assignId($this->nextApplicationId++);
            }
        }

        $this->applications[(int) $application->id()] = $application;

        return $application;
    }

    public function findByProspectId(int $prospectId): ?CreditApplication
    {
        $latest = null;
        foreach ($this->applications as $application) {
            if ($application->prospectId() === $prospectId) {
                if ($latest === null || (int) $application->id() > (int) $latest->id()) {
                    $latest = $application;
                }
            }
        }

        return $latest;
    }

    public function saveSimulation(CreditSimulation $simulation): CreditSimulation
    {
        if ($simulation->id() === null) {
            $existing = $this->findSimulationByPublicId($simulation->publicId());

            if ($existing !== null) {
                $simulation->assignId((int) $existing->id());
            } else {
                $simulation->assignId($this->nextSimulationId++);
            }
        }

        $this->simulations[(int) $simulation->id()] = $simulation;

        return $simulation;
    }

    public function findSimulationByPublicId(Uuid $publicId): ?CreditSimulation
    {
        foreach ($this->simulations as $simulation) {
            if ($simulation->publicId()->equals($publicId)) {
                return $simulation;
            }
        }

        return null;
    }

    public function findLatestSimulationFor(int $creditApplicationId): ?CreditSimulation
    {
        $latest = null;
        foreach ($this->simulations as $simulation) {
            if ($simulation->creditApplicationId() === $creditApplicationId) {
                if ($latest === null || (int) $simulation->id() > (int) $latest->id()) {
                    $latest = $simulation;
                }
            }
        }

        return $latest;
    }

    private function findByPublicId(Uuid $publicId): ?CreditApplication
    {
        foreach ($this->applications as $application) {
            if ($application->publicId()->equals($publicId)) {
                return $application;
            }
        }

        return null;
    }
}
