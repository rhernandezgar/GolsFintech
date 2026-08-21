<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Datos capturados en P2 o extraidos por el OCR, tal como llegan desde la capa de
 * entrada: cadenas sin interpretar. La conversion a objetos de valor —y por tanto
 * la validacion real— ocurre dentro del caso de uso, del lado del servidor
 * (regla de seguridad 4).
 */
final readonly class ProspectDataInput
{
    public function __construct(
        public string $fullName,
        public string $curp,
        public ?string $rfc,
        public int $age,
        public string $sex,
        public string $monthlyIncome,
        public ?string $address = null,
        public ?string $geographicLocation = null,
        public ?string $businessType = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
