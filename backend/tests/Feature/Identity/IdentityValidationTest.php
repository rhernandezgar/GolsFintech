<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
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
 * P4: endpoint de validacion de identidad.
 *
 * Las cuatro precisiones del disenio se acreditan aqui:
 *
 * - Expediente confirmado como precondicion: sin `data_confirmed`, 422 con
 *   codigo `PROSPECT_DATA_NOT_CONFIRMED`.
 * - Rechazo generico al cliente: la respuesta no dice INE ni RENAPO ni cual
 *   de los cuatro checks fallo. Solo el status agregado y el folio.
 * - Asimetria `_requested` -> `_succeeded|_rejected|_deferred`: la solicitud
 *   deja rastro ANTES del resultado, para que un fallo del proveedor no sea
 *   un hueco silencioso (R-01).
 * - Indisponibilidad como `deferred`, nunca `rejected`: el proveedor caido
 *   no niega credito a alguien con identidad valida (R-03).
 */
final class IdentityValidationTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedProspect(
        string $curp = 'HEGG560427MVZRRL04',
        string $name = 'Ana Perez Lopez',
    ): ProspectRecord {
        return ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => $name,
            'curp' => $curp,
            'curp_hash' => hash('sha256', $curp),
            'age' => 34,
            'sex' => 'M',
            'monthly_income' => 20000.00,
            'capture_method' => 'manual',
            'capture_status' => 'data_confirmed',
        ]);
    }

    private function unconfirmedProspect(): ProspectRecord
    {
        return ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_captured',
        ]);
    }

    private function documentFor(ProspectRecord $prospect): IdentityDocumentRecord
    {
        return IdentityDocumentRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'document_type' => 'INE',
            'storage_path' => 'documents/2026/ine.jpg',
            'original_extension' => 'jpg',
            'detected_mime_type' => 'image/jpeg',
            'file_size_bytes' => 250_000,
            'file_hash' => hash('sha256', 'ine-content'),
            'ocr_status' => 'completed',
        ]);
    }

    private function tokenFor(ProspectRecord $prospect): User
    {
        $user = User::factory()->role(Role::Prospect)->forProspect($prospect->id)->create();
        Passport::actingAs($user, [ProspectSessionIssuer::PROSPECT_SCOPE]);

        return $user;
    }

    // =========================================================== 401 =========

    #[Test]
    public function without_a_token_the_endpoint_answers_401(): void
    {
        $this->postJson('/api/v1/identity-validations')->assertStatus(401);
    }

    // =========================================================== 200 =========

    #[Test]
    public function a_verified_identity_returns_status_verified_with_folio(): void
    {
        $prospect = $this->confirmedProspect();
        $document = $this->documentFor($prospect);
        $this->tokenFor($prospect);

        $response = $this->postJson('/api/v1/identity-validations', [
            'document_public_id' => $document->public_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonStructure(['data' => ['status', 'verification_folio', 'message']]);
    }

    #[Test]
    public function a_rejected_identity_does_not_reveal_which_check_failed(): void
    {
        // Riesgo R-01: al cliente solo `status: not_verified` y el folio. Ni
        // INE, ni RENAPO, ni cual de los cuatro checks fue el que fallo. El
        // detalle si va a la bitacora (metadata del evento).
        $prospect = $this->confirmedProspect('XEXX010101HNEXXXA4');
        $document = $this->documentFor($prospect);
        $this->tokenFor($prospect);

        $response = $this->postJson('/api/v1/identity-validations', [
            'document_public_id' => $document->public_id,
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'not_verified');

        $body = (string) $response->getContent();
        foreach (['ine_status', 'renapo_status', 'data_match_status', 'document_validity_status',
            'fraud_flagged', 'INE', 'RENAPO', 'ine', 'renapo'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                sprintf('La respuesta de identidad rechazada nombra "%s".', $forbidden)
            );
        }
    }

    #[Test]
    public function an_unavailable_provider_answers_pending_not_rejected(): void
    {
        // Riesgo R-03: el proveedor no respondio "verificado" ni "no
        // verificado"; el estado es "en proceso" y se puede reintentar.
        // Rechazar aqui negaria credito a alguien con identidad valida.
        $prospect = $this->confirmedProspect('XEXX020202MNEXXXA1');
        $this->tokenFor($prospect);

        $response = $this->postJson('/api/v1/identity-validations');

        $response->assertOk()->assertJsonPath('data.status', 'pending');
    }

    // =========================================================== 422 =========

    #[Test]
    public function without_confirmed_data_the_request_is_refused_with_a_stable_code(): void
    {
        $prospect = $this->unconfirmedProspect();
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/identity-validations')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PROSPECT_DATA_NOT_CONFIRMED');
    }

    // =========================================================== 403 =========

    #[Test]
    public function a_document_of_another_prospect_is_refused_with_a_generic_403(): void
    {
        $mine = $this->confirmedProspect(name: 'Ana Perez Lopez');
        $strangerDocument = $this->documentFor($this->confirmedProspect('XEXX030303HNEXXXA8', 'Luis Ramirez Soto'));
        $this->tokenFor($mine);

        $response = $this->postJson('/api/v1/identity-validations', [
            'document_public_id' => $strangerDocument->public_id,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'DOCUMENT_NOT_OWNED')
            // El mismo mensaje que emiten las politicas: no revela si el
            // documento existe.
            ->assertJsonPath('message', 'No tiene acceso a este recurso.');
    }

    // ====================================== asimetria de eventos =============

    #[Test]
    public function the_requested_event_is_emitted_before_the_provider_is_called(): void
    {
        $prospect = $this->confirmedProspect();
        $document = $this->documentFor($prospect);
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/identity-validations', [
            'document_public_id' => $document->public_id,
        ])->assertOk();

        // Orden estricto: `_requested` va primero, `_succeeded` (o rejected /
        // deferred) despues. Si el proveedor cayera con excepcion, solo
        // quedaria el `_requested` -y ese es exactamente el rastro que la
        // asimetria tiene que preservar (R-01)-.
        $events = AuditLogRecord::query()->orderBy('id')->pluck('event_type')->all();

        $this->assertGreaterThanOrEqual(2, count($events));
        $this->assertSame('identity.validation_requested', $events[0]);
        $this->assertSame('identity.validation_succeeded', $events[1]);
    }

    #[Test]
    public function the_deferred_case_also_leaves_the_two_events_in_order(): void
    {
        $prospect = $this->confirmedProspect('XEXX020202MNEXXXA1');
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/identity-validations')->assertOk();

        $events = AuditLogRecord::query()->orderBy('id')->pluck('event_type')->all();
        $this->assertSame('identity.validation_requested', $events[0]);
        $this->assertSame('identity.validation_deferred', $events[1]);
    }

    #[Test]
    public function a_refused_request_leaves_no_provider_call_in_the_log(): void
    {
        // Precondicion fallida: `_requested` no debe aparecer, porque el
        // proveedor no se llamo. Sin este control, un pico de datos
        // incompletos contaminaria la senal que sostiene R-01.
        $prospect = $this->unconfirmedProspect();
        $this->tokenFor($prospect);

        $this->postJson('/api/v1/identity-validations')->assertStatus(422);

        $this->assertSame(
            0,
            DB::table('audit_logs')->where('event_type', 'identity.validation_requested')->count(),
        );
    }
}
