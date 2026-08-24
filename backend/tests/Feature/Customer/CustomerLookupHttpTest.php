<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use App\Infrastructure\Persistence\Eloquent\CreditLineRecord;
use App\Infrastructure\Persistence\Eloquent\CustomerRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P7: consulta administrativa del cliente por su numero.
 *
 * Es el UNICO endpoint del bloque 6 que usa rol administrativo y no el token
 * de prospecto. Las cinco invariantes:
 *
 * - 401 sin token.
 * - 403 con token de prospecto o de cliente: su alcance es solo lo propio.
 * - 200 para admin, auditor y analista de riesgos (tienen ViewAnyCustomer).
 * - `declared_income` NO viaja al admin ni al auditor (RS-05): solo el
 *   analista de riesgos lo ve.
 * - El acceso queda auditado (RS-06.a): evento `customer.looked_up`.
 */
final class CustomerLookupHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_NUMBER = 'CU-20260822-ABC12345';

    private const DECLARED_INCOME = 22500.00;

    private function fixtureCustomer(): CustomerRecord
    {
        $prospect = ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_confirmed',
        ]);

        $application = CreditApplicationRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'application_folio' => 'APP-'.Str::upper(Str::random(12)),
            'credit_type' => 'personal',
            'application_status' => 'approved',
            'validated_monthly_income' => self::DECLARED_INCOME,
            'payment_capacity' => 6750.00,
            'decided_at' => now(),
        ]);

        $customer = CustomerRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'credit_application_id' => $application->id,
            'customer_number' => self::CUSTOMER_NUMBER,
            'full_name' => 'Ana Perez Lopez',
            'contract_folio' => 'CTR-'.Str::upper(Str::random(12)),
            'contract_version' => '2026-08-01',
            'consent_at' => now(),
            'activated_at' => now(),
        ]);

        CreditLineRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'credit_simulation_id' => $this->fabricSimulationId($application->id),
            'authorized_amount' => 50000.00,
            'available_balance' => 50000.00,
            'currency' => 'MXN',
            'annual_rate' => 0.2850,
            'term_months' => 24,
            'line_status' => 'active',
            'opened_at' => now(),
        ]);

        return $customer;
    }

    private function fabricSimulationId(int $applicationId): int
    {
        return \DB::table('credit_simulations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'credit_application_id' => $applicationId,
            'simulation_folio' => 'SIM-'.Str::upper(Str::random(12)),
            'proposed_amount' => 50000.00,
            'annual_rate' => 0.2850,
            'cat' => 0.3200,
            'term_months' => 24,
            'estimated_monthly_payment' => 2760.55,
            'total_payable' => 66253.20,
            'simulation_status' => 'accepted',
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actAs(Role $role): User
    {
        $user = User::factory()->role($role);
        // Los perfiles administrativos exigen 2FA (Role::isStaff), y el
        // factory tiene helper para simularlo.
        if ($role->isStaff()) {
            $user = $user->withTwoFactor();
        }
        $user = $user->create();
        Passport::actingAs($user);

        return $user;
    }

    #[Test]
    public function without_a_token_the_endpoint_answers_401(): void
    {
        $this->fixtureCustomer();

        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)->assertStatus(401);
    }

    #[Test]
    public function a_prospect_token_cannot_access_p7(): void
    {
        // Alcance del prospecto: solo lo suyo. Ni siquiera con su propio
        // numero: P7 es panel administrativo.
        $this->fixtureCustomer();
        $this->actAs(Role::Prospect);

        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)->assertStatus(403);
    }

    #[Test]
    public function a_customer_token_cannot_access_p7_either(): void
    {
        // Alcance del cliente: solo sus propios productos. La consulta de
        // "cualquier cliente" (P7) es del personal administrativo.
        $this->fixtureCustomer();
        $this->actAs(Role::Customer);

        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)->assertStatus(403);
    }

    #[Test]
    public function the_admin_can_read_but_does_not_see_declared_income(): void
    {
        // RS-05: los ingresos declarados quedan restringidos al analista de
        // riesgos. El admin lee la vista de P7 sin ese campo.
        $this->fixtureCustomer();
        $this->actAs(Role::Admin);

        $response = $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER);

        $response->assertOk()
            ->assertJsonPath('data.customer_number', self::CUSTOMER_NUMBER)
            ->assertJsonPath('data.authorized_amount', '50000.00')
            ->assertJsonMissingPath('data.declared_income');
    }

    #[Test]
    public function the_auditor_reads_in_read_only_and_without_declared_income(): void
    {
        // El auditor tampoco ve el ingreso declarado. Solo lectura por rol
        // (Role::isReadOnly() lo deriva de sus permisos), y el filtrado por
        // campo lo hace el controlador.
        $this->fixtureCustomer();
        $auditor = $this->actAs(Role::Auditor);

        $this->assertTrue($auditor->role->isReadOnly());
        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)
            ->assertOk()
            ->assertJsonMissingPath('data.declared_income');
    }

    #[Test]
    public function only_the_risk_analyst_sees_the_declared_income(): void
    {
        // La restriccion de la Fase 2 §P7 aplicada al pie de la letra: solo
        // el analista de riesgos tiene ViewAnyDeclaredIncome, y por eso es
        // el unico que ve `declared_income`.
        $this->fixtureCustomer();
        $this->actAs(Role::RiskAnalyst);

        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)
            ->assertOk()
            ->assertJsonPath('data.declared_income', '22500.00');
    }

    #[Test]
    public function a_missing_customer_returns_404_and_leaves_an_authorization_denied_event(): void
    {
        // El use case emite auth.authorization_denied con
        // reason=customer_not_found: es lo que sostiene RS-06.a y ademas
        // hace visible el barrido por numero (R-01).
        $this->actAs(Role::Admin);

        $this->getJson('/api/v1/customers/CU-20260822-ZZZZZZZZ')->assertStatus(404);

        $this->assertSame(
            1,
            AuditLogRecord::query()
                ->where('event_type', 'auth.authorization_denied')
                ->where('metadata->reason', 'customer_not_found')
                ->count(),
        );
    }

    #[Test]
    public function the_lookup_is_itself_an_audited_event(): void
    {
        // RS-06.a: el acceso a informacion personal deja rastro con actor
        // y numero. Sin este control, la bitacora no podria decir quien
        // consulto a quien y cuando.
        $this->fixtureCustomer();
        $this->actAs(Role::Admin);

        $this->getJson('/api/v1/customers/'.self::CUSTOMER_NUMBER)->assertOk();

        $this->assertSame(
            1,
            AuditLogRecord::query()
                ->where('event_type', 'customer.looked_up')
                ->where('metadata->customer_number', self::CUSTOMER_NUMBER)
                ->count(),
        );
    }
}
