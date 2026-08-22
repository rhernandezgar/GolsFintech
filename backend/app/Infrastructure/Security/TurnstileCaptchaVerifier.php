<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificador contra Cloudflare Turnstile, para produccion.
 *
 * Se eligio Turnstile y no reCAPTCHA porque no perfila al visitante ni le pide
 * resolver puzles, lo que encaja mejor con un tramite que el diseno promete en
 * menos de cinco minutos.
 *
 * Cualquier fallo —red, tiempo agotado, respuesta inesperada— se resuelve como
 * NO verificado. El detalle va al registro del servidor; el llamante solo sabe
 * que no paso (regla de seguridad 8).
 */
final readonly class TurnstileCaptchaVerifier implements CaptchaVerifier
{
    public function __construct(
        private string $secret,
        private string $verifyUrl,
        private int $timeoutSeconds,
    ) {}

    public function verify(?string $token, ?string $ipAddress): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeoutSeconds)
                ->post($this->verifyUrl, array_filter([
                    'secret' => $this->secret,
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]));

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $exception) {
            Log::warning('No se pudo verificar el CAPTCHA.', [
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
