<?php

declare(strict_types=1);

namespace Tests\Feature\Prospect;

use App\Domain\Identity\OcrStatus;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /prospects/me` devuelve lo que el OCR extrajo, para que P2 pueda
 * presentarlo a confirmacion humana (Fase 2 §P3, Fase 3).
 *
 * Las dos propiedades que se sostienen a la vez:
 *
 *   - el prospecto ve lo suficiente para revisar y corregir;
 *   - la respuesta NO transporta la CURP ni el RFC completos (RS-03).
 *
 * La segunda se comprueba recorriendo el cuerpo entero en busca del valor
 * literal, no mirando solo el campo que se espera enmascarado: si el dato se
 * cuela por otra clave —una que el proveedor nombro de otro modo, un volcado
 * del payload crudo—, la prueba tiene que fallar igual.
 */
final class ProspectExtractedDataTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CAPTCHA = 'captcha-ok';

    private const EXTRACTED_CURP = 'HEGG560427MVZRRL04';

    private const EXTRACTED_RFC = 'HEGG560427AB1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    private function newSessionToken(string $captureMethod = 'ocr'): string
    {
        return (string) $this->postJson('/api/v1/prospects', [
            'capture_method' => $captureMethod,
            'privacy_notice_accepted' => true,
            'captcha_token' => self::VALID_CAPTCHA,
        ])->assertCreated()->json('data.session.access_token');
    }

    private function showWith(string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/prospects/me');
    }

    /** @param array<string, mixed>|null $ocrResult */
    private function documentFor(
        string $token,
        ?array $ocrResult,
        string $status = 'completed',
    ): IdentityDocumentRecord {
        $prospectId = ProspectRecord::query()->latest('id')->value('id');

        return IdentityDocumentRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'prospect_id' => $prospectId,
            'document_type' => 'INE',
            'storage_path' => 'private/documents/'.Str::uuid().'.png',
            'detected_mime_type' => 'image/png',
            'file_size_bytes' => 1024,
            'file_hash' => hash('sha256', 'contenido de prueba'),
            'ocr_status' => $status,
            'ocr_result' => $ocrResult,
            'ocr_attempts' => 1,
            'ocr_job_id' => 'ocrsim-extracted-'.substr(hash('sha256', (string) Str::uuid()), 0, 16),
            'processed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function ocrResult(): array
    {
        return [
            'provider' => 'fake',
            'confidence' => 0.97,
            'fields' => [
                'full_name' => 'GABRIELA HERNANDEZ GARCIA',
                'birth_date' => '1956-04-27',
                'curp' => self::EXTRACTED_CURP,
                'rfc' => self::EXTRACTED_RFC,
                'document_number' => 'A1B2C3D4E5F6',
            ],
        ];
    }

    // ================================================= lo que sí viaja =======

    public function test_the_extracted_fields_travel_so_the_prospect_can_review_them(): void
    {
        $token = $this->newSessionToken();
        $document = $this->documentFor($token, $this->ocrResult());

        $response = $this->showWith($token)->assertOk();

        $response->assertJsonPath('data.ocr_extraction.fields.full_name.value', 'GABRIELA HERNANDEZ GARCIA');
        $response->assertJsonPath('data.ocr_extraction.fields.full_name.masked', false);
        $response->assertJsonPath('data.ocr_extraction.fields.birth_date.value', '1956-04-27');
        $response->assertJsonPath('data.ocr_extraction.legibility', 'high');
        $response->assertJsonPath('data.ocr_extraction.needs_careful_review', false);
        $response->assertJsonPath('data.ocr_extraction.document_tracking_id', $document->public_id);
    }

    public function test_a_low_confidence_extraction_asks_for_a_careful_review(): void
    {
        $token = $this->newSessionToken();
        $result = $this->ocrResult();
        $result['confidence'] = 0.42;
        $this->documentFor($token, $result);

        $this->showWith($token)->assertOk()
            ->assertJsonPath('data.ocr_extraction.legibility', 'low')
            // El umbral lo fija el dominio; la vista solo lo refleja (RS-04).
            ->assertJsonPath('data.ocr_extraction.needs_careful_review', true);
    }

    // ============================================== lo que NO viaja ==========

    public function test_the_response_never_carries_the_complete_curp_or_rfc(): void
    {
        $token = $this->newSessionToken();
        $this->documentFor($token, $this->ocrResult());

        $response = $this->showWith($token)->assertOk();
        $body = $response->getContent();

        // Recorrido del cuerpo ENTERO: si el dato se cuela por otra clave, esto
        // falla igual que si se colara por la esperada.
        $this->assertStringNotContainsString(self::EXTRACTED_CURP, $body);
        $this->assertStringNotContainsString(self::EXTRACTED_RFC, $body);

        // Y aun asi es reconocible, que es lo que hace util la revision.
        $response->assertJsonPath('data.ocr_extraction.fields.curp.value', 'HEGG************04');
        $response->assertJsonPath('data.ocr_extraction.fields.curp.masked', true);
        $response->assertJsonPath('data.ocr_extraction.fields.rfc.masked', true);
    }

    public function test_the_document_number_travels_only_by_its_tail(): void
    {
        $token = $this->newSessionToken();
        $this->documentFor($token, $this->ocrResult());

        $response = $this->showWith($token)->assertOk();

        $this->assertStringNotContainsString('A1B2C3D4E5F6', $response->getContent());
        $response->assertJsonPath('data.ocr_extraction.fields.document_number.value', '********E5F6');
    }

    public function test_the_captured_file_data_still_does_not_travel(): void
    {
        // Lo que se anade es la EXTRACCION pendiente de confirmar, no el
        // expediente ya capturado: para saber por que paso va el tramite sigue
        // sin hacer falta ningun dato personal.
        $token = $this->newSessionToken();
        ProspectRecord::query()->latest('id')->first()->update([
            'full_name' => 'Ana Perez Lopez',
            'curp' => 'XEXX010101HNEXXXA4',
        ]);

        $response = $this->showWith($token)->assertOk();

        $this->assertStringNotContainsString('XEXX010101HNEXXXA4', $response->getContent());
        $response->assertJsonPath('data.has_data', true);
        $response->assertJsonMissingPath('data.ocr_extraction');
    }

    // ============================================ cuando no hay nada =========

    public function test_without_a_document_there_is_no_extraction_block(): void
    {
        $token = $this->newSessionToken('manual');

        $this->showWith($token)->assertOk()->assertJsonMissingPath('data.ocr_extraction');
    }

    public function test_a_document_still_processing_shows_no_extraction(): void
    {
        // Un documento en proceso no tiene resultado que revisar. Mostrar un
        // bloque vacio sugeriria que el OCR ya termino.
        $token = $this->newSessionToken();
        $this->documentFor($token, null, OcrStatus::Processing->value);

        $this->showWith($token)->assertOk()->assertJsonMissingPath('data.ocr_extraction');
    }

    public function test_a_failed_extraction_shows_nothing_to_review(): void
    {
        $token = $this->newSessionToken();
        $this->documentFor($token, null, OcrStatus::Failed->value);

        $this->showWith($token)->assertOk()->assertJsonMissingPath('data.ocr_extraction');
    }

    // ================================================== aislamiento ==========

    public function test_a_prospect_never_sees_the_extraction_of_another(): void
    {
        $firstToken = $this->newSessionToken();
        $this->documentFor($firstToken, $this->ocrResult());

        // Segundo expediente, sin documento propio. Su respuesta no puede
        // arrastrar la extraccion del primero: el prospecto sale del token.
        $secondToken = $this->newSessionToken();

        $response = $this->showWith($secondToken)->assertOk();

        $response->assertJsonMissingPath('data.ocr_extraction');
        $this->assertStringNotContainsString('GABRIELA HERNANDEZ GARCIA', $response->getContent());
    }
}
