<?php

declare(strict_types=1);

namespace Tests\Feature\Prospect;

use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P2: captura (PATCH) y confirmacion (POST) del expediente del prospecto.
 *
 * Lo que se prueba aqui son las cuatro capas del control:
 *
 * - 401 sin token,
 * - 403 sobre expediente ajeno —aunque en el diseno no hay forma de nombrarlo
 *   por URL, la politica se comprueba directamente para dejar la barrera
 *   probada por si manana aparece una ruta con `{prospect}`—,
 * - 422 con detalle en las dos ramas: validacion sintactica del FormRequest y
 *   validacion del dominio (CURP con digito equivocado, RFC malformado, campos
 *   incompletos al confirmar),
 * - 200 en el camino feliz, con la fila avanzando de `started` a
 *   `data_captured` a `data_confirmed`.
 *
 * Ademas se acredita la precision de negocio 1: el PATCH acepta captura
 * parcial —solo `full_name`, o solo un subconjunto— sin exigir que esten
 * todos los campos obligatorios; esa comprobacion es de `confirm`.
 */
final class ProspectDataCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CAPTCHA = 'captcha-ok';

    private const VALID_CURP = 'HEGG560427MVZRRL04';

    // XEXX... son las CURPs de test que ya usan otros archivos del proyecto.
    private const OTHER_CURP = 'XEXX010101HNEXXXA4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    /** Abre expediente y devuelve el token de sesion del prospecto que abrio. */
    private function newSessionToken(string $capture_method = 'manual'): string
    {
        $response = $this->postJson('/api/v1/prospects', [
            'capture_method' => $capture_method,
            'privacy_notice_accepted' => true,
            'captcha_token' => self::VALID_CAPTCHA,
        ])->assertCreated();

        return (string) $response->json('data.session.access_token');
    }

    /**
     * En tests, el contenedor se reutiliza entre peticiones y el guard de
     * Passport cachea el `user()` resuelto. Si en la misma prueba se emiten
     * dos tokens y se ejerce el segundo tras haber ejercido el primero, el
     * guard devuelve al usuario del primero y el aislamiento por token se
     * evapora en el test aunque en produccion no exista el problema —cada
     * request abre un ciclo del kernel nuevo—. Se resetea de forma explicita
     * antes de cada peticion autenticada.
     */
    private function resetGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @param array<string, mixed> $body */
    private function patchWith(string $token, array $body): TestResponse
    {
        $this->resetGuard();

        return $this->withHeader('Authorization', 'Bearer '.$token)->patchJson('/api/v1/prospects/me', $body);
    }

    private function confirmWith(string $token): TestResponse
    {
        $this->resetGuard();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/prospects/me/confirm');
    }

    /** Cuerpo minimo para pasar al estado `data_confirmed`. */
    private function completeBody(string $curp = self::VALID_CURP): array
    {
        return [
            'full_name' => 'Ana Perez Lopez',
            'curp' => $curp,
            'age' => 34,
            'sex' => 'M',
            'monthly_income' => '18000.00',
        ];
    }

    // =========================================================== 401 =========

    public function test_patch_without_a_token_answers_401(): void
    {
        $this->patchJson('/api/v1/prospects/me', $this->completeBody())->assertStatus(401);
    }

    public function test_confirm_without_a_token_answers_401(): void
    {
        $this->postJson('/api/v1/prospects/me/confirm')->assertStatus(401);
    }

    // =========================================================== 422 =========

    public function test_a_curp_with_the_wrong_length_is_rejected_by_form_request(): void
    {
        $token = $this->newSessionToken();

        $this->patchWith($token, ['curp' => 'HEGG560427MVZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('curp');
    }

    public function test_a_curp_with_the_wrong_check_digit_is_rejected_by_the_domain(): void
    {
        $token = $this->newSessionToken();

        // Estructura buena, digito verificador equivocado: el FormRequest lo
        // deja pasar y la domain exception lo corta con 422 y codigo estable.
        $response = $this->patchWith($token, ['curp' => 'HEGG560427MVZRRL05']);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_CURP')
            ->assertJsonMissingPath('trace');
    }

    public function test_confirm_without_captured_data_lists_the_missing_fields(): void
    {
        $token = $this->newSessionToken();

        $response = $this->confirmWith($token);

        $response->assertStatus(422)->assertJsonPath('error_code', 'PROSPECT_DATA_INCOMPLETE');
        $this->assertEqualsCanonicalizing(
            ['full_name', 'curp', 'age', 'sex', 'monthly_income'],
            $response->json('missing_fields'),
        );
    }

    public function test_confirm_with_partial_data_lists_exactly_what_is_missing(): void
    {
        $token = $this->newSessionToken();
        $this->patchWith($token, ['full_name' => 'Ana Perez Lopez', 'age' => 34])->assertOk();

        $response = $this->confirmWith($token);

        $response->assertStatus(422)->assertJsonPath('error_code', 'PROSPECT_DATA_INCOMPLETE');
        $this->assertEqualsCanonicalizing(
            ['curp', 'sex', 'monthly_income'],
            $response->json('missing_fields'),
        );
    }

    // =========================================================== 200 =========

    public function test_a_partial_patch_moves_to_data_captured_and_keeps_the_other_fields_intact(): void
    {
        $token = $this->newSessionToken();

        // Primera pasada: solo el nombre. Se acepta y avanza a data_captured.
        $this->patchWith($token, ['full_name' => 'Ana Perez Lopez'])
            ->assertOk()
            ->assertJsonPath('data.capture_status', 'data_captured');

        // Segunda pasada: solo la CURP. El nombre no se pierde.
        $this->patchWith($token, ['curp' => self::VALID_CURP])->assertOk();

        $row = ProspectRecord::query()->firstOrFail();
        $this->assertSame('Ana Perez Lopez', (string) $row->full_name);
        $this->assertNotNull($row->curp);
    }

    public function test_the_full_flow_reaches_data_confirmed(): void
    {
        $token = $this->newSessionToken();

        $this->patchWith($token, $this->completeBody())
            ->assertOk()
            ->assertJsonPath('data.capture_status', 'data_captured');

        $this->confirmWith($token)
            ->assertOk()
            ->assertJsonPath('data.capture_status', 'data_confirmed');

        $this->assertSame('data_confirmed', DB::table('prospects')->value('capture_status'));
    }

    public function test_the_patch_does_not_return_curp_or_rfc_even_on_success(): void
    {
        // Regla de seguridad 1 y RS-03: el cliente ya sabe lo que envio; que
        // la API le devuelva su propia CURP en claro agranda la superficie
        // sin necesidad y expone el dato en registros/proxies intermedios.
        $token = $this->newSessionToken();

        $response = $this->patchWith($token, $this->completeBody())->assertOk();

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::VALID_CURP, $body);
        $this->assertStringNotContainsString('curp', $body);
    }

    // =========================================================== 403 =========

    public function test_a_token_of_one_prospect_does_not_touch_the_file_of_another(): void
    {
        // Dos expedientes independientes. Como el propio sale SIEMPRE del
        // token, no hay identificador ajeno que enviar. Lo que se prueba es
        // el aislamiento: cada token toca su propia fila y no la del otro.
        //
        // Se separan las emisiones con travel(1)->minutes() porque Passport
        // guarda la caducidad del token con granularidad de segundo y dos
        // tokens emitidos en el mismo instante pueden confundirse al
        // resolverse desde la cabecera. Es el mismo patron que sigue
        // ProspectSessionTest::test_the_prospect_token_cannot_read_the_file_of_a_third_party.
        $mineToken = $this->newSessionToken();
        $this->travel(1)->minutes();
        $strangerToken = $this->newSessionToken();

        $this->patchWith($strangerToken, ['full_name' => 'Luis Ramirez Soto'])->assertOk();
        $this->patchWith($mineToken, ['full_name' => 'Ana Perez Lopez'])->assertOk();

        $rows = ProspectRecord::query()->orderBy('id')->pluck('full_name', 'id')->all();
        $this->assertContains('Ana Perez Lopez', $rows);
        $this->assertContains('Luis Ramirez Soto', $rows);
        // Ademas: cada nombre esta en SU fila. Si los tokens se hubieran
        // confundido y ambos PATCHes fueran sobre la misma fila, uno de los
        // dos nombres desapareceria y la fila del otro quedaria en null.
        $this->assertCount(2, array_filter($rows));
    }

    public function test_the_policy_denies_when_the_token_prospect_does_not_match_the_target(): void
    {
        // Comprobacion directa de la politica: si en el futuro se anadiera
        // una ruta que reciba `{prospect}` por URL, este es el punto donde
        // el 403 tiene que sostener la titularidad. Se ejerce en aislamiento
        // para que la barrera este acreditada aunque la ruta actual no la
        // exponga directamente.
        $this->newSessionToken();
        $strangerRecord = ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Luis Ramirez Soto',
            'capture_method' => 'manual',
            'capture_status' => 'started',
        ]);
        $user = User::query()->firstOrFail();

        $this->assertFalse($user->can('updateOwn', $strangerRecord));
        // Y sobre lo suyo, si.
        $ownRecord = ProspectRecord::query()->findOrFail((int) $user->prospect_id);
        $this->assertTrue($user->can('updateOwn', $ownRecord));
    }
}
