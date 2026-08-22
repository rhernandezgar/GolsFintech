<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Notification\Notification;
use App\Domain\Port\NotificationSender;
use App\Infrastructure\Queue\BullMqQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adaptador de notificaciones sobre BullMQ.
 *
 * El envio es asincrono por la misma razon que el OCR: una pasarela de correo o
 * de SMS lenta no debe consumir procesos de la API (Fase 2 §d). El caso de uso
 * llama a `send()` y sigue; el worker entrega.
 *
 * La carga lleva clave de plantilla y parametros, no el texto ya redactado, que
 * es como el dominio modela la notificacion justamente para que la cola no
 * guarde datos personales. Los parametros ya vienen enmascarados desde el
 * constructor de `Notification`, asi que lo que se escribe en Redis no puede
 * contener una CURP, un RFC ni un PAN en claro.
 */
final readonly class BullMqNotificationSender implements NotificationSender
{
    public function __construct(
        private BullMqQueue $queue,
        private LoggerInterface $logger,
        private string $queueName = 'notifications',
        private string $jobName = 'notification.send',
        private int $attempts = 5,
        private int $backoffDelayMs = 2000,
    ) {}

    public function send(Notification $notification): void
    {
        try {
            $jobId = $this->queue->add(
                queue: $this->queueName,
                jobName: $this->jobName,
                data: [
                    'channel' => $notification->channel->value,
                    'recipient' => $notification->recipient,
                    'template_key' => $notification->templateKey,
                    'parameters' => $notification->parameters,
                ],
                options: [
                    // Mas intentos que el OCR y con mas separacion: una pasarela
                    // de correo suele restablecerse sola, y reintentar un aviso
                    // no tiene el costo de reprocesar una imagen.
                    'attempts' => $this->attempts,
                    'backoff' => ['type' => 'exponential', 'delay' => $this->backoffDelayMs],
                    'removeOnFail' => false,
                    'removeOnComplete' => ['age' => 86400],
                ],
            );
        } catch (Throwable $e) {
            $this->logger->error('No se pudo encolar la notificacion', [
                'template_key' => $notification->templateKey,
                'channel' => $notification->channel->value,
                'reason' => $e->getMessage(),
            ]);

            throw ExternalServiceUnavailableException::forService(
                'notification',
                'la cola de notificaciones no acepta trabajos'
            );
        }

        // El destinatario no se registra: es un dato personal.
        $this->logger->info('Notificacion encolada', [
            'queue' => $this->queueName,
            'bullmq_job_id' => $jobId,
            'template_key' => $notification->templateKey,
            'channel' => $notification->channel->value,
        ]);
    }
}
