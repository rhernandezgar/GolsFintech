<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\Identity\ValidateIdentity;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Identity\Curp;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Doubles\FakeIdentityValidator;
use Tests\Support\Doubles\InMemoryAuditLogger;
use Tests\Support\Doubles\InMemoryDocumentRepository;
use Tests\Support\Doubles\InMemoryIdentityValidationRepository;
use Tests\Support\Doubles\InMemoryProspectRepository;

/**
 * P4: se cuenta lo que la validacion tiene que garantizar, no como lo hace.
 *
 * Cada rama —verificado, rechazado, proveedor caido— produce **su** par de
 * eventos: `identity.validation_requested` primero (para que quede rastro
 * incluso si el proveedor no responde) y despues `_succeeded` o `_rejected`
 * segun el desenlace.
 */
final class ValidateIdentityTest extends TestCase
{
    private InMemoryProspectRepository $prospects;

    private InMemoryDocumentRepository $documents;

    private FakeIdentityValidator $validator;

    private InMemoryIdentityValidationRepository $validations;

    private InMemoryAuditLogger $audit;

    protected function setUp(): void
    {
        $this->prospects = new InMemoryProspectRepository;
        $this->documents = new InMemoryDocumentRepository;
        $this->validator = new FakeIdentityValidator;
        $this->validations = new InMemoryIdentityValidationRepository;
        $this->audit = new InMemoryAuditLogger;
    }

    private function useCase(): ValidateIdentity
    {
        return new ValidateIdentity(
            $this->prospects,
            $this->documents,
            $this->validator,
            $this->validations,
            $this->audit,
        );
    }

    private function context(): AuditContext
    {
        return new AuditContext('user:test', '198.51.100.7');
    }

    private function storedProspect(): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-22 12:00:00'));
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

    private function storedDocument(int $prospectId): IdentityDocument
    {
        return $this->documents->save(IdentityDocument::register(
            prospectId: $prospectId,
            documentType: DocumentType::Ine,
            storagePath: 'documents/2026/ine.jpg',
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 250_000,
            fileHash: hash('sha256', 'ine-content'),
        ));
    }

    public function test_verified_result_persists_and_emits_requested_then_succeeded(): void
    {
        $prospect = $this->storedProspect();
        // Sin documento, FakeIdentityValidator deja documentValidity en Pending
        // y el overall queda Pending —el conjunto no se da por bueno—. Con
        // documento, los cuatro son Verified.
        $document = $this->storedDocument((int) $prospect->id());
        $this->validator->willVerify();

        $recorded = $this->useCase()->execute(
            $prospect->publicId(),
            documentPublicId: $document->publicId(),
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        $this->assertTrue($recorded->result->isVerified());
        $this->assertSame(1, $this->validations->count());
        $this->assertSame(
            [AuditEventType::IdentityValidationRequested->value, AuditEventType::IdentityValidationSucceeded->value],
            $this->audit->eventTypes(),
        );
    }

    public function test_rejected_result_emits_rejected_and_still_persists(): void
    {
        $prospect = $this->storedProspect();
        $this->validator->willReject();

        $recorded = $this->useCase()->execute(
            $prospect->publicId(),
            documentPublicId: null,
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        // El rechazo tambien se guarda: hace falta el rastro para poder mostrar
        // en P4 el motivo y para que el motor no vuelva a llamar al proveedor
        // sin necesidad.
        $this->assertFalse($recorded->result->isVerified());
        $this->assertSame(1, $this->validations->count());
        $this->assertSame(
            [AuditEventType::IdentityValidationRequested->value, AuditEventType::IdentityValidationRejected->value],
            $this->audit->eventTypes(),
        );
    }

    public function test_the_second_event_carries_the_folio_and_the_overall_status_but_no_curp(): void
    {
        $prospect = $this->storedProspect();
        $document = $this->storedDocument((int) $prospect->id());
        $this->validator->willVerify();

        $this->useCase()->execute(
            $prospect->publicId(),
            documentPublicId: $document->publicId(),
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );

        $second = $this->audit->events()[1];
        $this->assertArrayHasKey('verification_folio', $second->metadata);
        $this->assertArrayHasKey('overall_status', $second->metadata);
        $this->assertArrayHasKey('attempts', $second->metadata);

        // Regla de seguridad 1: CURP no viaja al log ni por descuido, ni
        // siquiera dentro de una clave menor.
        $serialised = json_encode($second->metadata) ?: '';
        $this->assertStringNotContainsString('HEGG560427MVZRRL04', $serialised);
    }

    public function test_the_request_event_is_emitted_even_if_the_provider_throws(): void
    {
        $prospect = $this->storedProspect();
        $this->validator->willThrowUnavailable();

        try {
            $this->useCase()->execute(
                $prospect->publicId(),
                documentPublicId: null,
                context: $this->context(),
                now: new DateTimeImmutable('2026-08-22 12:00:00'),
            );
            $this->fail('Se esperaba que el proveedor caido propagara la excepcion.');
        } catch (\Throwable) {
            // La excepcion se propaga; lo que este test comprueba es la traza.
        }

        // Punto clave: aunque no haya resultado, queda constancia de que se
        // intento. Un pico de estos sobre el mismo expediente delata R-01.
        $this->assertSame([AuditEventType::IdentityValidationRequested->value], $this->audit->eventTypes());
        $this->assertSame(0, $this->validations->count());
    }

    public function test_a_document_that_does_not_belong_to_the_prospect_is_refused(): void
    {
        $prospect = $this->storedProspect();
        // Un UUID de documento que no existe simula un identificador ajeno:
        // sin este control, alguien podria validar contra el documento de otro
        // expediente (CWE-639).
        $strangerDocumentId = Uuid::generate();

        $this->expectException(RuntimeException::class);
        $this->useCase()->execute(
            $prospect->publicId(),
            documentPublicId: $strangerDocumentId,
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }

    public function test_an_unknown_prospect_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->useCase()->execute(
            Uuid::generate(),
            documentPublicId: null,
            context: $this->context(),
            now: new DateTimeImmutable('2026-08-22 12:00:00'),
        );
    }
}
