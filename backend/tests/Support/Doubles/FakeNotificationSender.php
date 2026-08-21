<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Notification\Notification;
use App\Domain\Port\NotificationSender;

/**
 * Doble programable del puerto de notificaciones. Guarda lo que se habria enviado
 * para que la prueba pueda afirmar la clave de plantilla y el destinatario, y sabe
 * fallar para cubrir el caso de la pasarela caida.
 */
final class FakeNotificationSender implements NotificationSender
{
    /** @var list<Notification> */
    private array $sent = [];

    private bool $fail = false;

    public function send(Notification $notification): void
    {
        if ($this->fail) {
            throw ExternalServiceUnavailableException::forService(
                'notification',
                'pasarela no disponible (doble de prueba)'
            );
        }

        $this->sent[] = $notification;
    }

    public function willFail(): self
    {
        $this->fail = true;

        return $this;
    }

    /** @return list<Notification> */
    public function sentNotifications(): array
    {
        return $this->sent;
    }

    /** @return list<string> */
    public function templateKeys(): array
    {
        return array_map(static fn (Notification $n): string => $n->templateKey, $this->sent);
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }
}
