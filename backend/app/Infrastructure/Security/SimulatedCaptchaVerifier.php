<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Verificador de CAPTCHA para desarrollo y pruebas.
 *
 * **No acepta cualquier cosa**, que es lo que convertiria el control en
 * decorativo: acepta exactamente el token de prueba configurado y rechaza todo
 * lo demas, incluidos el nulo y la cadena vacia. Asi las pruebas pueden exigir
 * tanto que el reto valido pase como que el invalido se corte.
 */
final readonly class SimulatedCaptchaVerifier implements CaptchaVerifier
{
    public function __construct(private string $expectedToken) {}

    public function verify(?string $token, ?string $ipAddress): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->expectedToken, $token);
    }
}
