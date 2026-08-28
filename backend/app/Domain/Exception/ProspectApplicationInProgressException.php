<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Ya hay una solicitud viva para esa CURP y todavia no ha caducado.
 *
 * Existe para no seguir diciendole «tu CURP no es valida» a alguien cuya CURP
 * es perfectamente valida (VUL-17). Con el mensaje anterior, el solicitante la
 * revisaba, comprobaba que estaba bien, la volvia a escribir y recibia lo
 * mismo: un callejon del que solo se sale abandonando.
 *
 * El mensaje lleva **minutos reales**, no un «intentalo mas tarde». Un plazo
 * sin numero es lo que hace que la gente reintente cada pocos segundos, que es
 * peor para el usuario y para el servidor.
 */
final class ProspectApplicationInProgressException extends DomainException
{
    public function __construct(private readonly int $retryAfterMinutes)
    {
        parent::__construct(sprintf(
            'Existe una solicitud en curso para esa CURP; faltan %d minuto(s) para que caduque.',
            $retryAfterMinutes
        ));
    }

    public function retryAfterMinutes(): int
    {
        return $this->retryAfterMinutes;
    }

    public function errorCode(): string
    {
        return 'PROSPECT_APPLICATION_IN_PROGRESS';
    }

    public function userMessage(): string
    {
        return sprintf(
            'Ya hay una solicitud en curso con esos datos. Podras iniciar una nueva en %d minuto(s), '
            .'o continuar la anterior si la tienes abierta en otra pestana.',
            $this->retryAfterMinutes
        );
    }
}
