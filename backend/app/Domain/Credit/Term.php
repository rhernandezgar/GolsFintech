<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Exception\UnauthorizedTermException;

/**
 * Plazo del credito en meses.
 *
 * El catalogo autorizado vive AQUI, en el dominio, y no en el controlador ni en el
 * navegador: ese fue exactamente el defecto VUL-02 (CWE-20), donde el plazo enviado
 * por el cliente se usaba sin contrastarlo contra la politica. Cualquier valor fuera
 * de la lista se rechaza, venga de donde venga.
 */
final readonly class Term
{
    /** @var list<int> */
    public const AUTHORIZED_MONTHS = [6, 12, 18, 24, 36];

    private function __construct(public int $months)
    {
    }

    public static function fromMonths(int $months): self
    {
        if (! in_array($months, self::AUTHORIZED_MONTHS, true)) {
            throw new UnauthorizedTermException(sprintf(
                'Plazo de %d meses fuera del catalogo autorizado (%s).',
                $months,
                implode(', ', self::AUTHORIZED_MONTHS)
            ));
        }

        return new self($months);
    }

    /** @return list<self> */
    public static function authorized(): array
    {
        return array_map(static fn (int $months): self => new self($months), self::AUTHORIZED_MONTHS);
    }

    public function equals(self $other): bool
    {
        return $this->months === $other->months;
    }
}
