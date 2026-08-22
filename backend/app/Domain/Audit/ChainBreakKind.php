<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Las dos formas en que se rompe la cadena, que son diagnosticos distintos.
 *
 * Se reportan por separado porque no significan lo mismo para quien responde a un
 * incidente: ContentAltered dice que ESE registro cambio despues de escribirse;
 * LinkMismatch dice que la secuencia cambio —falta un registro, sobra uno o se
 * reordenaron—, y el registro que aparece en el reporte puede ser inocente: es
 * simplemente el primero que ya no encaja con quien tiene delante.
 */
enum ChainBreakKind: string
{
    case ContentAltered = 'content_altered';
    case LinkMismatch = 'link_mismatch';
}
