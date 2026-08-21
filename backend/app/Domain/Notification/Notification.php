<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Audit\SensitiveDataMasker;

/**
 * Aviso a enviar. Viaja como clave de plantilla mas parametros, nunca como texto ya
 * redactado: el mensaje se arma en el adaptador, en el momento del envio, y asi la
 * cola no guarda datos personales. Los parametros pasan igualmente por el
 * enmascarado, porque una cola tambien es un registro persistente.
 */
final readonly class Notification
{
    /** @var array<string, mixed> */
    public array $parameters;

    /** @param array<string, mixed> $parameters */
    public function __construct(
        public NotificationChannel $channel,
        public string $recipient,
        public string $templateKey,
        array $parameters = [],
        ?SensitiveDataMasker $masker = null,
    ) {
        $this->parameters = ($masker ?? new SensitiveDataMasker)->mask($parameters);
    }
}
