<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use RuntimeException;

/**
 * La carga no cumple los controles de VUL-01.
 *
 * El mensaje ya es apto para el cliente: se redacta generico en el punto donde
 * se lanza, sin decir que tipo se detecto ni que limite se supero por cuanto
 * (regla de seguridad no negociable 8).
 */
final class DocumentUploadRejected extends RuntimeException {}
