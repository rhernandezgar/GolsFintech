<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en TODA respuesta (T11, VUL-08, CWE-1021).
 *
 * ### Por que en un middleware global y no ruta por ruta
 *
 * Una cabecera de seguridad que hay que acordarse de poner no es un control:
 * la ruta nueva que alguien anada manana naceria sin ella y nadie se enteraria
 * hasta la siguiente auditoria. Aplicandolas aqui, el caso por defecto es el
 * seguro y una excepcion tendria que escribirse a proposito.
 *
 * Se aplica a los grupos `web` y `api` porque las dos superficies existen: la
 * API responde JSON y la ruta `/` responde HTML. `Content-Security-Policy` casi
 * no significa nada sobre un JSON —no carga subrecursos—, pero mandarla igual
 * cuesta cero y cubre el dia que un endpoint devuelva HTML por error o por
 * cambio.
 *
 * ### La politica vive en configuracion
 *
 * Endurecerla en produccion es un cambio de entorno, no un despliegue de
 * codigo. El valor por defecto ya es el restrictivo: `config/security.php`
 * explica por que `default-src 'none'` es aqui la descripcion honesta de lo que
 * el backend hace y no una postura ambiciosa.
 *
 * ### El orden importa: primero se quita, luego se pone
 *
 * `X-Powered-By` la anade PHP al vuelo, asi que se retira tanto del objeto
 * respuesta como de la cola de cabeceras del SAPI. Quitarla despues de escribir
 * las nuestras seria igual de correcto, pero hacerlo antes deja claro en el
 * codigo que son dos operaciones distintas: reducir lo que se revela y anadir
 * lo que protege.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->removeFingerprintingHeaders($response);
        $this->applySecurityHeaders($response);

        return $response;
    }

    private function removeFingerprintingHeaders(Response $response): void
    {
        /** @var list<string> $toRemove */
        $toRemove = (array) config('security.headers.remove', []);

        foreach ($toRemove as $header) {
            $response->headers->remove($header);

            // PHP anade `X-Powered-By` fuera del objeto respuesta, en la cola
            // del SAPI: sin esto sobreviviria a `headers->remove()`. Se
            // comprueba `headers_sent()` porque en la linea de comandos —y en
            // parte de las pruebas— no hay cola que tocar y llamarlo emitiria
            // un aviso.
            if (! headers_sent() && function_exists('header_remove')) {
                header_remove($header);
            }
        }
    }

    private function applySecurityHeaders(Response $response): void
    {
        $headers = [
            'Content-Security-Policy' => (string) config('security.headers.content_security_policy'),

            'Strict-Transport-Security' => $this->strictTransportSecurity(),

            // Impide que el navegador adivine el tipo de contenido. Sin esto,
            // un archivo subido que el navegador decida interpretar como HTML
            // se convierte en XSS almacenado (CWE-430). Es la cabecera mas
            // barata del conjunto y de las que mas cierran.
            'X-Content-Type-Options' => 'nosniff',

            'Referrer-Policy' => (string) config('security.headers.referrer_policy', 'no-referrer'),

            // Companera antigua de `frame-ancestors 'none'`. Se manda tambien
            // porque los navegadores que no entienden CSP nivel 2 solo hacen
            // caso a esta, y un control que depende de la version del navegador
            // del usuario conviene tenerlo por duplicado.
            'X-Frame-Options' => 'DENY',
        ];

        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $response->headers->set($name, $value);
            }
        }
    }

    /**
     * `max-age` corto en desarrollo y largo en produccion, con los dos
     * compromisos serios —`includeSubDomains` y `preload`— apagados salvo que
     * el entorno los encienda. El razonamiento completo esta en
     * `config/security.php`: HSTS es pegajoso y un valor largo puesto por
     * descuido contra un certificado autofirmado deja el sitio inservible en
     * ese navegador hasta que expire.
     */
    private function strictTransportSecurity(): string
    {
        $maxAge = (int) config('security.headers.hsts.max_age', 300);

        // Un max-age de 0 es la instruccion explicita de OLVIDAR la politica,
        // que es util para revertir un despliegue equivocado. Negativo no
        // significa nada, asi que se normaliza.
        $directives = ['max-age='.max($maxAge, 0)];

        if (config('security.headers.hsts.include_subdomains', false)) {
            $directives[] = 'includeSubDomains';
        }

        // `preload` sin `includeSubDomains` lo rechaza la propia lista de
        // precarga, asi que anadirlo solo seria escribir una cabecera que no
        // hace nada y que sugiere una proteccion inexistente.
        if (config('security.headers.hsts.preload', false)
            && config('security.headers.hsts.include_subdomains', false)) {
            $directives[] = 'preload';
        }

        return implode('; ', $directives);
    }
}
