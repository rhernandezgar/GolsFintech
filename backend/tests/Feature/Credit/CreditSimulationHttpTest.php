<?php

declare(strict_types=1);

namespace Tests\Feature\Credit;

use App\Domain\Access\Role;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\VerificationStatus;
use App\Infrastructure\Persistence\Eloquent\IdentityValidationRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Infrastructure\Security\ProspectSessionIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5: endpoint HTTP de simulacion.
 *
 * La lista de invariantes que sostiene:
 *
 * - 401 sin token.
 * - 422 con plazo fuera del catalogo del dominio (VUL-02 en la capa HTTP).
 * - 422 sin identidad verificada previa: un `deferred` por caida del proveedor
 *   NO simula (distinto de rechazado, tampoco verificado; consultar el motor
 *   sobre datos sin RENAPO daria una oferta que P6 no podria autorizar).
 * - 201 con oferta que lleva `simulation_public_id` y `expires_at`: es lo que
 *   P6 necesita para comprobar caducidad y aceptar.
 */
final class CreditSimulationHttpTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedProspect(): ProspectRecord
    {
        return ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Ana Perez Lopez',
            'curp' => 'HEGG560427MVZRRL04',
            'curp_hash' => hash('sha256', 'HEGG560427MVZRRL04'),
            'age' => 34,
            'sex' => 'M',
            'monthly_income' => 20000.00,
            'capture_method' => 'manual',
            'capture_status' => 'data_confirmed',
        ]);
    }

    private function verifiedIdentityFor(ProspectRecord $prospect): IdentityValidationRecord
    {
        return IdentityValidationRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'verification_folio' => 'VF-'.Str::upper(Str::random(8)),
            'ine_status' => VerificationStatus::Verified->value,
            'renapo_status' => VerificationStatus::Verified->value,
            'data_match_status' => VerificationStatus::Verified->value,
            'document_validity_status' => VerificationStatus::Verified->value,
            'fraud_evaluation_status' => 'passed',
            'overall_status' => OverallValidationStatus::Verified->value,
            'attempts' => 1,
            'validated_at' => now(),
        ]);
    }

    private function deferredIdentityFor(ProspectRecord $prospect): IdentityValidationRecord
    {
        return IdentityValidationRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'verification_folio' => 'VF-'.Str::upper(Str::random(8)),
            'ine_status' => VerificationStatus::Unavailable->value,
            'renapo_status' => VerificationStatus::Unavailable->value,
            'data_match_status' => VerificationStatus::Pending->value,
            'document_validity_status' => VerificationStatus::Pending->value,
            'fraud_evaluation_status' => 'pending',
            'overall_status' => OverallValidationStatus::Pending->value,
            'attempts' => 1,
            'validated_at' => now(),
        ]);
    }

    private function tokenFor(ProspectRecord $prospect): User
    {
        $user = User::factory()->role(Role::Prospect)->forProspect($prospect->id)->create();
        Passport::actingAs($user, [ProspectSessionIssuer::PROSPECT_SCOPE]);

        return $user;
    }

    #[Test]
    public function without_a_token_the_endpoint_answers_401(): void
    {
        $this->postJson('/api/v1/credit-simulations', ['term_months' => 12])->assertStatus(401);
    }

    #[Test]
    public function a_term_outside_the_catalogue_is_rejected_by_the_form_request(): void
    {
        $prospect = $this->confirmedProspect();
        $this->verifiedIdentityFor($prospect);
        $this->tokenFor($prospect);

        // 9 no esta en [6, 12, 18, 24, 36]. Corta el FormRequest antes de
        // tocar el motor.
        $this->postJson('/api/v1/credit-simulations', ['term_months' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('term_months');
    }

    #[Test]
    public function without_verified_identity_the_simulation_is_refused(): void
    {
        // Deferred no es verificado. Simular aqui sobre RENAPO no confirmado
        // daria una oferta que P6 no podria autorizar despues.
        $prospect = $this->confirmedProspect();
        $this->deferredIdentityFor($prospect);
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/credit-simulations', ['term_months' => 12])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'IDENTITY_NOT_VERIFIED');
    }

    #[Test]
    public function without_any_identity_validation_the_simulation_is_refused(): void
    {
        $prospect = $this->confirmedProspect();
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/credit-simulations', ['term_months' => 12])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'IDENTITY_NOT_VERIFIED');
    }

    #[Test]
    public function a_verified_prospect_gets_an_offer_with_uuid_and_expiry(): void
    {
        $prospect = $this->confirmedProspect();
        $this->verifiedIdentityFor($prospect);
        $this->tokenFor($prospect);

        $response = $this->postJson('/api/v1/credit-simulations', ['term_months' => 12]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'simulation_public_id',
                'simulation_folio',
                'credit_type',
                'term_months',
                'proposed_amount',
                'estimated_monthly_payment',
                'annual_rate_percentage',
                'expires_at',
                'currency',
            ]]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.simulation_public_id'));
        $this->assertSame(12, $response->json('data.term_months'));
        $this->assertSame(1, DB::table('credit_simulations')->count());
        $this->assertSame(1, DB::table('credit_applications')->count());
    }

    #[Test]
    public function without_confirmed_data_the_simulation_is_refused(): void
    {
        // El prospecto no ha confirmado sus datos en P2. El use case exige
        // hasConfirmedData() y lanza InvalidStateTransition, que el
        // controlador traduce a 422 con codigo estable.
        $prospect = ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_captured',
        ]);
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/credit-simulations', ['term_months' => 12])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATE_TRANSITION');
    }
}
