<?php

declare(strict_types=1);

namespace App\Infrastructure\Card;

use App\Domain\Card\CardBrand;
use App\Domain\Card\IssuedCard;
use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Port\CardIssuer;
use DateTimeImmutable;

/**
 * Adaptador del procesador de tarjetas simulado (RF-08, P6).
 *
 * No hay procesador real ni credenciales. Lo importante es que el simulador cumple
 * la MISMA regla que debera cumplir el real: aqui no se genera, ni se recibe, ni se
 * guarda un PAN. Se devuelve un token con prefijo "tok_sim_" y cuatro digitos de
 * cierre; el objeto IssuedCard rechaza por su cuenta cualquier token que tenga
 * forma de numero de tarjeta (regla de seguridad 2, PCI DSS).
 *
 * Determinista: la misma pareja (cliente, linea de credito) produce siempre la
 * misma tarjeta, de modo que una prueba puede afirmar el token exacto.
 */
final readonly class SimulatedCardIssuer implements CardIssuer
{
    public function __construct(
        private bool $fail = false,
        private int $validityYears = 3,
        private ?DateTimeImmutable $reference = null,
    ) {}

    public function issue(int $customerId, int $creditLineId): IssuedCard
    {
        if ($this->fail) {
            throw ExternalServiceUnavailableException::forService(
                'card_issuer',
                'el procesador de tarjetas rechazo la emision (simulado)'
            );
        }

        // SHA-256 y no MD5/SHA-1 tambien aqui: la prohibicion no admite excepcion
        // por tratarse de codigo de simulacion (regla de seguridad 6).
        $seed = hash('sha256', sprintf('card:%d:%d', $customerId, $creditLineId));
        $expiration = ($this->reference ?? new DateTimeImmutable)
            ->modify(sprintf('+%d years', $this->validityYears));

        return new IssuedCard(
            tokenizedCardNumber: 'tok_sim_'.substr($seed, 0, 32),
            lastFour: substr(str_pad((string) (hexdec(substr($seed, 32, 6)) % 10000), 4, '0', STR_PAD_LEFT), -4),
            brand: CardBrand::Visa,
            expirationMonth: (int) $expiration->format('n'),
            expirationYear: (int) $expiration->format('Y'),
        );
    }
}
