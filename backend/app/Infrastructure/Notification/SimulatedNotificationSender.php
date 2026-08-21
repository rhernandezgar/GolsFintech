<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Notification\Notification;
use App\Domain\Port\NotificationSender;
use Psr\Log\LoggerInterface;

/**
 * Adaptador de notificaciones simulado (RF-09).
 *
 * No envia correo ni SMS: deja constancia en el registro del servidor. Es lo que
 * necesita el entorno de desarrollo —comprobar QUE se habria enviado y a quien—
 * sin contratar pasarela ni exponer una direccion real.
 *
 * El destinatario se registra ENMASCARADO. Un correo o un telefono son datos
 * personales, y el registro del servidor es persistente: escribirlos completos
 * seria la misma fuga que VUL-04, solo que por otra puerta. Del contenido solo se
 * anota la clave de plantilla, nunca el texto armado.
 */
final readonly class SimulatedNotificationSender implements NotificationSender
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $fail = false,
    ) {}

    public function send(Notification $notification): void
    {
        if ($this->fail) {
            throw ExternalServiceUnavailableException::forService(
                'notification',
                'la pasarela de notificaciones no acepto el envio (simulado)'
            );
        }

        $this->logger->info('Notificacion simulada: no se envio nada', [
            'channel' => $notification->channel->value,
            'recipient' => self::maskRecipient($notification->recipient),
            'template_key' => $notification->templateKey,
            // Los parametros ya vienen enmascarados por el propio objeto Notification.
            'parameters' => $notification->parameters,
        ]);
    }

    /**
     * Deja legible lo justo para reconocer el destino en soporte: la inicial y el
     * dominio del correo, o los ultimos cuatro digitos del telefono.
     */
    public static function maskRecipient(string $recipient): string
    {
        if (str_contains($recipient, '@')) {
            [$user, $domain] = explode('@', $recipient, 2);

            return substr($user, 0, 1).str_repeat('*', max(strlen($user) - 1, 1)).'@'.$domain;
        }

        $digits = preg_replace('/\D/', '', $recipient) ?? '';

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }
}
