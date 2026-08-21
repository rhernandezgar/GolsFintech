<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Audit\AuditEvent;

/**
 * Puerto de la bitacora append-only. Solo expone append: no hay actualizacion ni
 * borrado, ni siquiera como operacion declarada (CLAUDE.md seccion 5).
 */
interface AuditLogger
{
    public function append(AuditEvent $event): void;
}
