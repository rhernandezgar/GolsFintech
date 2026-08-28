<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

/**
 * Que hacer con una CURP que ya aparece en el sistema.
 *
 * La politica devuelve una decision en vez de lanzar la excepcion ella misma
 * porque dos de los cinco desenlaces exigen ESCRIBIR —abandonar el expediente
 * caducado y dejar su evento en la bitacora—, y eso es orquestacion: le toca al
 * caso de uso. Una politica que escribe deja de poder probarse sin dobles.
 */
enum ReapplicationOutcome
{
    /** No hay nada que estorbe: se puede capturar. */
    case Allow;

    /**
     * Hay un expediente en curso pero caducado. Antes de permitir el nuevo hay
     * que marcarlo `abandoned` y registrarlo (RS-09: un expediente abandonado
     * no se conserva indefinidamente con datos personales).
     */
    case AbandonPreviousThenAllow;

    /** Hay una solicitud viva. Se dice cuanto falta para poder reintentar. */
    case BlockInProgress;

    /** La CURP ya es de un cliente. Es un caso distinto y se dice distinto. */
    case BlockAlreadyCustomer;

    /** Demasiados rechazos de identidad en la ventana. */
    case BlockRetryLimit;
}

final readonly class ReapplicationDecision
{
    private function __construct(
        public ReapplicationOutcome $outcome,
        /** Minutos que faltan para poder reintentar. Null cuando no aplica. */
        public ?int $retryAfterMinutes = null,
    ) {}

    public static function allow(): self
    {
        return new self(ReapplicationOutcome::Allow);
    }

    public static function abandonPreviousThenAllow(): self
    {
        return new self(ReapplicationOutcome::AbandonPreviousThenAllow);
    }

    public static function blockInProgress(int $retryAfterMinutes): self
    {
        return new self(ReapplicationOutcome::BlockInProgress, $retryAfterMinutes);
    }

    public static function blockAlreadyCustomer(): self
    {
        return new self(ReapplicationOutcome::BlockAlreadyCustomer);
    }

    public static function blockRetryLimit(int $retryAfterMinutes): self
    {
        return new self(ReapplicationOutcome::BlockRetryLimit, $retryAfterMinutes);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === ReapplicationOutcome::Allow
            || $this->outcome === ReapplicationOutcome::AbandonPreviousThenAllow;
    }
}
