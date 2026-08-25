<?php

declare(strict_types=1);

namespace Tests\Feature\Error;

use App\Http\Middleware\AssignCorrelationId;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Normalizacion de errores con identificador de correlacion (VUL-05, T10).
 *
 * La propiedad que se sostiene es doble y las dos mitades importan igual:
 *
 *   1. **Al cliente no llega detalle tecnico.** Ni clase, ni archivo, ni linea,
 *      ni traza, ni el texto interno de la excepcion. **Ni siquiera con
 *      `APP_DEBUG` activo**, que es como corre la suite: el modo de depuracion
 *      no puede ser lo que separa una respuesta segura de una que no lo es.
 *   2. **Soporte puede encontrar el fallo.** Sin el identificador, cumplir la
 *      mitad de arriba deja al usuario diciendo «me sale que no se pudo
 *      completar la operacion» y a nadie con forma de localizar su caso. Esa
 *      tension es la que empuja a devolver el detalle «solo por esta vez».
 */
final class CorrelatedErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    /**
     * Ruta que revienta de forma imprevista, que es el caso que se normaliza.
     *
     * Se registra DENTRO del grupo `api`, que es donde viven las rutas reales
     * de `routes/api.php`. Sin ese grupo el middleware de correlacion no corre
     * y la prueba mediria una pila que no existe en produccion.
     */
    private function registerExplodingRoute(): void
    {
        Route::middleware('api')->get('/api/v1/testing/explode', function (): void {
            throw new RuntimeException(
                'Detalle interno: SQLSTATE[42S02] tabla prospects_v2 no existe'
            );
        });
    }

    // ================================== lo que NO llega al cliente ===========

    #[Test]
    public function an_unhandled_failure_never_leaks_technical_detail(): void
    {
        $this->registerExplodingRoute();

        // La suite corre con APP_DEBUG activo. Sin la normalizacion, aqui
        // llegarian la clase, el archivo, la linea y la traza entera.
        $this->assertTrue(config('app.debug'), 'La prueba pierde sentido si el modo depuracion esta apagado.');

        $response = $this->getJson('/api/v1/testing/explode')->assertStatus(500);
        $body = $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('prospects_v2', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('/opt/golsfintech', $body);
        $response->assertJsonMissingPath('trace');
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('file');
        $response->assertJsonMissingPath('line');
    }

    #[Test]
    public function the_normalised_body_carries_exactly_three_fields(): void
    {
        $this->registerExplodingRoute();

        $response = $this->getJson('/api/v1/testing/explode')->assertStatus(500);

        $this->assertSame(
            ['message', 'error_code', 'correlation_id'],
            array_keys($response->json()),
        );
        $this->assertSame('INTERNAL_ERROR', $response->json('error_code'));
        $this->assertMatchesRegularExpression(self::ULID, $response->json('correlation_id'));
    }

    // ================================ lo que si llega, para soporte ==========

    #[Test]
    public function the_identifier_in_the_body_matches_the_response_header(): void
    {
        // El usuario copia el del cuerpo; el operador lee el de la cabecera.
        // Si no coincidieran, soporte buscaria el caso equivocado.
        $this->registerExplodingRoute();

        $response = $this->getJson('/api/v1/testing/explode')->assertStatus(500);

        $this->assertSame(
            $response->headers->get(AssignCorrelationId::HEADER),
            $response->json('correlation_id'),
        );
    }

    #[Test]
    public function every_response_carries_the_header_including_the_successful_ones(): void
    {
        // Una peticion que respondio 200 y se comporto raro es un caso de
        // soporte tan real como un fallo, y tambien tiene que ser rastreable.
        $response = $this->postJson('/api/v1/prospects', [
            'capture_method' => 'manual',
            'privacy_notice_accepted' => true,
            'captcha_token' => 'captcha-ok',
        ])->assertCreated();

        $this->assertMatchesRegularExpression(
            self::ULID,
            (string) $response->headers->get(AssignCorrelationId::HEADER),
        );
    }

    #[Test]
    public function each_request_gets_its_own_identifier(): void
    {
        $this->registerExplodingRoute();

        $first = $this->getJson('/api/v1/testing/explode')->json('correlation_id');
        $second = $this->getJson('/api/v1/testing/explode')->json('correlation_id');

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function the_identifier_is_generated_by_the_server_and_not_taken_from_the_client(): void
    {
        // Aceptar el del cliente seria comodo y es lo que hacen muchas
        // pasarelas. Tambien es inyeccion de registro: un valor con saltos de
        // linea fabrica entradas falsas. Y dos peticiones podrian declarar el
        // mismo identificador, rompiendo la propiedad por la que existe.
        $this->registerExplodingRoute();

        $forged = "falsificado\nFATAL entrada inventada";

        $response = $this->withHeader(AssignCorrelationId::HEADER, $forged)
            ->getJson('/api/v1/testing/explode')
            ->assertStatus(500);

        $this->assertNotSame($forged, $response->json('correlation_id'));
        $this->assertMatchesRegularExpression(self::ULID, $response->json('correlation_id'));
        $this->assertStringNotContainsString('entrada inventada', $response->getContent());
    }

    // ============================ lo que NO se normaliza, y esta bien ========

    #[Test]
    public function the_statuses_the_framework_already_renders_are_left_alone(): void
    {
        // Convertirlas en 500 seria mentir sobre lo que paso. El 401 ademas
        // reabriria VUL-14 desde el otro lado: el primer intento de este bloque
        // hacia justo eso y tumbo 15 pruebas.
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/ruta-que-no-existe')->assertStatus(404);

        // 422 de validacion conserva su forma con `errors`, que es lo que la
        // SPA usa para marcar campo por campo.
        $this->postJson('/api/v1/prospects', ['capture_method' => 'inventado'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    #[Test]
    public function a_handled_domain_failure_keeps_its_own_message_and_gains_the_identifier(): void
    {
        // Los 422 del recorrido ya traen mensaje y codigo pensados uno por uno:
        // la normalizacion NO los sustituye por algo mas pobre. Lo unico que
        // les faltaba era el hilo con el registro.
        $token = (string) $this->postJson('/api/v1/prospects', [
            'capture_method' => 'manual',
            'privacy_notice_accepted' => true,
            'captcha_token' => 'captcha-ok',
        ])->assertCreated()->json('data.session.access_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/prospects/me/confirm')
            ->assertStatus(422);

        $response->assertJsonPath('error_code', 'PROSPECT_DATA_INCOMPLETE');
        $response->assertJsonStructure(['message', 'error_code', 'missing_fields', 'correlation_id']);
        $this->assertMatchesRegularExpression(self::ULID, $response->json('correlation_id'));
    }
}
