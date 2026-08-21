<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Card\CardBrand;
use App\Domain\Card\IssuedCard;
use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Port\CardIssuer;

/**
 * Doble programable del procesador de tarjetas.
 *
 * Devuelve un token, nunca un PAN: si un doble de prueba devolviera un numero con
 * forma de tarjeta, la prueba pasaria y el control real —que IssuedCard rechace
 * tokens con forma de PAN— quedaria sin ejercitar.
 */
final class FakeCardIssuer implements CardIssuer
{
    /** @var list<array{customer_id: int, credit_line_id: int}> */
    private array $issued = [];

    private bool $fail = false;

    public function issue(int $customerId, int $creditLineId): IssuedCard
    {
        if ($this->fail) {
            throw ExternalServiceUnavailableException::forService(
                'card_issuer',
                'emision rechazada (doble de prueba)'
            );
        }

        $this->issued[] = ['customer_id' => $customerId, 'credit_line_id' => $creditLineId];
        $seed = hash('sha256', sprintf('fake-card:%d:%d', $customerId, $creditLineId));

        return new IssuedCard(
            tokenizedCardNumber: 'tok_test_'.substr($seed, 0, 24),
            lastFour: str_pad((string) (hexdec(substr($seed, 24, 6)) % 10000), 4, '0', STR_PAD_LEFT),
            brand: CardBrand::Visa,
            expirationMonth: 12,
            expirationYear: 2030,
        );
    }

    public function willFail(): self
    {
        $this->fail = true;

        return $this;
    }

    /** @return list<array{customer_id: int, credit_line_id: int}> */
    public function issuedCards(): array
    {
        return $this->issued;
    }
}
