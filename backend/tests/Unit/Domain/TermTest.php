<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Credit\Term;
use App\Domain\Exception\UnauthorizedTermException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** VUL-02 (CWE-20): el catalogo de plazos vive en el dominio y nada lo puede saltar. */
final class TermTest extends TestCase
{
    public function test_it_accepts_every_authorized_term(): void
    {
        foreach (Term::AUTHORIZED_MONTHS as $months) {
            $this->assertSame($months, Term::fromMonths($months)->months);
        }
    }

    /**
     * @return list<array{int}>
     */
    public static function unauthorizedTerms(): array
    {
        return [[0], [-12], [5], [7], [48], [120], [999]];
    }

    #[DataProvider('unauthorizedTerms')]
    public function test_it_rejects_any_term_outside_the_catalogue(int $months): void
    {
        $this->expectException(UnauthorizedTermException::class);

        Term::fromMonths($months);
    }
}
