<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Notification\Notification;

/**
 * Puerto de notificaciones al prospecto o cliente. El contenido viaja como clave de
 * plantilla y parametros, no como texto ya armado, para que ningun dato personal
 * quede escrito en la cola.
 */
interface NotificationSender
{
    public function send(Notification $notification): void;
}
