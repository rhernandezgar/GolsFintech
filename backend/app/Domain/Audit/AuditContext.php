<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Quien ejecuta la accion y desde donde. Lo arma la capa de entrada (HTTP o worker)
 * y lo entrega a los casos de uso: el dominio no conoce la peticion HTTP.
 */
final readonly class AuditContext
{
    public function __construct(
        public string $actor,
        public ?string $ipAddress = null,
    ) {
    }

    /** Acciones ejecutadas por el propio sistema, sin usuario detras (worker, cron). */
    public static function system(string $component): self
    {
        return new self(sprintf('system:%s', $component));
    }
}
