<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Exception\CreditSimulationAlreadyDecidedException;
use App\Domain\Exception\CreditSimulationExpiredException;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Simulacion presentada al prospecto en P5 (credit_simulations).
 *
 * Tiene vigencia propia: unas condiciones caducadas no pueden aceptarse mas tarde,
 * ni aunque el navegador conserve la pantalla abierta. La caducidad se evalua contra
 * la hora que entrega la capa de aplicacion, no contra el reloj del cliente.
 */
final class CreditSimulation
{
    private function __construct(
        private ?int $id,
        private readonly Uuid $publicId,
        private readonly int $creditApplicationId,
        private readonly Folio $simulationFolio,
        private readonly CreditOffer $offer,
        private SimulationStatus $simulationStatus,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $decidedAt,
    ) {}

    public static function propose(
        int $creditApplicationId,
        CreditOffer $offer,
        DateTimeImmutable $proposedAt,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            id: null,
            publicId: Uuid::generate(),
            creditApplicationId: $creditApplicationId,
            simulationFolio: Folio::generate('SIM', $proposedAt),
            offer: $offer,
            simulationStatus: SimulationStatus::Proposed,
            expiresAt: $expiresAt,
            decidedAt: null,
        );
    }

    public static function reconstitute(
        int $id,
        Uuid $publicId,
        int $creditApplicationId,
        Folio $simulationFolio,
        CreditOffer $offer,
        SimulationStatus $simulationStatus,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $decidedAt,
    ): self {
        return new self($id, $publicId, $creditApplicationId, $simulationFolio, $offer,
            $simulationStatus, $expiresAt, $decidedAt);
    }

    public function accept(DateTimeImmutable $now): void
    {
        $this->assertDecidable($now);

        $this->simulationStatus = SimulationStatus::Accepted;
        $this->decidedAt = $now;
    }

    public function reject(DateTimeImmutable $now): void
    {
        $this->assertDecidable($now);

        $this->simulationStatus = SimulationStatus::Rejected;
        $this->decidedAt = $now;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicId(): Uuid
    {
        return $this->publicId;
    }

    public function creditApplicationId(): int
    {
        return $this->creditApplicationId;
    }

    public function simulationFolio(): Folio
    {
        return $this->simulationFolio;
    }

    public function offer(): CreditOffer
    {
        return $this->offer;
    }

    public function simulationStatus(): SimulationStatus
    {
        return $this->simulationStatus;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    private function assertDecidable(DateTimeImmutable $now): void
    {
        if ($this->simulationStatus !== SimulationStatus::Proposed) {
            // Excepcion propia: doble aceptacion (o aceptacion tras rechazo,
            // o expiracion previa) es un caso de reintento con mensaje al
            // usuario propio, no un error de flujo generico. Sin este
            // control, un doble click podria crear dos clientes.
            throw new CreditSimulationAlreadyDecidedException('La simulacion ya fue decidida.');
        }

        if ($this->isExpired($now)) {
            $this->simulationStatus = SimulationStatus::Expired;

            // Excepcion propia: caducar no es un error de flujo, es una regla
            // de negocio con codigo estable para la API.
            throw new CreditSimulationExpiredException('La simulacion caduco y debe recalcularse.');
        }
    }
}
