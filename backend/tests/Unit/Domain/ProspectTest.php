<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\ProspectDataIncompleteException;
use App\Domain\Identity\Curp;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\CaptureStatus;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProspectTest extends TestCase
{
    private function startedProspect(): Prospect
    {
        return Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-21 09:00:00'));
    }

    private function capture(Prospect $prospect): void
    {
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString('HEGG560427MVZRRL04'),
            rfc: null,
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
        );
    }

    public function test_a_new_prospect_starts_with_a_public_id_and_no_data(): void
    {
        $prospect = $this->startedProspect();

        $this->assertSame(CaptureStatus::Started, $prospect->captureStatus());
        $this->assertNull($prospect->id());
        $this->assertNull($prospect->curp());
        $this->assertNotSame('', $prospect->publicId()->value);
    }

    public function test_the_capture_flow_reaches_confirmed_data(): void
    {
        $prospect = $this->startedProspect();
        $this->capture($prospect);

        $this->assertSame(CaptureStatus::DataCaptured, $prospect->captureStatus());

        $prospect->confirmData();

        $this->assertSame(CaptureStatus::DataConfirmed, $prospect->captureStatus());
        $this->assertTrue($prospect->hasConfirmedData());
    }

    public function test_data_cannot_be_confirmed_before_being_captured(): void
    {
        try {
            $this->startedProspect()->confirmData();
            $this->fail('Se esperaba ProspectDataIncompleteException al confirmar sin datos.');
        } catch (ProspectDataIncompleteException $e) {
            // La excepcion propia trae la lista de campos faltantes: es lo
            // que el endpoint de P6 usa para responder 422 diciendo cuales.
            $this->assertEqualsCanonicalizing(
                ['full_name', 'curp', 'age', 'sex', 'monthly_income'],
                $e->missingFields(),
            );
        }
    }

    public function test_a_confirmed_prospect_cannot_go_back_to_capture(): void
    {
        // El servidor no acepta rehacer la captura porque el navegador lo pida.
        $prospect = $this->startedProspect();
        $this->capture($prospect);
        $prospect->confirmData();

        $this->expectException(InvalidStateTransitionException::class);

        $this->capture($prospect);
    }

    public function test_an_abandoned_prospect_is_terminal(): void
    {
        $prospect = $this->startedProspect();
        $prospect->abandon();

        $this->expectException(InvalidStateTransitionException::class);

        $prospect->markDocumentUploaded();
    }

    public function test_data_can_be_corrected_before_confirmation(): void
    {
        $prospect = $this->startedProspect();
        $this->capture($prospect);
        $this->capture($prospect);

        $this->assertSame(CaptureStatus::DataCaptured, $prospect->captureStatus());
    }

    // ============================================== VUL-15 =================

    /**
     * El caso que produjo VUL-15: la rama manual llega al documento DESPUES de
     * confirmar los datos, porque el veredicto «verificado» de P4 exige
     * documento. Antes esto lanzaba `InvalidStateTransitionException` y el
     * endpoint devolvia 500.
     */
    public function test_uploading_a_document_after_confirming_does_not_undo_the_confirmation(): void
    {
        $prospect = $this->startedProspect();
        $this->capture($prospect);
        $prospect->confirmData();

        $this->assertSame(CaptureStatus::DataConfirmed, $prospect->captureStatus());

        $prospect->markDocumentUploaded();

        // No lanza, y sobre todo NO retrocede: `hasConfirmedData()` compara con
        // DataConfirmed exactamente, asi que un retroceso dejaria a P4
        // respondiendo 422 y la rama manual bloqueada otra vez, en silencio.
        $this->assertSame(CaptureStatus::DataConfirmed, $prospect->captureStatus());
        $this->assertTrue($prospect->hasConfirmedData());
    }

    public function test_uploading_a_document_twice_after_confirming_stays_confirmed(): void
    {
        // Un reintento de carga -P3 lo permite tras un OCR fallido- no puede
        // degradar el estado en la segunda pasada.
        $prospect = $this->startedProspect();
        $this->capture($prospect);
        $prospect->confirmData();

        $prospect->markDocumentUploaded();
        $prospect->markDocumentUploaded();

        $this->assertTrue($prospect->hasConfirmedData());
    }

    public function test_the_no_op_does_not_resurrect_an_abandoned_file(): void
    {
        // El no-op es SOLO para data_confirmed. Un expediente abandonado sigue
        // siendo terminal: si esto dejara de lanzar, subir un documento
        // reabriria un tramite que el negocio da por cerrado.
        $prospect = $this->startedProspect();
        $prospect->abandon();

        $this->expectException(InvalidStateTransitionException::class);
        $prospect->markDocumentUploaded();
    }

    public function test_the_ocr_branch_still_moves_to_document_uploaded(): void
    {
        // La rama OCR no cambia: el documento es el mecanismo de captura y
        // `document_uploaded` es su estado temprano legitimo.
        $prospect = $this->startedProspect();

        $prospect->markDocumentUploaded();

        $this->assertSame(CaptureStatus::DocumentUploaded, $prospect->captureStatus());
        $this->assertFalse($prospect->hasConfirmedData());
    }
}
