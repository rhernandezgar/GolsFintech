<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\Curp;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use App\Infrastructure\Card\SimulatedCardIssuer;
use App\Infrastructure\Identity\IdentityScenario;
use App\Infrastructure\Identity\SimulatedIdentityValidator;
use App\Infrastructure\Notification\SimulatedNotificationSender;
use App\Infrastructure\Ocr\OcrScenario;
use App\Infrastructure\Ocr\SimulatedOcrService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Los adaptadores simulados de T4. Se prueban dos cosas de cada uno: que sean
 * DETERMINISTAS —la misma entrada produce siempre la misma salida— y que sepan
 * fallar, porque de nada sirve un simulador que solo conoce el camino feliz.
 *
 * Sin base de datos, sin contenedor y sin red: son adaptadores, pero su logica es
 * pura y se prueba como tal.
 */
final class SimulatedAdaptersTest extends TestCase
{
    private function document(string $storagePath = 'documents/2026/ine-frente.jpg'): IdentityDocument
    {
        return IdentityDocument::register(
            prospectId: 1,
            documentType: DocumentType::Ine,
            storagePath: $storagePath,
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 250_000,
            fileHash: hash('sha256', 'contenido-del-archivo'),
        );
    }

    private function prospect(string $curp): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-21 09:00:00'));
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString($curp),
            rfc: null,
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
        );

        return $prospect;
    }

    /** @return array{0: SimulatedIdentityValidator, 1: array<string, list<string>>} */
    private function validator(?IdentityScenario $forced = null): array
    {
        $sandbox = [
            'rejected' => ['XEXX010101HNEXXXA4'],
            'unavailable' => ['XEXX020202MNEXXXA1'],
            'fraud' => ['XEXX030303HNEXXXA8'],
        ];

        return [new SimulatedIdentityValidator($forced, $sandbox, new DateTimeImmutable('2026-08-21')), $sandbox];
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    // ---------------------------------------------------------------- OCR ----

    public function test_the_simulated_ocr_returns_the_same_job_id_for_the_same_document(): void
    {
        $ocr = new SimulatedOcrService($this->logger());
        $document = $this->document();

        $this->assertSame($ocr->enqueueExtraction($document), $ocr->enqueueExtraction($document));
    }

    public function test_a_reserved_path_marker_makes_the_simulated_ocr_time_out(): void
    {
        // El timeout es el caso que T7 necesita para probar los reintentos.
        $ocr = new SimulatedOcrService(
            logger: $this->logger(),
            scenarioMarkers: ['timeout' => 'sandbox-timeout', 'unreadable' => 'sandbox-unreadable'],
        );

        $jobId = $ocr->enqueueExtraction($this->document('documents/2026/ine-sandbox-timeout.jpg'));

        $this->assertSame(OcrScenario::Timeout, SimulatedOcrService::scenarioOfJobId($jobId));
        $this->assertTrue(OcrScenario::Timeout->isRetryable());
    }

    public function test_an_unreadable_document_is_not_retryable(): void
    {
        $ocr = new SimulatedOcrService(
            logger: $this->logger(),
            scenarioMarkers: ['timeout' => 'sandbox-timeout', 'unreadable' => 'sandbox-unreadable'],
        );

        $jobId = $ocr->enqueueExtraction($this->document('documents/2026/ine-sandbox-unreadable.png'));

        $this->assertSame(OcrScenario::Unreadable, SimulatedOcrService::scenarioOfJobId($jobId));
        $this->assertFalse(OcrScenario::Unreadable->isRetryable());
    }

    public function test_a_document_without_markers_is_extracted(): void
    {
        $ocr = new SimulatedOcrService(
            logger: $this->logger(),
            scenarioMarkers: ['timeout' => 'sandbox-timeout'],
        );

        $this->assertSame(
            OcrScenario::Extracted,
            SimulatedOcrService::scenarioOfJobId($ocr->enqueueExtraction($this->document()))
        );
    }

    public function test_the_forced_scenario_overrides_the_path_marker(): void
    {
        $ocr = new SimulatedOcrService(
            logger: $this->logger(),
            forcedScenario: OcrScenario::Timeout,
            scenarioMarkers: ['unreadable' => 'sandbox-unreadable'],
        );

        $this->assertSame(
            OcrScenario::Timeout,
            $ocr->scenarioFor($this->document('documents/2026/ine-sandbox-unreadable.png'))
        );
    }

    public function test_the_simulated_ocr_can_fail_to_enqueue(): void
    {
        $ocr = new SimulatedOcrService(logger: $this->logger(), failEnqueue: true);

        $this->expectException(ExternalServiceUnavailableException::class);

        $ocr->enqueueExtraction($this->document());
    }

    public function test_the_simulated_ocr_does_not_log_the_storage_path_nor_personal_data(): void
    {
        $logger = $this->logger();
        (new SimulatedOcrService($logger))->enqueueExtraction($this->document());

        $context = $logger->records[0]['context'];

        $this->assertSame(['job_id', 'document_public_id', 'scenario', 'attempt'], array_keys($context));
    }

    // ----------------------------------------------------------- Identidad ----

    public function test_an_ordinary_curp_is_verified(): void
    {
        [$validator] = $this->validator();

        $result = $validator->validate($this->prospect('HEGG560427MVZRRL04'), null);

        $this->assertSame(VerificationStatus::Verified, $result->ineStatus);
        $this->assertSame(VerificationStatus::Verified, $result->renapoStatus);
    }

    public function test_the_sandbox_curps_reproduce_each_ekyc_outcome(): void
    {
        [$validator] = $this->validator();

        $rejected = $validator->validate($this->prospect('XEXX010101HNEXXXA4'), null);
        $this->assertSame(VerificationStatus::NotVerified, $rejected->renapoStatus);
        $this->assertSame(OverallValidationStatus::Rejected, $rejected->overallStatus());

        // Indisponibilidad NO es rechazo: el prospecto queda pendiente (riesgo R-03).
        $unavailable = $validator->validate($this->prospect('XEXX020202MNEXXXA1'), null);
        $this->assertSame(VerificationStatus::Unavailable, $unavailable->ineStatus);
        $this->assertSame(OverallValidationStatus::Pending, $unavailable->overallStatus());

        $fraud = $validator->validate($this->prospect('XEXX030303HNEXXXA8'), null);
        $this->assertTrue($fraud->fraudFlagged);
        $this->assertSame(OverallValidationStatus::Rejected, $fraud->overallStatus());
    }

    public function test_the_identity_result_is_deterministic_for_the_same_prospect(): void
    {
        [$validator] = $this->validator();
        $prospect = $this->prospect('HEGG560427MVZRRL04');

        $this->assertSame(
            $validator->validate($prospect, null)->verificationFolio,
            $validator->validate($prospect, null)->verificationFolio
        );
    }

    public function test_the_provider_response_never_carries_the_curp(): void
    {
        [$validator] = $this->validator();
        $prospect = $this->prospect('XEXX010101HNEXXXA4');

        $response = $validator->validate($prospect, null)->maskedProviderResponse;

        $this->assertStringNotContainsString('XEXX010101HNEXXXA4', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function test_the_forced_identity_scenario_overrides_the_curp(): void
    {
        [$validator] = $this->validator(IdentityScenario::Rejected);

        // CURP corriente, pero el entorno esta forzado a rechazo: es lo que permite
        // a T10 ejercitar el mensaje generico sin depender de una CURP concreta.
        $this->assertSame(
            OverallValidationStatus::Rejected,
            $validator->validate($this->prospect('HEGG560427MVZRRL04'), null)->overallStatus()
        );
    }

    // ------------------------------------------------------------ Tarjeta ----

    public function test_the_simulated_card_is_deterministic_and_is_never_a_pan(): void
    {
        $issuer = new SimulatedCardIssuer(reference: new DateTimeImmutable('2026-08-21'));

        $first = $issuer->issue(7, 3);
        $second = $issuer->issue(7, 3);

        $this->assertSame($first->tokenizedCardNumber, $second->tokenizedCardNumber);
        $this->assertStringStartsWith('tok_sim_', $first->tokenizedCardNumber);
        $this->assertDoesNotMatchRegularExpression('/^\d{13,19}$/', $first->tokenizedCardNumber);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $first->lastFour);
        $this->assertSame('**** **** **** '.$first->lastFour, $first->maskedNumber());
        $this->assertSame(2029, $first->expirationYear);
    }

    public function test_two_different_credit_lines_get_different_cards(): void
    {
        $issuer = new SimulatedCardIssuer(reference: new DateTimeImmutable('2026-08-21'));

        $this->assertNotSame(
            $issuer->issue(7, 3)->tokenizedCardNumber,
            $issuer->issue(7, 4)->tokenizedCardNumber
        );
    }

    public function test_the_simulated_card_issuer_can_fail(): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        (new SimulatedCardIssuer(fail: true))->issue(7, 3);
    }

    // ------------------------------------------------------ Notificaciones ----

    public function test_the_simulated_notification_masks_the_recipient(): void
    {
        $logger = $this->logger();
        $sender = new SimulatedNotificationSender($logger);

        $sender->send(new Notification(
            channel: NotificationChannel::Email,
            recipient: 'rogelio@example.com',
            templateKey: 'credit.authorized',
            parameters: ['customer_number' => 'GF-0084417'],
        ));

        $context = $logger->records[0]['context'];

        $this->assertSame('r******@example.com', $context['recipient']);
        $this->assertSame('credit.authorized', $context['template_key']);
    }

    public function test_a_phone_recipient_keeps_only_its_last_four_digits(): void
    {
        $this->assertSame('********3456', SimulatedNotificationSender::maskRecipient('+52 993 123 3456'));
    }

    public function test_the_simulated_notification_sender_can_fail(): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        (new SimulatedNotificationSender($this->logger(), fail: true))->send(new Notification(
            channel: NotificationChannel::Sms,
            recipient: '+52 993 123 3456',
            templateKey: 'credit.authorized',
        ));
    }
}
