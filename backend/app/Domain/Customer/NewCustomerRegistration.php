<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Credit\AnnualRate;
use App\Domain\Shared\Folio;
use App\Domain\Shared\Money;
use DateTimeImmutable;

/**
 * Todo lo que hace falta para dar de alta al cliente en P6, en un solo objeto.
 *
 * La tarjeta no viene aqui: la emite un tercero y se adjunta despues, con
 * CustomerRegistry::attachCard. Vease el comentario de ese puerto.
 */
final readonly class NewCustomerRegistration
{
    public function __construct(
        public int $prospectId,
        public int $creditApplicationId,
        public int $creditSimulationId,
        public string $fullName,
        public Folio $customerNumber,
        public Folio $contractFolio,
        public string $contractVersion,
        public DateTimeImmutable $consentAt,
        public Money $authorizedAmount,
        public AnnualRate $annualRate,
        public int $termMonths,
    ) {}
}
