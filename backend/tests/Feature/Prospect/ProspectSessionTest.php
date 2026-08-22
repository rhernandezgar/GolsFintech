<?php

declare(strict_types=1);

namespace Tests\Feature\Prospect;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use App\Infrastructure\Security\ProspectSessionIssuer;
use App\Models\User;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * P1: la sesion del prospecto anonimo.
 *
 * Es la quinta excepcion a la regla de seguridad no negociable 9 y la unica del
 * recorrido del prospecto, asi que lo que se prueba aqui no es solo que
 * funcione, sino que lo que compensa la exposicion este de verdad puesto: el
 * CAPTCHA, la vigencia corta, el alcance acotado y la evidencia del
 * consentimiento.
 */
final class ProspectSessionTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CAPTCHA = 'captcha-ok';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    /** @param array<string, mixed> $overrides */
    private function start(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/prospects', array_merge([
            'capture_method' => 'ocr',
            'privacy_notice_accepted' => true,
            'captcha_token' => self::VALID_CAPTCHA,
        ], $overrides));
    }

    /** Cadena minima expediente -> solicitud -> cliente, que la FK de users exige. */
    private function registerCustomerFor(int $prospectId): int
    {
        $applicationId = DB::table('credit_applications')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospectId,
            'application_folio' => 'AP-'.Str::upper(Str::random(8)),
            'application_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('customers')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospectId,
            'credit_application_id' => $applicationId,
            'customer_number' => 'GF-0000001',
            'full_name' => 'Ana Perez Lopez',
            'contract_folio' => 'CT-'.Str::upper(Str::random(8)),
            'contract_version' => '2026-08-01',
            'consent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_opens_a_file_and_returns_a_token_without_any_previous_account(): void
    {
        $response = $this->start();

        $response->assertCreated()
            ->assertJsonPath('data.capture_method', 'ocr')
            ->assertJsonPath('data.capture_status', 'started')
            ->assertJsonStructure(['data' => ['tracking_id', 'session' => [
                'access_token', 'token_type', 'scope', 'expires_in', 'expires_at', 'renew_before',
            ]]]);

        $this->assertSame(ProspectSessionIssuer::PROSPECT_SCOPE, $response->json('data.session.scope'));
        $this->assertDatabaseCount('prospects', 1);
    }

    public function test_the_token_it_issues_works_on_the_prospect_own_file(): void
    {
        $token = $this->start()->json('data.session.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/prospects/me')
            ->assertOk()
            ->assertJsonPath('data.capture_status', 'started')
            ->assertJsonPath('data.has_data', false);
    }

    // -------------------------------------------------------------- vigencia corta

    public function test_the_session_lasts_thirty_minutes_and_not_longer(): void
    {
        $session = $this->start()->json('data.session');

        $this->assertSame(1800, $session['expires_in']);

        // La caducidad se comprueba sobre el token emitido y no viajando en el
        // tiempo: la valida league/oauth2-server contra el reloj real del
        // sistema, que Carbon::setTestNow no toca. Lo que hay que acreditar es
        // que la ventana son 30 minutos, y eso esta aqui.
        $token = Token::query()->firstOrFail();

        $this->assertEqualsWithDelta(
            1800,
            abs(now()->diffInSeconds($token->expires_at)),
            5.0,
            'La sesion del prospecto tiene que caducar a los 30 minutos.'
        );
    }

    public function test_renewing_issues_a_new_token_and_does_not_strand_requests_already_in_flight(): void
    {
        $first = $this->start()->json('data.session.access_token');

        $this->travel(20)->minutes();

        $second = $this->withHeader('Authorization', 'Bearer '.$first)
            ->postJson('/api/v1/prospects/me/session')
            ->assertOk()
            ->json('data.session.access_token');

        $this->assertNotSame($first, $second);

        // El anterior sigue sirviendo hasta que caduque solo: P3 consulta el
        // estado del OCR en bucle y revocar en caliente cortaria la peticion
        // que ya iba por el cable.
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/prospects/me')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$second)
            ->getJson('/api/v1/prospects/me')->assertOk();
    }

    // ------------------------------------------------------------ anti-automatizacion

    public function test_a_wrong_captcha_is_rejected_and_no_file_is_created(): void
    {
        $this->start(['captcha_token' => 'no-soy-una-persona'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No pudimos verificar que la solicitud viene de una persona. Intentalo de nuevo.');

        $this->assertDatabaseCount('prospects', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_missing_captcha_is_rejected_by_validation(): void
    {
        $this->start(['captcha_token' => null])->assertStatus(422)->assertJsonValidationErrors('captcha_token');
    }

    public function test_the_rejected_captcha_leaves_a_trace(): void
    {
        $this->start(['captcha_token' => 'guion-automatizado']);

        $record = AuditLogRecord::query()->firstOrFail();

        $this->assertSame('auth.authorization_denied', $record->event_type);
        $this->assertSame('captcha_failed', $record->metadata['reason']);
        $this->assertNotNull($record->ip_address);
    }

    public function test_the_public_endpoint_is_rate_limited_per_address(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->start()->assertCreated();
        }

        $this->start()->assertStatus(429);
    }

    // ------------------------------------------------------------------- consentimiento

    public function test_the_privacy_notice_must_be_accepted(): void
    {
        $this->start(['privacy_notice_accepted' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('privacy_notice_accepted');

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_the_consent_is_recorded_with_timestamp_version_and_address(): void
    {
        $this->start();

        $record = AuditLogRecord::query()->where('event_type', 'prospect.started')->firstOrFail();

        // No basta un booleano: la LFPDPPP exige poder decir QUE texto se
        // acepto, cuando y desde donde.
        $this->assertNotNull($record->metadata['privacy_notice_accepted_at']);
        $this->assertSame(config('security.privacy_notice.version'), $record->metadata['privacy_notice_version']);
        $this->assertNotNull($record->ip_address);
        $this->assertNotNull($record->event_at);
    }

    // ------------------------------------------------------------------- alcance acotado

    public function test_the_prospect_token_does_not_open_administrative_endpoints(): void
    {
        $token = $this->start()->json('data.session.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_the_prospect_token_cannot_read_the_file_of_a_third_party(): void
    {
        $mine = $this->start()->json('data.session.access_token');
        $this->travel(1)->minutes();
        $theirs = $this->start()->json('data.tracking_id');

        // No hay endpoint que acepte el expediente por parametro: el mio sale
        // del token y el ajeno no se puede ni nombrar. Se comprueba que lo que
        // devuelve es el propio.
        $mineTrackingId = $this->withHeader('Authorization', 'Bearer '.$mine)
            ->getJson('/api/v1/prospects/me')->json('data.tracking_id');

        $this->assertNotSame($theirs, $mineTrackingId);
    }

    public function test_a_token_without_the_scope_is_refused_even_with_the_right_role(): void
    {
        $this->start();
        $user = User::query()->firstOrFail();

        // Mismo usuario, mismo rol, token sin el alcance: el scope es una
        // segunda barrera independiente del rol.
        Passport::actingAs($user, []);

        $this->getJson('/api/v1/prospects/me')->assertForbidden();
    }

    // --------------------------------------------------------- la cuenta no es una cuenta

    public function test_the_generated_user_cannot_log_in_with_a_password(): void
    {
        $this->start();
        $user = User::query()->firstOrFail();

        $this->assertSame(Role::Prospect, $user->role);
        $this->assertStringEndsWith('@prospect.invalid', $user->email);

        // La contrasena es aleatoria de 64 caracteres y no se devuelve en
        // ninguna respuesta: no hay credencial que presentar aqui.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    // ------------------------------------------------------------ promocion a cliente

    public function test_promoting_to_customer_revokes_every_prospect_token(): void
    {
        $token = $this->start()->json('data.session.access_token');
        $user = User::query()->firstOrFail();

        $customerId = $this->registerCustomerFor((int) $user->prospect_id);

        $issued = $this->app->make(ProspectSessionIssuer::class)->promoteToCustomer($user, $customerId);

        $this->assertSame(ProspectSessionIssuer::CUSTOMER_SCOPE, $issued->scope);
        $this->assertSame(Role::Customer, $user->refresh()->role);

        // El token de prospecto no escala solo: deja de valer.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/prospects/me')->assertUnauthorized();
    }
}
