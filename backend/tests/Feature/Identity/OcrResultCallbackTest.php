<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Access\Role;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El otro extremo del 202: lo que el worker devuelve cuando termina.
 *
 * Aqui se comprueban las tres propiedades que hacen que el ciclo asincrono sea
 * seguro y no solo funcional: quien puede llamar, que un resultado rezagado no
 * pisa el estado vigente, y —PT-03— que agotar los reintentos no le cuesta al
 * prospecto volver a empezar.
 */
final class OcrResultCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const JOB_REF = 'ocrsim-extracted-0123456789abcdef';

    private function documentInProcess(string $jobRef = self::JOB_REF): IdentityDocumentRecord
    {
        $prospect = ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'ocr',
            'capture_status' => 'document_uploaded',
            'monthly_income' => 25000.00,
        ]);

        return IdentityDocumentRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospect->id,
            'document_type' => 'INE',
            'storage_path' => 'identity-documents/'.$prospect->public_id.'/'.Str::uuid().'.jpg',
            'original_extension' => 'jpg',
            'detected_mime_type' => 'image/jpeg',
            'file_size_bytes' => 102400,
            'file_hash' => hash('sha256', 'contenido'),
            'ocr_status' => 'processing',
            'ocr_attempts' => 1,
            'ocr_job_id' => $jobRef,
        ]);
    }

    /** El worker se autentica como cliente, no como persona. */
    private function actingAsWorker(): void
    {
        Passport::actingAsClient(Passport::client()->forceFill([
            'name' => 'GolsFintech worker',
        ]), ['ocr-result']);
    }

    private function payload(IdentityDocumentRecord $document, array $overrides = []): array
    {
        return array_merge([
            'document_public_id' => $document->public_id,
            'job_ref' => $document->ocr_job_id,
            'status' => 'extracted',
            'attempt' => 1,
            'result' => ['provider' => 'fake', 'confidence' => 0.97, 'fields' => ['full_name' => 'ANA PEREZ LOPEZ']],
        ], $overrides);
    }

    // ======================================================= Acceso =========

    #[Test]
    public function without_a_token_the_callback_answers_401(): void
    {
        $document = $this->documentInProcess();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))->assertStatus(401);

        $this->assertSame('processing', $document->fresh()->ocr_status);
    }

    #[Test]
    public function a_user_token_cannot_report_results(): void
    {
        // La ruta es para un proceso. Un usuario autenticado —aunque sea
        // admin— no es el dueno del recurso que exige el middleware `client`:
        // si bastara un token de persona, cualquiera con sesion podria
        // inyectar el resultado de un OCR que nunca se ejecuto.
        $document = $this->documentInProcess();

        Passport::actingAs(User::factory()->role(Role::Admin)->withTwoFactor()->create());

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))->assertStatus(401);

        $this->assertSame('processing', $document->fresh()->ocr_status);
    }

    #[Test]
    public function a_client_without_the_scope_cannot_report_results(): void
    {
        $document = $this->documentInProcess();

        Passport::actingAsClient(Passport::client()->forceFill(['name' => 'Otro cliente']), ['otro-scope']);

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))->assertStatus(403);
    }

    // ================================================ Aplicar el resultado ==

    #[Test]
    public function the_worker_completes_the_extraction(): void
    {
        $document = $this->documentInProcess();
        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))
            ->assertOk()
            ->assertJsonPath('data.ocr_status', 'completed');

        $fresh = $document->fresh();
        $this->assertSame('completed', $fresh->ocr_status);
        $this->assertSame('ANA PEREZ LOPEZ', $fresh->ocr_result['fields']['full_name']);
        $this->assertNotNull($fresh->processed_at);

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.ocr_completed']);
    }

    #[Test]
    public function a_result_from_another_attempt_is_not_applied(): void
    {
        // Correlacion. Un resultado que llega tarde, de un intento anterior, no
        // puede pisar el estado del intento vigente.
        $document = $this->documentInProcess('ocrsim-extracted-1111111111111111');
        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document, [
            'job_ref' => 'ocrsim-extracted-9999999999999999',
        ]))->assertStatus(409);

        $this->assertSame('processing', $document->fresh()->ocr_status);
    }

    #[Test]
    public function repeating_the_same_result_changes_nothing(): void
    {
        // El worker puede reintentar el aviso: una respuesta perdida, un 500
        // pasajero. Repetirlo no debe duplicar eventos en la bitacora.
        $document = $this->documentInProcess();
        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))->assertOk();
        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document))->assertOk();

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event_type', 'document.ocr_completed')->count(),
        );
    }

    #[Test]
    public function an_unknown_document_is_rejected_without_saying_why(): void
    {
        $this->actingAsWorker();

        $response = $this->postJson('/api/v1/internal/ocr-results', [
            'document_public_id' => (string) Str::uuid(),
            'job_ref' => self::JOB_REF,
            'status' => 'extracted',
            'attempt' => 1,
        ])->assertStatus(409);

        // Regla 8: nada de tablas, columnas ni trazas en la respuesta.
        $this->assertSame('El resultado no se pudo aplicar.', $response->json('message'));
    }

    #[Test]
    public function the_reason_is_a_closed_code_and_not_free_text(): void
    {
        // Lo que manda el worker acaba en la bitacora. Ahi no se vuelca la
        // respuesta cruda de un tercero.
        $document = $this->documentInProcess();
        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document, [
            'status' => 'failed',
            'reason' => '<script>alert(1)</script>',
        ]))->assertStatus(422);
    }

    // ========================================================= PT-03 ========

    #[Test]
    public function exhausting_the_retries_does_not_lose_the_prospect_data(): void
    {
        // Criterio de la prueba PT-03 de la Fase 1. Fallar no puede costarle al
        // prospecto volver a empezar: el documento queda en un estado
        // recuperable, con su archivo y sus datos intactos.
        $document = $this->documentInProcess();
        $prospectId = $document->prospect_id;
        $storagePath = $document->storage_path;
        $fileHash = $document->file_hash;

        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document, [
            'status' => 'failed',
            'attempt' => 3,
            'reason' => 'exhausted',
            'result' => null,
        ]))->assertOk()->assertJsonPath('data.ocr_status', 'failed');

        $fresh = $document->fresh();

        // El estado dice que fallo...
        $this->assertSame('failed', $fresh->ocr_status);

        // ...y nada mas se perdio: ni el archivo, ni su huella, ni el vinculo
        // con el prospecto, ni los datos que el prospecto ya habia dado.
        $this->assertSame($storagePath, $fresh->storage_path);
        $this->assertSame($fileHash, $fresh->file_hash);
        $this->assertSame($prospectId, $fresh->prospect_id);
        $this->assertDatabaseHas('prospects', ['id' => $prospectId, 'full_name' => 'Ana Perez Lopez']);

        // Y queda constancia de que hay algo que recuperar.
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.ocr_failed']);
    }

    #[Test]
    public function an_unreadable_document_fails_on_the_first_attempt(): void
    {
        // El worker no reintenta un documento ilegible: reporta en el intento 1.
        $document = $this->documentInProcess();
        $this->actingAsWorker();

        $this->postJson('/api/v1/internal/ocr-results', $this->payload($document, [
            'status' => 'failed',
            'attempt' => 1,
            'reason' => 'unreadable',
            'result' => null,
        ]))->assertOk();

        $this->assertSame('failed', $document->fresh()->ocr_status);
        $this->assertSame(1, $document->fresh()->ocr_attempts);
    }
}
