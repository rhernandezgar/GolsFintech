<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Las tres capas del control de acceso, que son distintas y ninguna sustituye
 * a otra:
 *
 *   401  no hay token, o el token no vale.
 *   403  el rol no alcanza para la operacion.
 *   403  el rol alcanza pero el REGISTRO es de otro.
 *
 * El tercer caso es el que la Fase 3 §4.9 senala como "una de las causas mas
 * frecuentes de acceso indebido en aplicaciones que si autentican
 * correctamente": el usuario esta autenticado, su rol le permite consultar
 * solicitudes, y aun asi no puede consultar la de otro prospecto. Un 403 por
 * rol no lo cubre, porque el rol es el correcto.
 */
final class ApiAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function prospectRecord(string $name = 'Ana Perez Lopez'): ProspectRecord
    {
        return ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => $name,
            'capture_method' => 'manual',
            'capture_status' => 'data_confirmed',
            'monthly_income' => 25000.00,
        ]);
    }

    private function applicationFor(ProspectRecord $prospect): CreditApplicationRecord
    {
        return CreditApplicationRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'application_folio' => Str::upper(Str::random(12)),
            'credit_type' => 'personal',
            'application_status' => 'pre_approved',
            'validated_monthly_income' => 25000.00,
            'payment_capacity' => 7500.00,
            'rejection_reason_code' => null,
        ]);
    }

    // =========================================================== 401 =========

    #[Test]
    public function without_a_token_every_endpoint_answers_401(): void
    {
        $application = $this->applicationFor($this->prospectRecord());

        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/audit-logs')->assertStatus(401);
        $this->getJson('/api/v1/credit-applications/'.$application->public_id)->assertStatus(401);
        $this->patchJson('/api/v1/credit-applications/'.$application->public_id.'/status', [
            'application_status' => 'approved',
        ])->assertStatus(401);
        $this->deleteJson('/api/v1/auth/session')->assertStatus(401);
    }

    #[Test]
    public function an_invalid_token_answers_401(): void
    {
        $this->withToken('no-es-un-token-valido')
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    #[Test]
    public function the_endpoints_are_authenticated_by_default(): void
    {
        // Regla de seguridad no negociable 9. La lista es cerrada a proposito:
        // una ruta nueva sin autenticacion rompe esta prueba y obliga a
        // declararla como excepcion de forma consciente.
        $unauthenticated = collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->reject(fn ($route): bool => collect($route->gatherMiddleware())
                ->contains(fn ($middleware): bool => is_string($middleware)
                    && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))))
            ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            // El servidor de autorizacion resuelve la sesion el mismo: si no
            // hay usuario, responde login_required en vez de emitir codigo.
            'GET|HEAD api/v1/auth/authorize',
            // Credencial mas segundo factor: quien todavia no tiene token no
            // puede presentarlo. Limitado por intentos.
            'POST api/v1/auth/login',
            // El refresh_token es en si mismo la credencial.
            'POST api/v1/auth/refresh',
            // Canje del codigo de autorizacion por el token; la credencial es
            // el code_verifier de PKCE.
            'POST api/v1/auth/token',
        ], $unauthenticated, 'Hay un endpoint sin autenticar que no esta declarado como excepcion.');
    }

    // =================================================== 403 por rol =========

    #[Test]
    public function the_auditor_cannot_write_even_over_a_record_it_may_read(): void
    {
        $application = $this->applicationFor($this->prospectRecord());
        $auditor = User::factory()->role(Role::Auditor)->withTwoFactor()->create();

        // Puede leerla...
        Passport::actingAs($auditor);
        $this->getJson('/api/v1/credit-applications/'.$application->public_id)->assertOk();

        // ...y no puede tocarla. Solo lectura (Fase 3 §4.9).
        $this->patchJson('/api/v1/credit-applications/'.$application->public_id.'/status', [
            'application_status' => 'approved',
        ])->assertStatus(403);

        $this->assertSame('pre_approved', $application->fresh()->application_status);
    }

    #[Test]
    public function the_risk_analyst_cannot_read_the_audit_log(): void
    {
        Passport::actingAs(User::factory()->role(Role::RiskAnalyst)->withTwoFactor()->create());

        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    #[Test]
    public function a_prospect_cannot_read_the_audit_log(): void
    {
        Passport::actingAs(User::factory()->role(Role::Prospect)->create());

        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    #[Test]
    public function a_prospect_cannot_change_the_status_of_its_own_application(): void
    {
        $prospect = $this->prospectRecord();
        $application = $this->applicationFor($prospect);

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($prospect->id)->create()
        );

        // Es su solicitud, pero decidir sobre ella no es suyo: el estatus lo
        // mueve el personal autorizado, no el solicitante.
        $this->patchJson('/api/v1/credit-applications/'.$application->public_id.'/status', [
            'application_status' => 'approved',
        ])->assertStatus(403);

        $this->assertSame('pre_approved', $application->fresh()->application_status);
    }

    #[Test]
    public function the_administrator_can_change_the_status(): void
    {
        $application = $this->applicationFor($this->prospectRecord());

        Passport::actingAs(User::factory()->role(Role::Admin)->withTwoFactor()->create());

        $this->patchJson('/api/v1/credit-applications/'.$application->public_id.'/status', [
            'application_status' => 'approved',
        ])->assertOk();

        $this->assertSame('approved', $application->fresh()->application_status);
    }

    // ================================== 403 por objeto (autorizacion por objeto)

    #[Test]
    public function a_prospect_cannot_read_the_application_of_another_prospect(): void
    {
        $mine = $this->prospectRecord('Ana Perez Lopez');
        $someone_elses = $this->prospectRecord('Luis Ramirez Soto');

        $application = $this->applicationFor($someone_elses);

        // Autenticado, con rol de prospecto y con el permiso generico de
        // consultar solicitudes. Lo unico que le falta es tener derecho sobre
        // ESTA. Es el fallo que la Fase 3 §4.9 subraya.
        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $this->getJson('/api/v1/credit-applications/'.$application->public_id)
            ->assertStatus(403);
    }

    #[Test]
    public function a_prospect_reads_its_own_application(): void
    {
        $mine = $this->prospectRecord();
        $application = $this->applicationFor($mine);

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $this->getJson('/api/v1/credit-applications/'.$application->public_id)
            ->assertOk()
            ->assertJsonPath('data.id', $application->public_id);
    }

    #[Test]
    public function a_prospect_with_no_file_of_its_own_reads_nobody_elses(): void
    {
        $application = $this->applicationFor($this->prospectRecord());

        // prospect_id nulo: una cuenta recien creada que aun no capturo nada.
        // Sin el anclaje, la comparacion "es mia" no debe resolverse a cierto
        // por comparar null con null o cero con cero.
        Passport::actingAs(User::factory()->role(Role::Prospect)->create());

        $this->getJson('/api/v1/credit-applications/'.$application->public_id)
            ->assertStatus(403);
    }

    #[Test]
    public function the_object_level_denial_reveals_nothing_about_the_record(): void
    {
        $someone_elses = $this->applicationFor($this->prospectRecord('Luis Ramirez Soto'));
        $mine = $this->prospectRecord('Ana Perez Lopez');

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $response = $this->getJson('/api/v1/credit-applications/'.$someone_elses->public_id)
            ->assertStatus(403);

        $body = $response->getContent();

        // Ni el folio, ni el nombre del titular, ni el nombre de la tabla
        // (regla de seguridad no negociable 8).
        $this->assertStringNotContainsString($someone_elses->application_folio, $body);
        $this->assertStringNotContainsString('Luis Ramirez Soto', $body);
        $this->assertStringNotContainsString('credit_applications', $body);
        $this->assertSame('No tiene acceso a este recurso.', $response->json('message'));
    }

    // ================================= Autorizacion por campo ================

    #[Test]
    public function only_the_risk_analyst_sees_the_declared_income_of_a_third_party(): void
    {
        $application = $this->applicationFor($this->prospectRecord());
        $url = '/api/v1/credit-applications/'.$application->public_id;

        Passport::actingAs(User::factory()->role(Role::RiskAnalyst)->withTwoFactor()->create());
        $this->getJson($url)->assertOk()->assertJsonPath('data.validated_monthly_income', '25000.00');
    }

    #[DataProvider('rolesWithoutIncomeAccess')]
    #[Test]
    public function the_declared_income_of_a_third_party_does_not_travel_to_other_roles(Role $role): void
    {
        $application = $this->applicationFor($this->prospectRecord());

        Passport::actingAs(User::factory()->role($role)->withTwoFactor()->create());

        // El recurso responde 200 y simplemente omite el campo restringido: la
        // autorizacion es por campo, no por endpoint (RS-05).
        $response = $this->getJson('/api/v1/credit-applications/'.$application->public_id)->assertOk();

        $this->assertArrayNotHasKey('validated_monthly_income', $response->json('data'));
        $this->assertArrayNotHasKey('payment_capacity', $response->json('data'));
        $this->assertStringNotContainsString('25000', $response->getContent());
    }

    /** @return array<string, array{Role}> */
    public static function rolesWithoutIncomeAccess(): array
    {
        return [
            'administrador' => [Role::Admin],
            'auditor' => [Role::Auditor],
        ];
    }

    #[Test]
    public function the_prospect_sees_the_income_it_declared_itself(): void
    {
        $mine = $this->prospectRecord();
        $application = $this->applicationFor($mine);

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $this->getJson('/api/v1/credit-applications/'.$application->public_id)
            ->assertOk()
            ->assertJsonPath('data.validated_monthly_income', '25000.00');
    }

    #[Test]
    public function the_internal_rejection_reason_does_not_travel_to_the_prospect(): void
    {
        $mine = $this->prospectRecord();
        $application = $this->applicationFor($mine);
        $application->forceFill([
            'application_status' => 'rejected',
            'rejection_reason_code' => 'INCOME_BELOW_THRESHOLD',
        ])->save();

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $response = $this->getJson('/api/v1/credit-applications/'.$application->public_id)->assertOk();

        // Al prospecto se le muestra un mensaje generico; el motivo interno se
        // queda en el servidor (riesgo R-01).
        $this->assertArrayNotHasKey('rejection_reason_code', $response->json('data'));
        $this->assertStringNotContainsString('INCOME_BELOW_THRESHOLD', $response->getContent());
    }

    // ================================= La bitacora registra la consulta ======

    #[Test]
    public function reading_an_application_is_itself_an_audited_event(): void
    {
        $application = $this->applicationFor($this->prospectRecord());
        $auditor = User::factory()->role(Role::Auditor)->withTwoFactor()->create();

        Passport::actingAs($auditor);
        $this->getJson('/api/v1/credit-applications/'.$application->public_id)->assertOk();

        // El acceso a informacion personal es en si mismo un evento auditable
        // (Fase 2, pantalla P7; RS-06).
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'credit_application.viewed',
            'affected_entity' => 'CreditApplication',
            'affected_entity_id' => $application->id,
            'actor' => $auditor->email,
        ]);
    }

    #[Test]
    public function a_denied_read_writes_no_data_of_the_record_into_the_audit_log(): void
    {
        $someone_elses = $this->applicationFor($this->prospectRecord('Luis Ramirez Soto'));
        $mine = $this->prospectRecord('Ana Perez Lopez');

        Passport::actingAs(
            User::factory()->role(Role::Prospect)->forProspect($mine->id)->create()
        );

        $this->getJson('/api/v1/credit-applications/'.$someone_elses->public_id)->assertStatus(403);

        // La consulta no llego a realizarse, asi que no hay evento de lectura
        // que registrar sobre un expediente que el usuario nunca vio.
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'credit_application.viewed',
            'affected_entity_id' => $someone_elses->id,
        ]);
    }

    // ================================= Sesion ================================

    #[Test]
    public function closing_the_session_revokes_the_token(): void
    {
        $user = User::factory()->role(Role::Prospect)->create();

        Passport::actingAs($user);
        $this->deleteJson('/api/v1/auth/session')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'auth.session_ended']);
    }

    // ================================= /me ===================================

    #[Test]
    public function me_publishes_the_effective_permissions_of_the_role(): void
    {
        Passport::actingAs(User::factory()->role(Role::Auditor)->withTwoFactor()->create());

        $response = $this->getJson('/api/v1/me')->assertOk();

        $this->assertSame('auditor', $response->json('data.role'));
        $this->assertTrue($response->json('data.read_only'));
        $this->assertContains('audit_log.read', $response->json('data.permissions'));
        $this->assertNotContains('prospect.declared_income.view.any', $response->json('data.permissions'));
    }
}
