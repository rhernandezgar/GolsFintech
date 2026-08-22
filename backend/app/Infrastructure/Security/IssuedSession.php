<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use DateTimeImmutable;

/**
 * Lo que la SPA necesita saber de su sesion: el token, cuando caduca y con
 * cuanta antelacion conviene renovarlo.
 *
 * El margen de renovacion viaja en la respuesta y no cableado en el navegador
 * para que ajustarlo sea una decision del servidor, que es quien fija la
 * vigencia.
 */
final readonly class IssuedSession
{
    public function __construct(
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
        public int $expiresInSeconds,
        public int $renewBeforeSeconds,
        public string $scope,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'scope' => $this->scope,
            'expires_in' => $this->expiresInSeconds,
            'expires_at' => $this->expiresAt->format(DateTimeImmutable::ATOM),
            'renew_before' => $this->renewBeforeSeconds,
        ];
    }
}
