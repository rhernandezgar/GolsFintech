<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use App\Infrastructure\Security\TotpAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceso con credencial y segundo factor (RS-01, Fase 3 §4.4).
 *
 * El segundo factor no es opcional para los perfiles administrativos, y eso
 * significa tres cosas distintas que se comprueban por separado: que sin codigo
 * no se entra, que con un codigo incorrecto tampoco, y que un perfil
 * administrativo sin segundo factor dado de alta queda fuera en vez de quedar
 * exento.
 */
final class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'C0ntrasena-De-Prueba!2026';

    private TotpAuthenticator $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = app(TotpAuthenticator::class);
        RateLimiter::clear('');
    }

    private function auditor(?string $secret = null): User
    {
        return User::factory()
            ->role(Role::Auditor)
            ->withTwoFactor($secret ?? $this->totp->generateSecret())
            ->create(['password' => Hash::make(self::PASSWORD)]);
    }

    // --- Perfil administrativo ----------------------------------------------

    #[Test]
    public function an_administrative_profile_enters_with_password_and_totp_code(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'totp_code' => $this->totp->codeAt($secret, time()),
        ])->assertOk()->assertJsonPath('two_factor_verified', true);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_administrative_profile_does_not_enter_with_the_password_alone(): void
    {
        $user = $this->auditor();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $this->assertGuest();
    }

    #[Test]
    public function an_administrative_profile_does_not_enter_with_a_wrong_totp_code(): void
    {
        $user = $this->auditor();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'totp_code' => '000000',
        ])->assertStatus(401);

        $this->assertGuest();
    }

    #[Test]
    public function an_expired_totp_code_is_not_accepted(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            // Diez minutos atras: muy fuera de la ventana de tolerancia.
            'totp_code' => $this->totp->codeAt($secret, time() - 600),
        ])->assertStatus(401);

        $this->assertGuest();
    }

    #[Test]
    public function an_administrative_profile_without_a_second_factor_is_locked_out(): void
    {
        // Sin withTwoFactor(): el usuario existe con su rol pero nunca dio de
        // alta el segundo factor. No se le exime del control, se le niega.
        $user = User::factory()->role(Role::Admin)->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $this->assertGuest();
    }

    // --- Prospecto ----------------------------------------------------------

    #[Test]
    public function a_prospect_does_not_need_a_second_factor(): void
    {
        $user = User::factory()->role(Role::Prospect)->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor_verified', false);

        $this->assertAuthenticatedAs($user);
    }

    // --- Enumeracion de usuarios --------------------------------------------

    #[Test]
    public function the_error_does_not_distinguish_an_unknown_email_from_a_wrong_password(): void
    {
        $user = User::factory()->role(Role::Prospect)->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nadie@golsfintech.mx',
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'otra-contrasena-cualquiera',
        ])->assertStatus(401);

        // Distinguirlos permite averiguar que correos estan registrados.
        //
        // Se comparan los cuerpos SIN el identificador de correlacion (T10):
        // es distinto en cada peticion por diseno, asi que comparar los cuerpos
        // enteros no probaria nada —diferirian siempre—. Que sea unico no
        // filtra nada: es opaco y no dice si la cuenta existe.
        $this->assertSame(
            $this->bodyWithoutCorrelationId($unknown->json()),
            $this->bodyWithoutCorrelationId($wrongPassword->json()),
        );

        // Y aun asi, cada intento es rastreable por separado en el registro.
        $this->assertNotSame(
            $unknown->json('correlation_id'),
            $wrongPassword->json('correlation_id'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function bodyWithoutCorrelationId(array $body): array
    {
        unset($body['correlation_id']);

        return $body;
    }

    #[Test]
    public function the_error_message_carries_no_technical_detail(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nadie@golsfintech.mx',
            'password' => 'lo-que-sea',
        ])->assertStatus(401);

        // El cuerpo lleva EXACTAMENTE dos claves: el mensaje generico y el
        // identificador de correlacion (T10). La comparacion sigue siendo
        // exacta a proposito —no `assertJsonFragment`— para que anadir un
        // campo nuevo obligue a decidir aqui si ese campo puede viajar.
        $this->assertSame(
            ['message', 'correlation_id'],
            array_keys($response->json()),
        );
        $this->assertSame('Credenciales invalidas.', $response->json('message'));

        // El identificador es opaco: no dice nada del fallo ni de la cuenta.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $response->json('correlation_id'));
        $this->assertStringNotContainsString('nadie@golsfintech.mx', $response->getContent());
    }

    // --- Contrasenas --------------------------------------------------------

    #[Test]
    public function passwords_are_stored_with_argon2id(): void
    {
        $user = User::factory()->role(Role::Prospect)->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        // El prefijo del hash identifica el algoritmo. Ni bcrypt ($2y$) ni
        // ningun hash rapido: Argon2id, con sal unica incluida en el propio
        // hash (regla de seguridad no negociable 6).
        $this->assertStringStartsWith('$argon2id$', $user->fresh()->password);
    }

    #[Test]
    public function the_totp_secret_is_not_stored_in_clear_text(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);

        $stored = DB::table('users')
            ->where('id', $user->id)
            ->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, (string) $stored);
        // Y descifra de vuelta al mismo valor.
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    #[Test]
    public function the_totp_secret_never_travels_in_a_response(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    // --- Bitacora -----------------------------------------------------------

    #[Test]
    public function a_successful_login_is_recorded_in_the_audit_log(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'totp_code' => $this->totp->codeAt($secret, time()),
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'auth.login_succeeded',
            'affected_entity' => 'User',
            'affected_entity_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_failed_login_is_recorded_in_the_audit_log(): void
    {
        $user = $this->auditor();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'otra-contrasena-cualquiera',
        ])->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'auth.login_failed']);
    }

    #[Test]
    public function a_failed_second_factor_is_recorded_apart_from_a_failed_password(): void
    {
        $user = $this->auditor();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'totp_code' => '000000',
        ])->assertStatus(401);

        // El cliente recibe el mismo mensaje en ambos casos, pero la bitacora
        // si los distingue: quien acierta la contrasena y falla el segundo
        // factor es una senal muy distinta de quien no acierta ninguno.
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'auth.two_factor_failed']);
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'auth.login_failed']);
    }

    #[Test]
    public function the_audit_log_of_a_login_records_no_password_or_code(): void
    {
        $secret = $this->totp->generateSecret();
        $user = $this->auditor($secret);
        $code = $this->totp->codeAt($secret, time());

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'totp_code' => $code,
        ])->assertOk();

        $entries = AuditLogRecord::query()->get();

        foreach ($entries as $entry) {
            $serialized = json_encode($entry->getAttributes());

            $this->assertStringNotContainsString(self::PASSWORD, $serialized);
            $this->assertStringNotContainsString($secret, $serialized);
            $this->assertStringNotContainsString($code, $serialized);
        }
    }

    // --- Anti-automatizacion ------------------------------------------------

    #[Test]
    public function repeated_attempts_against_one_account_are_throttled(): void
    {
        $user = $this->auditor();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'otra-contrasena-cualquiera',
            ])->assertStatus(401);
        }

        // El sexto ya no llega a comprobar la contrasena (RS-10).
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'otra-contrasena-cualquiera',
        ])->assertStatus(429);
    }
}
