<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invariantes del servidor OAuth2 de GolsFintech (Fase 3 §4.4).
 *
 * La SPA de Vue es un cliente publico: no puede custodiar un secreto de
 * cliente, porque cualquier valor incorporado en el codigo entregado al
 * navegador es legible por el usuario. De ahi tres exigencias que la libreria
 * por si sola no impone y que este middleware si:
 *
 * 1. PKCE obligatorio. league/oauth2-server ya lo exige a los clientes
 *    publicos, pero la comprobacion depende de que el cliente este marcado como
 *    publico en la base de datos. Aqui se exige sobre la peticion, sin
 *    depender de un dato que alguien pueda cambiar con un UPDATE.
 *
 * 2. Metodo S256 y nada mas. RFC 7636 §4.2 permite tambien `plain`, que envia
 *    el verificador tal cual en la peticion de autorizacion: quien la
 *    intercepte —un proxy, un registro de servidor, el historial del
 *    navegador— obtiene el verificador y PKCE deja de proteger nada. S256
 *    envia solo su SHA-256, que no se revierte.
 *
 * 3. Flujo implicito descartado. Es la alternativa historica para clientes
 *    publicos y entrega el token de acceso directamente en el fragmento de la
 *    redireccion, donde queda expuesto en el historial del navegador y en los
 *    registros de los servidores intermedios. Passport lo trae desactivado
 *    (Passport::$implicitGrantEnabled = false) y este proyecto nunca llama a
 *    Passport::enableImplicitGrant(); el rechazo explicito de response_type=token
 *    y de grant_type=implicit deja constancia de que es una decision y no un
 *    descuido, y convierte un `enableImplicitGrant()` futuro en una prueba
 *    en rojo antes que en una vulnerabilidad.
 */
final class EnforcePkceS256
{
    public function handle(Request $request, Closure $next): Response
    {
        $responseType = $request->input('response_type');
        $grantType = $request->input('grant_type');

        // (3) Flujo implicito: descartado.
        if ($responseType !== null && $responseType !== 'code') {
            return $this->reject(
                'unsupported_response_type',
                'Solo se admite el flujo de codigo de autorizacion.'
            );
        }

        if ($grantType === 'implicit') {
            return $this->reject(
                'unsupported_grant_type',
                'Solo se admite el flujo de codigo de autorizacion.'
            );
        }

        if ($responseType === 'code') {
            $challenge = $request->input('code_challenge');
            // RFC 7636 §4.3: si se omite el metodo, el valor por defecto es
            // `plain`. Se explicita para no aceptarlo por omision.
            $method = $request->input('code_challenge_method', 'plain');

            // (1) PKCE obligatorio.
            if (! is_string($challenge) || $challenge === '') {
                return $this->reject(
                    'invalid_request',
                    'La peticion de autorizacion exige PKCE (code_challenge).'
                );
            }

            // (2) Solo S256.
            if (! in_array($method, config('security.oauth.code_challenge_methods'), true)) {
                return $this->reject(
                    'invalid_request',
                    'El unico metodo de code_challenge admitido es S256.'
                );
            }
        }

        return $next($request);
    }

    /**
     * Error en el formato de RFC 6749 §5.2, sin traza ni detalle interno
     * (regla de seguridad no negociable 8).
     */
    private function reject(string $error, string $description): JsonResponse
    {
        return new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], Response::HTTP_BAD_REQUEST);
    }
}
