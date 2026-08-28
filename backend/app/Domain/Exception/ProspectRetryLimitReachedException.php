<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Demasiadas validaciones de identidad rechazadas para la misma CURP dentro de
 * la ventana.
 *
 * Es el limite que impide que P4 se convierta en un **oraculo de fuerza bruta
 * contra INE y RENAPO**: con reintentos ilimitados, alguien puede sondear
 * combinaciones de nombre, fecha o sexo hasta que una valide, usando nuestro
 * convenio como servicio de verificacion gratuito (riesgos R-01 y R-05).
 *
 * El mensaje NO dice cuantos intentos van ni cuantos quedan. Decirlo le
 * entregaria al que sondea el contador exacto con el que planificar; a quien
 * intenta de buena fe le basta con saber cuando puede volver.
 */
final class ProspectRetryLimitReachedException extends DomainException
{
    public function __construct(private readonly int $retryAfterMinutes)
    {
        parent::__construct(sprintf(
            'Se alcanzo el limite de validaciones rechazadas para esa CURP; quedan %d minuto(s) de espera.',
            $retryAfterMinutes
        ));
    }

    public function retryAfterMinutes(): int
    {
        return $this->retryAfterMinutes;
    }

    public function errorCode(): string
    {
        return 'PROSPECT_RETRY_LIMIT_REACHED';
    }

    public function userMessage(): string
    {
        return sprintf(
            'No pudimos verificar tu identidad en los intentos recientes. Podras intentarlo de nuevo '
            .'en aproximadamente %d minuto(s). Si el problema persiste, acude a soporte con tu folio.',
            $this->retryAfterMinutes
        );
    }
}
