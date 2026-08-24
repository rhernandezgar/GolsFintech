<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Datos parciales para el PATCH de P2: el prospecto llena el formulario por
 * pasos y no siempre envia todos los campos. Cada campo es opcional; un valor
 * nulo o ausente significa "no cambiar el que ya hay".
 *
 * Se separa de `ProspectDataInput` (que es todo-o-nada, usado por OCR) para
 * que el compilador y las pruebas no confundan una captura completa con una
 * actualizacion parcial: son dos operaciones con reglas de negocio distintas.
 */
final readonly class ProspectDataPatch
{
    public function __construct(
        public ?string $fullName = null,
        public ?string $curp = null,
        public ?string $rfc = null,
        public ?int $age = null,
        public ?string $sex = null,
        public ?string $monthlyIncome = null,
        public ?string $address = null,
        public ?string $geographicLocation = null,
        public ?string $businessType = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
