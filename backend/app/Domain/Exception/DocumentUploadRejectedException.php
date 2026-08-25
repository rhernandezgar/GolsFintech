<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La carga de la identificacion no cumple los controles de VUL-01.
 *
 * ### Por que baja a la jerarquia de DomainException
 *
 * Antes vivia en `Infrastructure\Storage` y extendia `RuntimeException` a secas,
 * con **un solo mensaje** que hacia de detalle tecnico y de texto para el
 * usuario a la vez. El controlador lo devolvia con `getMessage()`, y ahi esta el
 * problema de fondo de VUL-05: mientras el mensaje sea uno solo, que llegue o no
 * al cliente depende de que cada `catch` se acuerde de no devolverlo. Basta un
 * descuido —o una excepcion nueva escrita por otra persona— para filtrar detalle
 * tecnico.
 *
 * Con los dos mensajes separados, el descuido deja de ser posible: `getMessage()`
 * es tecnico y solo va al registro; `userMessage()` es lo unico que la capa HTTP
 * devuelve. La regla se vuelve estructural en vez de depender de la disciplina de
 * cada `catch`, y por eso `ControllerErrorExposureTest` puede exigirla.
 *
 * ### Por que los constructores son con nombre
 *
 * Cada motivo de rechazo tiene su codigo estable —con el que soporte correlaciona
 * el caso— y su propio detalle tecnico, mientras que **el mensaje al usuario es
 * casi siempre el mismo a proposito**: decirle a quien sube un archivo si lo
 * rechazamos por el tipo real detectado o por cuanto se paso de tamano le dice
 * exactamente que ajustar para colar el siguiente intento (regla de seguridad 8).
 * El limite y el tipo si se nombran donde son utiles sin dar pistas: tamano y
 * formato son restricciones publicadas de antemano en la propia pantalla.
 */
final class DocumentUploadRejectedException extends DomainException
{
    private function __construct(
        string $technicalMessage,
        private readonly string $errorCode,
        private readonly string $userMessage,
    ) {
        parent::__construct($technicalMessage);
    }

    /** El archivo llego corrupto o el transporte fallo a mitad. */
    public static function unreadable(): self
    {
        return new self(
            'El archivo cargado no se pudo leer del almacenamiento temporal.',
            'DOCUMENT_UNREADABLE',
            'No pudimos leer el archivo. Intentalo de nuevo.',
        );
    }

    public static function emptyFile(): self
    {
        return new self(
            'El archivo cargado tiene cero bytes.',
            'DOCUMENT_EMPTY',
            'El archivo esta vacio. Adjunta tu identificacion de nuevo.',
        );
    }

    public static function tooLarge(int $sizeBytes, int $limitBytes): self
    {
        return new self(
            sprintf('El archivo pesa %d bytes y el limite es %d.', $sizeBytes, $limitBytes),
            'DOCUMENT_TOO_LARGE',
            'El archivo supera el tamano maximo permitido.',
        );
    }

    /**
     * El tipo REAL del contenido no esta en la lista blanca. El detalle tecnico
     * lleva el tipo detectado —util para investigar un rechazo legitimo— y el
     * mensaje al usuario no: confirmarle a quien renombra un ejecutable que lo
     * detectamos como tal le dice que la comprobacion mira el contenido.
     */
    public static function disallowedType(string $detectedMimeType): self
    {
        return new self(
            sprintf('El tipo real detectado "%s" no esta en la lista blanca.', $detectedMimeType),
            'DOCUMENT_TYPE_NOT_ALLOWED',
            'El tipo de archivo no esta permitido. Sube una imagen JPG o PNG, o un PDF.',
        );
    }

    /** Fallo al escribir en el almacen: disco lleno, permisos, ruta invalida. */
    public static function couldNotBeStored(string $reason): self
    {
        return new self(
            sprintf('El archivo no se pudo almacenar: %s', $reason),
            'DOCUMENT_NOT_STORED',
            'No pudimos procesar el archivo. Intentalo de nuevo.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
