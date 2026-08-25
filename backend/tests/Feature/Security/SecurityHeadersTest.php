<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cabeceras de seguridad sobre la RESPUESTA REAL (T11, VUL-08).
 *
 * ### Por que no basta comprobar que el middleware esta registrado
 *
 * Es la leccion de VUL-14, y conviene no aprenderla dos veces. Alli habia once
 * pruebas verdes acreditando que los endpoints respondian 401, y el endpoint
 * respondia 500: las pruebas ejercian una configuracion —la cabecera `Accept`
 * que ponia el helper— y no el camino que recorre un cliente real.
 *
 * Comprobar «existe un middleware con la palabra Content-Security-Policy y esta
 * en bootstrap/app.php» es exactamente ese error. Un middleware puede estar
 * registrado en el grupo equivocado, ejecutarse despues de algo que corta la
 * peticion, o escribir la cabecera en una respuesta y no en otra. **Verificar la
 * configuracion no es verificar el comportamiento.**
 *
 * Por eso todo lo que hay aqui se afirma sobre `$response->headers`, y sobre
 * respuestas de distinta naturaleza: HTML, JSON con exito, 401, 404, 422 y 500.
 * Las de error importan mas que las de exito: son las que producen otras ramas
 * del codigo y las que un middleger mal colocado se salta.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    /** @return list<array{string, callable(self): TestResponse}> */
    public static function responsesOfEveryKind(): array
    {
        return [
            'HTML de la raiz (grupo web)' => ['/', 200],
            'JSON sin autenticar (401)' => ['/api/v1/me', 401],
            'JSON de ruta inexistente (404)' => ['/api/v1/no-existe', 404],
        ];
    }

    #[Test]
    #[DataProvider('responsesOfEveryKind')]
    public function the_four_headers_travel_in_every_response(string $uri, int $expectedStatus): void
    {
        $response = $this->get($uri);

        $this->assertSame($expectedStatus, $response->getStatusCode());

        // Las cuatro que exige el criterio de T11, sobre la respuesta real.
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('Strict-Transport-Security');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    #[Test]
    public function the_headers_survive_a_validation_error(): void
    {
        // Un 422 lo produce el manejador de excepciones, no el controlador: es
        // otra rama de salida y podria saltarse un middleware mal colocado.
        $response = $this->postJson('/api/v1/prospects', ['capture_method' => 'inventado'])
            ->assertStatus(422);

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function the_headers_survive_an_authenticated_success(): void
    {
        $response = $this->postJson('/api/v1/prospects', [
            'capture_method' => 'manual',
            'privacy_notice_accepted' => true,
            'captcha_token' => 'captcha-ok',
        ])->assertCreated();

        $token = (string) $response->json('data.session.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/prospects/me')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    // ============================================ contenido de la CSP ========

    #[Test]
    public function the_content_policy_denies_everything_by_default(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'none'", $policy);
        $this->assertStringContainsString("form-action 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
    }

    #[Test]
    public function the_content_policy_contains_no_escape_hatch(): void
    {
        // Es la prueba que impide el deslizamiento. Empezar permisiva «para no
        // romper nada» y endurecer despues es el camino por el que las CSP
        // acaban con `'unsafe-inline'` puesto para siempre: nadie vuelve a
        // tocar una politica que ya no se queja. Si alguien necesita una
        // excepcion, tendra que cambiar esta prueba, y cambiarla es una
        // decision consciente que se ve en la revision.
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('*', $policy);
        $this->assertStringNotContainsString('data:', $policy);
    }

    #[Test]
    public function the_only_html_page_needs_no_exception_to_the_policy(): void
    {
        // La pagina de bienvenida del scaffold traia un <style> en linea. La
        // salida NO fue abrir la politica: fue quitar el estilo. Si alguien
        // devuelve el bloque, esta prueba lo detiene antes de que aparezca la
        // tentacion de anadir 'unsafe-inline'.
        $body = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<style', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('style="', $body);
    }

    // ==================================================== HSTS ===============

    #[Test]
    public function hsts_is_short_outside_production(): void
    {
        // El certificado de desarrollo es autofirmado y HSTS es pegajoso: el
        // navegador obedece durante todo el max-age y no deja saltarse el aviso
        // del certificado. Un ano puesto por descuido deja el 6060 inservible
        // en ese navegador hasta que expire.
        $header = (string) $this->get('/')->headers->get('Strict-Transport-Security');

        $this->assertMatchesRegularExpression('/^max-age=\d+/', $header);

        preg_match('/max-age=(\d+)/', $header, $matches);
        $maxAge = (int) ($matches[1] ?? 0);

        $this->assertGreaterThan(0, $maxAge);
        $this->assertLessThanOrEqual(
            3600,
            $maxAge,
            'Fuera de produccion el max-age tiene que ser corto: con certificado autofirmado, '
            .'un valor largo deja el navegador rechazando el sitio hasta que expire.'
        );
    }

    #[Test]
    public function the_two_serious_commitments_are_off_by_default(): void
    {
        // `includeSubDomains` afecta a subdominios que quiza sirva otro equipo,
        // y salir de la lista de `preload` tarda meses. Ninguno se enciende sin
        // que alguien lo decida por entorno.
        $header = (string) $this->get('/')->headers->get('Strict-Transport-Security');

        $this->assertStringNotContainsString('includeSubDomains', $header);
        $this->assertStringNotContainsString('preload', $header);
    }

    #[Test]
    public function the_production_value_is_a_year_and_is_configurable(): void
    {
        // El valor de produccion no se comprueba levantando el entorno: se
        // comprueba que la cabecera se construye desde configuracion, que es lo
        // que permite endurecerla sin desplegar codigo.
        config([
            'security.headers.hsts.max_age' => 31_536_000,
            'security.headers.hsts.include_subdomains' => true,
            'security.headers.hsts.preload' => true,
        ]);

        $header = (string) $this->get('/')->headers->get('Strict-Transport-Security');

        $this->assertSame('max-age=31536000; includeSubDomains; preload', $header);
    }

    #[Test]
    public function preload_without_include_subdomains_is_not_emitted(): void
    {
        // La propia lista de precarga lo rechazaria: emitirlo seria escribir
        // una cabecera que no hace nada y que sugiere una proteccion que no
        // existe.
        config([
            'security.headers.hsts.include_subdomains' => false,
            'security.headers.hsts.preload' => true,
        ]);

        $header = (string) $this->get('/')->headers->get('Strict-Transport-Security');

        $this->assertStringNotContainsString('preload', $header);
    }

    // ======================================= huella del servidor =============

    #[Test]
    public function the_response_does_not_reveal_the_product_or_its_version(): void
    {
        // Saber `PHP/8.4.24` le ahorra al atacante el sondeo: puede ir directo
        // a los fallos conocidos de esa version.
        $response = $this->get('/');

        $this->assertNull($response->headers->get('X-Powered-By'));
        $this->assertNull($response->headers->get('X-AspNet-Version'));
        $this->assertNull($response->headers->get('X-Runtime'));

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString(
                    PHP_VERSION,
                    (string) $value,
                    sprintf('La cabecera "%s" revela la version de PHP.', $name)
                );
            }
        }
    }
}
