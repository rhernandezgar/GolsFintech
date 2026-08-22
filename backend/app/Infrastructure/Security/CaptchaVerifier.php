<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Control anti-automatizacion del endpoint publico de P1 (RS-10, riesgo R-05).
 *
 * No sustituye al throttle: lo complementa. `throttle` cuenta peticiones por
 * direccion IP, y un guion que reparte el trabajo entre muchas direcciones crea
 * expedientes en masa sin acercarse nunca al umbral. Lo que encarece esa
 * automatizacion es el reto, no el contador.
 *
 * No es un puerto del dominio: al dominio no le consta que exista un CAPTCHA, y
 * no deberia. Vive en Infrastructure/Security con el resto de la maquinaria de
 * acceso.
 */
interface CaptchaVerifier
{
    /**
     * Devuelve true solo si el reto se resolvio de verdad.
     *
     * Nunca lanza por un fallo del proveedor: si no se puede comprobar, el
     * resultado es false. Ante un tercero caido, la respuesta segura es
     * rechazar la solicitud, no dejar pasar el trafico sin comprobar.
     */
    public function verify(?string $token, ?string $ipAddress): bool;
}
