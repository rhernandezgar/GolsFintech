<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\DTO\DocumentUploadInput;
use App\Application\UseCase\Identity\UploadIdentityDocument;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\Curp;
use App\Domain\Identity\OcrStatus;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeOcrService;
use Tests\Support\Doubles\InMemoryAuditLogger;
use Tests\Support\Doubles\InMemoryDocumentRepository;
use Tests\Support\Doubles\InMemoryProspectRepository;

/**
 * Un caso de uso completo corriendo SOLO contra dobles: ni base de datos, ni
 * framework, ni proveedor externo. Es la prueba de que los puertos sirven para lo
 * que existen —si el caso de uso conociera a Eloquent o al proveedor de OCR, esta
 * prueba no podria escribirse—.
 */
final class UploadIdentityDocumentTest extends TestCase
{
    private InMemoryProspectRepository $prospects;

    private InMemoryDocumentRepository $documents;

    private InMemoryAuditLogger $audit;

    private FakeOcrService $ocr;

    protected function setUp(): void
    {
        $this->prospects = new InMemoryProspectRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->audit = new InMemoryAuditLogger();
        $this->ocr = new FakeOcrService();
    }

    private function useCase(): UploadIdentityDocument
    {
        return new UploadIdentityDocument($this->prospects, $this->documents, $this->ocr, $this->audit);
    }

    private function storedProspect(): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Ocr, new DateTimeImmutable('2026-08-21 09:00:00'));
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString('HEGG560427MVZRRL04'),
            rfc: null,
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
        );

        return $this->prospects->save($prospect);
    }

    private function input(Prospect $prospect, string $storagePath = 'documents/2026/ine-frente.jpg'): DocumentUploadInput
    {
        return new DocumentUploadInput(
            prospectPublicId: $prospect->publicId()->value,
            documentType: 'INE',
            storagePath: $storagePath,
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 250_000,
            fileHash: hash('sha256', 'contenido-del-archivo'),
            originalExtension: 'jpg',
        );
    }

    public function test_the_document_is_stored_queued_and_recorded_in_the_audit_log(): void
    {
        $prospect = $this->storedProspect();

        $document = $this->useCase()->execute(
            $this->input($prospect),
            new AuditContext('prospect:anonymous', '203.0.113.10'),
            new DateTimeImmutable('2026-08-21 09:05:00'),
        );

        $this->assertSame(1, $this->documents->count());
        $this->assertSame(OcrStatus::Processing, $document->ocrStatus());
        $this->assertSame(1, $document->ocrAttempts());
        $this->assertSame(1, $this->ocr->enqueuedCount());
        $this->assertSame(
            ['document.uploaded', 'document.ocr_queued'],
            $this->audit->eventTypes()
        );
    }

    public function test_the_job_id_carries_the_scenario_the_worker_must_simulate(): void
    {
        $prospect = $this->storedProspect();
        $this->ocr->willTimeOut();

        $document = $this->useCase()->execute(
            $this->input($prospect),
            new AuditContext('prospect:anonymous'),
            new DateTimeImmutable('2026-08-21 09:05:00'),
        );

        $this->assertStringStartsWith('ocrsim-timeout-', (string) $document->ocrJobId());
        $this->assertTrue($document->canRetryExtraction());
    }

    public function test_a_queue_failure_does_not_leave_the_document_marked_as_processing(): void
    {
        // La cola caida es un fallo del adaptador, no del prospecto: el documento
        // queda guardado y pendiente, listo para reintentar, y no en un estado que
        // afirme que se esta procesando algo que nadie recibio.
        $prospect = $this->storedProspect();
        $this->ocr->willFailToEnqueue();

        try {
            $this->useCase()->execute(
                $this->input($prospect),
                new AuditContext('prospect:anonymous'),
                new DateTimeImmutable('2026-08-21 09:05:00'),
            );
            $this->fail('El fallo de la cola debio propagarse como ExternalServiceUnavailableException.');
        } catch (ExternalServiceUnavailableException $exception) {
            $this->assertSame('EXTERNAL_SERVICE_UNAVAILABLE', $exception->errorCode());
            // El mensaje al usuario no nombra el servicio ni el motivo tecnico.
            $this->assertStringNotContainsString('ocr', strtolower($exception->userMessage()));
        }

        $stored = $this->documents->findByProspectId((int) $prospect->id());

        $this->assertCount(1, $stored);
        $this->assertSame(OcrStatus::Pending, $stored[0]->ocrStatus());
        $this->assertSame(0, $stored[0]->ocrAttempts());
    }

    public function test_the_audit_log_of_the_upload_never_carries_the_curp(): void
    {
        $prospect = $this->storedProspect();

        $this->useCase()->execute(
            $this->input($prospect),
            new AuditContext('prospect:anonymous'),
            new DateTimeImmutable('2026-08-21 09:05:00'),
        );

        $serialized = json_encode(
            array_map(static fn ($event): array => $event->metadata, $this->audit->events()),
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString('HEGG560427MVZRRL04', $serialized);
    }
}
