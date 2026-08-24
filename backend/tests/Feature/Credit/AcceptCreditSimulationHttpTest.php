<?php

declare(strict_types=1);

namespace Tests\Feature\Credit;

use App\Application\DTO\ProspectDataInput;
use App\Application\UseCase\Credit\SimulateCredit;
use App\Application\UseCase\Identity\ValidateIdentity;
use App\Application\UseCase\Prospect\CaptureProspectData;
use App\Application\UseCase\Prospect\ConfirmProspectData;
use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Domain\Access\Role;
use App\Domain\Audit\AuditContext;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\DocumentRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Infrastructure\Security\ProspectSessionIssuer;
use App\Models\User;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P6: endpoint HTTP de aceptacion.
 *
 * Se prueban las tres invariantes que sostienen el paso:
 *
 * - Titularidad: la simulacion pertenece al prospecto autenticado. Un token
 *   ajeno no puede aceptar (CWE-639).
 * - Vigencia: pasada la caducidad la aceptacion falla con
 *   `CREDIT_SIMULATION_EXPIRED`.
 * - Doble aceptacion: aceptar dos veces la misma oferta no crea dos clientes.
 *   Falla con `CREDIT_SIMULATION_ALREADY_DECIDED`.
 *
 * Ademas se comprueba la **reemision del token**: al aceptar, el de prospecto
 * queda revocado y se emite uno con alcance `customer-session`.
 */
