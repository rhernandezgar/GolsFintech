<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Lo que se sabe de la solicitud que YA existe para una CURP.
 *
 * Es deliberadamente una foto de HECHOS y no de conclusiones: el puerto la
 * llena consultando la base y `ReapplicationPolicy` es quien decide que
 * significan. Si la instantanea trajera ya el veredicto —«esta expirada»,
 * «excedio intentos»—, las ventanas de tiempo acabarian repartidas entre el
 * adaptador y la politica, y cambiar una regla obligaria a tocar los dos.
 *
 * Por eso `rejectedValidationsAt` son las FECHAS de los rechazos y no un
 * contador: cuantos caen dentro de la ventana depende de la ventana, que es una
 * decision de negocio y vive en la politica.
 */
final readonly class ApplicationSnapshot
{
    /**
     * @param  list<DateTimeImmutable>  $rejectedValidationsAt  fechas de las
     *                                                          validaciones de identidad rechazadas, de la mas antigua a la mas reciente
     */
    public function __construct(
        public Uuid $prospectPublicId,
        public CaptureStatus $captureStatus,
        /** Ultimo momento en que el expediente se movio: sirve para la expiracion. */
        public DateTimeImmutable $lastActivityAt,
        /** La CURP ya corresponde a un cliente dado de alta. */
        public bool $belongsToCustomer,
        public array $rejectedValidationsAt = [],
    ) {}

    /**
     * Una solicitud «en curso» es la que todavia puede avanzar. `abandoned` no
     * lo esta —es terminal— y por eso no bloquea a nadie.
     */
    public function isInProgress(): bool
    {
        return $this->captureStatus !== CaptureStatus::Abandoned;
    }

    public function hasBeenRejected(): bool
    {
        return $this->rejectedValidationsAt !== [];
    }
}
