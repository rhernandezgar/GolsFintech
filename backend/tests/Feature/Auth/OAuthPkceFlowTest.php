<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Servidor OAuth2: codigo de autorizacion con PKCE (S256) para un cliente
 * publico, tal como lo exige la Fase 3 §4.4.
 *
 * Se recorre el flujo completo de verdad —autorizar, canjear, usar el token—
 * en vez de comprobar la configuracion: que la propiedad
 * Passport::$implicitGrantEnabled valga false no acredita que el servidor
 * rechace una peticion implicita, y es el rechazo lo que protege.
 */
final class OAuthPkceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private const REDIRECT_URI = 'http://127.0.0.1:6060/auth/callback';

    protected function setUp(): void
    {
        parent::setUp();

        // confidential: false -> cliente publico, sin secreto de cliente.
        $this->client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'GolsFintech SPA',
            redirectUris: [self::REDIRECT_URI],
            confidential: false,
        );
    }

    // --- El cliente es publico ----------------------------------------------

    #[Test]
    public function the_spa_client_has_no_client_secret(): void
    {
        $this->assertFalse($this->client->confidential());
        $this->assertNull($this->client->getAttributes()['secret'] ?? null);
    }

    // --- Flujo completo -----------------------------------------------------

    #[Test]
    public function the_full_authorization_code_flow_with_pkce_issues_a_usable_token(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        $verifier = $this->codeVerifier();

        $code = $this->authorizationCodeFor($user, $this->challengeFor($verifier));

        $response = $this->post('/api/v1/auth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            // Sin client_secret: el verificador ocupa su lugar.
            'code_verifier' => $verifier,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);

        // El token emitido sirve de verdad contra un endpoint autenticado.
        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    #[Test]
    public function the_refresh_token_renews_the_access_token(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();
        $verifier = $this->codeVerifier();

        $tokens = $this->post('/api/v1/auth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $this->authorizationCodeFor($user, $this->challengeFor($verifier)),
            'code_verifier' => $verifier,
        ])->assertOk();

        $this->post('/api/v1/auth/refresh', [
            'client_id' => $this->client->getKey(),
            'refresh_token' => $tokens->json('refresh_token'),
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    // --- PKCE obligatorio ---------------------------------------------------

    #[Test]
    public function an_authorization_request_without_pkce_is_rejected(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/authorize?'.http_build_query([
                'client_id' => $this->client->getKey(),
                'redirect_uri' => self::REDIRECT_URI,
                'response_type' => 'code',
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    #[Test]
    public function the_plain_code_challenge_method_is_rejected(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();
        $verifier = $this->codeVerifier();

        // `plain` envia el verificador tal cual: quien intercepte la peticion
        // se queda con el y PKCE deja de proteger nada. RFC 7636 lo permite;
        // este servidor no.
        $this->actingAs($user)
            ->getJson('/api/v1/auth/authorize?'.http_build_query([
                'client_id' => $this->client->getKey(),
                'redirect_uri' => self::REDIRECT_URI,
                'response_type' => 'code',
                'code_challenge' => $verifier,
                'code_challenge_method' => 'plain',
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    #[Test]
    public function omitting_the_method_does_not_fall_back_to_plain(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        // RFC 7636 §4.3 dice que si se omite el metodo, el valor por defecto
        // es `plain`. Aceptar la omision equivaldria a aceptar `plain`.
        $this->actingAs($user)
            ->getJson('/api/v1/auth/authorize?'.http_build_query([
                'client_id' => $this->client->getKey(),
                'redirect_uri' => self::REDIRECT_URI,
                'response_type' => 'code',
                'code_challenge' => $this->challengeFor($this->codeVerifier()),
            ]))
            ->assertStatus(400);
    }

    #[Test]
    public function an_intercepted_code_is_useless_without_the_verifier(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        $code = $this->authorizationCodeFor($user, $this->challengeFor($this->codeVerifier()));

        // Es el escenario que PKCE existe para cubrir: el atacante tiene el
        // codigo —lo vio pasar en la redireccion— pero no el verificador, que
        // nunca salio del navegador legitimo.
        $this->post('/api/v1/auth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->codeVerifier(),
        ])->assertStatus(400);
    }

    #[Test]
    public function the_code_cannot_be_exchanged_without_any_verifier(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        $code = $this->authorizationCodeFor($user, $this->challengeFor($this->codeVerifier()));

        $this->post('/api/v1/auth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
        ])->assertStatus(400);
    }

    // --- Flujo implicito: descartado ----------------------------------------

    #[Test]
    public function the_implicit_grant_is_disabled_in_passport(): void
    {
        // Si alguien anadiera Passport::enableImplicitGrant() al arranque, esta
        // asercion lo detiene antes de que llegue a produccion.
        $this->assertFalse(Passport::$implicitGrantEnabled);
    }

    #[Test]
    public function an_implicit_authorization_request_is_rejected(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        // response_type=token entrega el token en el fragmento de la
        // redireccion, donde queda en el historial del navegador y en los
        // registros de los servidores intermedios (Fase 3 §4.4).
        $this->actingAs($user)
            ->getJson('/api/v1/auth/authorize?'.http_build_query([
                'client_id' => $this->client->getKey(),
                'redirect_uri' => self::REDIRECT_URI,
                'response_type' => 'token',
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'unsupported_response_type');
    }

    #[Test]
    public function the_implicit_grant_type_is_rejected_at_the_token_endpoint(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'grant_type' => 'implicit',
            'client_id' => $this->client->getKey(),
        ])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }

    #[Test]
    public function the_password_grant_is_disabled(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        // El flujo de contrasena obligaria a la SPA a manejar la credencial en
        // claro y es incompatible con el segundo factor obligatorio.
        $this->postJson('/api/v1/auth/token', [
            'grant_type' => 'password',
            'client_id' => $this->client->getKey(),
            'username' => $user->email,
            'password' => 'password',
        ])->assertStatus(400);
    }

    // --- Ayudantes ----------------------------------------------------------

    /** Verificador de 43 a 128 caracteres del alfabeto de RFC 7636 §4.1. */
    private function codeVerifier(): string
    {
        return Str::random(64);
    }

    /** S256: base64url(sha256(verifier)), sin relleno (RFC 7636 §4.2). */
    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Recorre /authorize y devuelve el codigo que el servidor manda en el
     * redirect_uri.
     *
     * No hay paso de consentimiento: el cliente es de primera parte y
     * FirstPartyClient lo omite, asi que /authorize responde directamente con
     * la redireccion que lleva el codigo.
     */
    private function authorizationCodeFor(User $user, string $challenge): string
    {
        $query = http_build_query([
            'client_id' => $this->client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $redirect = $this->actingAs($user)
            ->get('/api/v1/auth/authorize?'.$query)
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT_URI, $redirect);

        parse_str(parse_url($redirect, PHP_URL_QUERY) ?? '', $params);

        $this->assertArrayHasKey('code', $params, 'El servidor no devolvio codigo de autorizacion.');

        return $params['code'];
    }
}