final class AcceptCreditSimulationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Requerido por `ProspectSessionIssuer::promoteToCustomer` que emite
        // token via Passport::createToken (personal access grant).
        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    /**
     * Se usa el reloj real de la aplicacion (`now()`) en vez de una hora
     * fija: el controller invoca los use cases con `now()->toImmutable()`,
     * y solo asi las simulaciones que quedan en `expires_at = now + TTL`
     * pueden compararse con el `now` del controller sin choques de reloj.
     * `Carbon::setTestNow`/`$this->travel()` mueven `now()` consistentemente
     * para los dos usos.
     */
    private function now(): DateTimeImmutable
    {
        return now()->toImmutable();
    }

    private function context(): AuditContext
    {
        return new AuditContext('prospect:test', '198.51.100.7');
    }

    private function prospectReadyToSimulate(string $curp = 'HEGG560427MVZRRL04'): Prospect
    {
        $prospect = $this->app->make(StartProspectCapture::class)
            ->execute(CaptureMethod::Manual, '2026-08-01', $this->context(), $this->now());

        $this->app->make(CaptureProspectData::class)->execute(
            $prospect->publicId(),
            new ProspectDataInput(
                fullName: 'Ana Perez Lopez',
                curp: $curp,
                rfc: 'GODE561231GR8',
                age: 34,
                sex: 'M',
                monthlyIncome: '20000.00',
                businessType: null,
                email: 'ana.perez@example.mx',
                phone: '5512345678',
            ),
            $this->context(),
            $this->now(),
        );
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now());

        $documents = $this->app->make(DocumentRepository::class);
        $document = $documents->save(IdentityDocument::register(
            prospectId: (int) $prospect->id(),
            documentType: DocumentType::Ine,
            storagePath: 'documents/2026/ine.jpg',
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 250_000,
            fileHash: hash('sha256', 'ine-content'),
        ));

        $this->app->make(ValidateIdentity::class)
            ->execute($prospect->publicId(), $document->publicId(), $this->context(), $this->now());

        return $prospect;
    }

    /** Persiste una simulacion y devuelve su UUID publico. */
    private function simulateFor(Prospect $prospect): string
    {
        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->now());

        return $offer->simulationPublicId;
    }

    private function tokenForProspect(Prospect $prospect): User
    {
        $record = ProspectRecord::query()->findOrFail($prospect->id());
        $user = User::factory()->role(Role::Prospect)->forProspect($record->id)->create();
        Passport::actingAs($user, [ProspectSessionIssuer::PROSPECT_SCOPE]);

        return $user;
    }

    #[Test]
    public function without_a_token_the_endpoint_answers_401(): void
    {
        $this->postJson('/api/v1/credit-simulations/00000000-0000-0000-0000-000000000000/accept')
            ->assertStatus(401);
    }

    #[Test]
    public function the_happy_path_registers_the_customer_and_issues_a_customer_token(): void
    {
        $prospect = $this->prospectReadyToSimulate();
        $simulationUuid = $this->simulateFor($prospect);
        $this->tokenForProspect($prospect);

        $response = $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'customer' => ['customer_number', 'contract_folio', 'authorized_amount', 'line_status', 'card_last_four'],
                'session' => ['access_token', 'scope', 'expires_in'],
            ]])
            ->assertJsonPath('data.session.scope', ProspectSessionIssuer::CUSTOMER_SCOPE);

        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('credit_lines')->count());
        // La tarjeta se emite con el CardIssuer simulado, asi que en el
        // happy path la fila de cards tambien queda escrita.
        $this->assertSame(1, DB::table('cards')->count());
    }

    #[Test]
    public function accepting_promotes_the_user_role_and_revokes_the_prospect_token(): void
    {
        $prospect = $this->prospectReadyToSimulate();
        $simulationUuid = $this->simulateFor($prospect);
        $user = $this->tokenForProspect($prospect);

        $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept')->assertOk();

        // Rol promovido a customer (quinta precision del bloque 1: el token
        // de prospecto queda revocado; el nuevo scope es customer-session).
        $this->assertSame(Role::Customer, $user->refresh()->role);

        // Todos los tokens del user quedan revocados (los emitidos como
        // prospecto), y `promoteToCustomer` emitio uno nuevo. Por eso lo que
        // no debe sobrevivir es NINGUN token de prospecto: la unica fila
        // viva en oauth_access_tokens es del scope customer-session.
        $liveScopes = DB::table('oauth_access_tokens')
            ->where('revoked', false)
            ->pluck('scopes')
            ->all();

        $this->assertNotEmpty($liveScopes);
        foreach ($liveScopes as $raw) {
            $this->assertStringContainsString(ProspectSessionIssuer::CUSTOMER_SCOPE, (string) $raw);
            $this->assertStringNotContainsString(ProspectSessionIssuer::PROSPECT_SCOPE, (string) $raw);
        }
    }

    #[Test]
    public function accepting_twice_the_same_offer_does_not_create_two_customers(): void
    {
        // Precision de negocio: un doble click de la SPA no debe generar dos
        // clientes. La segunda aceptacion falla con codigo estable propio,
        // no con InvalidStateTransition generico.
        $prospect = $this->prospectReadyToSimulate();
        $simulationUuid = $this->simulateFor($prospect);
        $this->tokenForProspect($prospect);

        $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept')->assertOk();

        $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CREDIT_SIMULATION_ALREADY_DECIDED');

        // Solo un cliente, solo una linea, solo una tarjeta.
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('credit_lines')->count());
        $this->assertSame(1, DB::table('cards')->count());
    }

    #[Test]
    public function an_expired_simulation_cannot_be_accepted(): void
    {
        // TTL por defecto 1800 s. Al hacer travel(31 min), la vigencia ya
        // paso y la aceptacion se corta con codigo estable de caducidad.
        $prospect = $this->prospectReadyToSimulate();
        $simulationUuid = $this->simulateFor($prospect);
        $this->tokenForProspect($prospect);

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CREDIT_SIMULATION_EXPIRED');

        $this->assertSame(0, DB::table('customers')->count());
    }

    #[Test]
    public function a_simulation_of_another_prospect_returns_a_generic_403(): void
    {
        // CWE-639 en integracion: el UUID de la simulacion viaja por la URL
        // y el use case comprueba que pertenece al prospecto del token.
        $owner = $this->prospectReadyToSimulate('HEGG560427MVZRRL04');
        $stranger = $this->prospectReadyToSimulate('XEXX030303HNEXXXA8');
        $simulationUuid = $this->simulateFor($owner);
        // Nos autenticamos como stranger e intentamos aceptar la del owner.
        $this->tokenForProspect($stranger);

        $this->postJson('/api/v1/credit-simulations/'.$simulationUuid.'/accept')
            ->assertStatus(403)
            // Mismo mensaje generico que las politicas: no revela si el
            // recurso existe.
            ->assertJsonPath('message', 'No tiene acceso a este recurso.');

        $this->assertSame(0, DB::table('customers')->count());
    }
}
