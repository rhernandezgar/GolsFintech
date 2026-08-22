<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P3 — carga de la identificacion oficial.
 *
 * Lo que estas pruebas fijan es el contrato asincrono de la Figura 2a: la API
 * **acepta** el trabajo y responde 202 con un identificador de seguimiento, sin
 * esperar a que el OCR termine. Si alguien convirtiera esto en sincrono para
 * "simplificar", la primera prueba se pone roja.
 */
final class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function prospect(string $name = 'Ana Perez Lopez'): ProspectRecord
    {
        return ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => $name,
            'capture_method' => 'ocr',
            'capture_status' => 'started',
            'monthly_income' => 25000.00,
        ]);
    }

    private function prospectUser(?ProspectRecord $prospect = null): User
    {
        $prospect ??= $this->prospect();

        return User::factory()->role(Role::Prospect)->forProspect($prospect->id)->create();
    }

    /** Un JPEG de verdad: la cabecera importa, porque el almacen mira el contenido. */
    private function jpeg(string $name = 'ine.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 400);
    }

    // ============================================== El contrato del 202 ======

    #[Test]
    public function the_upload_answers_202_accepted_with_a_tracking_id(): void
    {
        Passport::actingAs($this->prospectUser());

        $response = $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ]);

        $response->assertAccepted();
        $response->assertJsonStructure(['data' => ['tracking_id', 'ocr_status', 'attempt', 'status_url']]);

        // 'processing', no 'completed': la respuesta sale ANTES de que el OCR
        // haya hecho nada. Es la diferencia entre encolar y procesar.
        $this->assertSame('processing', $response->json('data.ocr_status'));
        $this->assertSame(1, $response->json('data.attempt'));

        // El identificador de seguimiento sirve de verdad para seguir.
        $this->assertNotNull(
            IdentityDocumentRecord::query()->where('public_id', $response->json('data.tracking_id'))->first()
        );
    }

    #[Test]
    public function the_response_never_carries_the_extracted_data(): void
    {
        Passport::actingAs($this->prospectUser());

        $response = $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->assertStatus(202);

        // Devolver campos extraidos en el 202 seria mentir: todavia no existen.
        $this->assertArrayNotHasKey('ocr_result', $response->json('data'));
        $this->assertArrayNotHasKey('curp', $response->json('data'));
    }

    #[Test]
    public function the_upload_leaves_an_audit_trail_with_the_job_queued(): void
    {
        Passport::actingAs($this->prospectUser());

        $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->assertStatus(202);

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.uploaded']);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.ocr_queued']);
    }

    // ================================================ Controles de VUL-01 ====

    #[Test]
    public function an_executable_renamed_as_an_image_is_rejected(): void
    {
        Passport::actingAs($this->prospectUser());

        // Extension y Content-Type dicen "imagen"; el contenido dice otra cosa.
        // Los dos primeros los elige quien sube el archivo: no son evidencia.
        $disguised = UploadedFile::fake()->createWithContent('ine.jpg', "\x7fELF\x02\x01\x01\x00binario");

        $this->post('/api/v1/identity-documents', [
            'document' => $disguised,
            'document_type' => 'INE',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('identity_documents', 0);
    }

    #[Test]
    public function a_file_over_the_size_limit_is_rejected(): void
    {
        Passport::actingAs($this->prospectUser());

        $this->post('/api/v1/identity-documents', [
            // 6 MB contra el limite de 5 MB de la Fase 2 §P3.
            'document' => UploadedFile::fake()->create('ine.jpg', 6144, 'image/jpeg'),
            'document_type' => 'INE',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('identity_documents', 0);
    }

    #[Test]
    public function an_unknown_document_type_is_rejected_on_the_server(): void
    {
        // Regla 4: la validacion del cliente se replica en el servidor. Que la
        // pantalla ofrezca un desplegable cerrado no impide mandar otra cosa.
        Passport::actingAs($this->prospectUser());

        $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'licencia-inventada',
        ])->assertStatus(422);
    }

    // ======================================================= Acceso =========

    #[Test]
    public function without_a_token_the_upload_answers_401(): void
    {
        $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_read_only_role_cannot_upload(): void
    {
        // El auditor esta autenticado y su rol es legitimo, pero es de solo
        // lectura: subir un documento es escribir (Fase 3 §4.9).
        Passport::actingAs(User::factory()->role(Role::Auditor)->withTwoFactor()->create());

        $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->assertStatus(403);

        $this->assertDatabaseCount('identity_documents', 0);
    }

    // ============================================ Seguimiento por objeto ====

    #[Test]
    public function the_tracking_id_answers_with_the_current_state(): void
    {
        Passport::actingAs($this->prospectUser());

        $trackingId = $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->json('data.tracking_id');

        $this->getJson('/api/v1/identity-documents/'.$trackingId)
            ->assertOk()
            ->assertJsonPath('data.tracking_id', $trackingId)
            ->assertJsonPath('data.ocr_status', 'processing');
    }

    #[Test]
    public function a_prospect_cannot_follow_the_document_of_another_prospect(): void
    {
        // Autorizacion por objeto (CWE-639). El rol es el correcto y el token es
        // valido: lo que no es suyo es el registro.
        Passport::actingAs($this->prospectUser());

        $trackingId = $this->postJson('/api/v1/identity-documents', [
            'document' => $this->jpeg(),
            'document_type' => 'INE',
        ])->json('data.tracking_id');

        Passport::actingAs($this->prospectUser($this->prospect('Otro Prospecto Distinto')));

        // 404 y no 403 a proposito: un 403 le confirmaria al que prueba
        // identificadores que ese documento existe.
        $this->getJson('/api/v1/identity-documents/'.$trackingId)->assertStatus(404);
    }
}
