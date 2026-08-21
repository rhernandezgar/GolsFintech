<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Un servicio externo no pudo atender la peticion: OCR, proveedor de identidad,
 * procesador de tarjetas o pasarela de notificaciones.
 *
 * Existe para que los adaptadores traduzcan SU fallo —una excepcion de HTTP, de
 * Redis o del SDK del proveedor— a un tipo que el dominio si conoce. Si el caso de
 * uso tuviera que atrapar la excepcion de una libreria concreta, la capa de
 * aplicacion quedaria atada al adaptador y el patron dejaria de servir.
 *
 * Es indisponibilidad, NO rechazo: que INE no responda no significa que la
 * identidad sea falsa (VerificationStatus::Unavailable existe justo por eso).
 */
final class ExternalServiceUnavailableException extends DomainException
{
    public static function forService(string $service, string $reason): self
    {
        return new self(sprintf('El servicio externo "%s" no esta disponible: %s', $service, $reason));
    }

    public function errorCode(): string
    {
        return 'EXTERNAL_SERVICE_UNAVAILABLE';
    }

    /**
     * Al usuario no se le nombra el proveedor ni el motivo tecnico: saber cual
     * servicio fallo no le sirve de nada y si le sirve a quien sondea el sistema
     * (regla de seguridad 8 y VUL-05). El detalle va en getMessage(), que solo
     * llega al registro del servidor.
     */
    public function userMessage(): string
    {
        return 'No pudimos completar la operacion en este momento. Intentalo de nuevo mas tarde.';
    }
}
