<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DTO\ProspectDataInput;
use App\Application\UseCase\Credit\SimulateCredit;
use App\Application\UseCase\Identity\ValidateIdentity;
use App\Application\UseCase\Prospect\CaptureProspectData;
use App\Application\UseCase\Prospect\ConfirmProspectData;
use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\IdentityNotVerifiedException;
use App\Domain\Exception\InvalidCurpException;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\ProspectApplicationInProgressException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\DocumentRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Recorrido de la capa de aplicacion con los adaptadores reales enchufados:
 * P1 (inicio) -> P2 (captura) -> confirmacion -> P5 (simulacion), comprobando que
 * cada paso deja su evento en la bitacora.
 */
final class ProspectJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const CURP = 'HEGG560427MVZRRL04';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-08-21 10:00:00');
    }

    private function context(): AuditContext
    {
        return new AuditContext('prospect:anonymous', '203.0.113.10');
    }

    private function startAndCapture(string $monthlyIncome = '20000.00'): Prospect
    {
        $prospect = $this->app->make(StartProspectCapture::class)
            ->execute(CaptureMethod::Manual, '2026-08-01', $this->context(), $this->now);

        return $this->app->make(CaptureProspectData::class)->execute(
            $prospect->publicId(),
            new ProspectDataInput(
                fullName: 'Ana Perez Lopez',
                curp: self::CURP,
                rfc: 'GODE561231GR8',
                age: 34,
                sex: 'M',
                monthlyIncome: $monthlyIncome,
                businessType: null,
                email: 'ana.perez@example.mx',
                phone: '5512345678',
            ),
            $this->context(),
            $this->now
        );
    }

    public function test_the_full_journey_produces_an_offer_and_an_audit_trail(): void
    {
        $prospect = $this->startAndCapture();
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now);
        $this->validateIdentityFor($prospect);

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->now);

        $this->assertSame('personal', $offer->creditType);
        $this->assertSame('6000.00', $offer->paymentCapacity);
        $this->assertSame(12, $offer->termMonths);
        $this->assertSame('MXN', $offer->currency);
        $this->assertNotSame('0.00', $offer->proposedAmount);
        // La simulacion ya no es un calculo al aire: viaja con su UUID publico
        // y su vigencia, y es lo que P6 necesita para poder aceptarla.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $offer->simulationPublicId);
        $this->assertNotSame('', $offer->expiresAt);
        $this->assertSame(1, DB::table('credit_simulations')->count());
        $this->assertSame(1, DB::table('credit_applications')->count());

        $events = DB::table('audit_logs')->orderBy('id')->pluck('event_type')->all();

        $this->assertSame([
            'prospect.started',
            'prospect.data_captured',
            'prospect.data_confirmed',
            'identity.validation_requested',
            'identity.validation_succeeded',
            'credit_simulation.generated',
        ], $events);
    }

    public function test_simulating_without_a_verified_identity_is_refused(): void
    {
        $prospect = $this->startAndCapture();
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now);
        // A proposito no se llama a ValidateIdentity: el flujo real exige P4
        // antes de P5. Si esto pasara, la simulacion se persistiria con la
        // solicitud vacia y despues no podria reconstruirse.

        $this->expectException(IdentityNotVerifiedException::class);
        $this->app->make(SimulateCredit::class)->execute($prospect->publicId(), 12, $this->context(), $this->now);
    }

    public function test_the_audit_trail_is_anchored_to_the_prospect(): void
    {
        $prospect = $this->startAndCapture();

        $anchored = DB::table('audit_logs')->where('prospect_id', $prospect->id())->count();

        $this->assertSame(2, $anchored);
        $this->assertSame(
            'Prospect',
            DB::table('audit_logs')->where('prospect_id', $prospect->id())->value('affected_entity')
        );
    }

    public function test_the_audit_trail_never_stores_the_curp(): void
    {
        $this->startAndCapture();

        $metadata = (string) DB::table('audit_logs')->where('event_type', 'prospect.data_captured')->value('metadata');

        // Se registra que la CURP existe y con que metodo se capturo, nunca su valor.
        $this->assertStringNotContainsString(self::CURP, $metadata);
        $this->assertStringNotContainsString('HEGG', $metadata);
        $this->assertStringContainsString('has_curp', $metadata);
        $this->assertStringContainsString('manual', $metadata);
    }

    public function test_a_second_prospect_cannot_reuse_a_curp_with_a_live_application(): void
    {
        $this->startAndCapture();

        // La excepcion es especifica, no `InvalidCurpException` (VUL-17). La
        // version anterior de esta prueba afirmaba la clase generica, que es la
        // MISMA que se lanza cuando la CURP esta malformada: con esa asercion,
        // la prueba pasaba tanto si el sistema decia «tienes una solicitud en
        // curso» como si le decia «tu CURP no es valida» a alguien cuya CURP
        // era correcta. Era demasiado gruesa para ver la diferencia que importa.
        try {
            $this->startAndCapture();
            $this->fail('Se esperaba que la segunda solicitud fuera rechazada.');
        } catch (ProspectApplicationInProgressException $e) {
            $this->assertSame('PROSPECT_APPLICATION_IN_PROGRESS', $e->errorCode());
            // El mensaje lleva minutos reales y NO dice que la CURP sea invalida.
            $this->assertGreaterThan(0, $e->retryAfterMinutes());
            $this->assertStringNotContainsString('no es valida', $e->userMessage());
            $this->assertStringContainsString((string) $e->retryAfterMinutes(), $e->userMessage());
        }
    }

    public function test_the_simulation_requires_confirmed_data(): void
    {
        $prospect = $this->startAndCapture();

        $this->expectException(InvalidStateTransitionException::class);

        $this->app->make(SimulateCredit::class)->execute($prospect->publicId(), 12, $this->context(), $this->now);
    }

    public function test_a_term_outside_the_catalogue_is_rejected_even_reaching_the_use_case(): void
    {
        // VUL-02 extremo a extremo: el plazo llega desde fuera y muere en el dominio.
        $prospect = $this->startAndCapture();
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now);
        $this->validateIdentityFor($prospect);

        $this->expectException(UnauthorizedTermException::class);

        $this->app->make(SimulateCredit::class)->execute($prospect->publicId(), 9, $this->context(), $this->now);
    }

    /**
     * Ejecuta P4 con el validador simulado en modo "verificado". Requiere un
     * documento cargado para que la vigencia del documento tambien salga
     * verificada; sin el, el conjunto queda en `pending`.
     */
    private function validateIdentityFor(Prospect $prospect): void
    {
        $documents = $this->app->make(DocumentRepository::class);
        $document = $documents->save(IdentityDocument::register(
            prospectId: (int) $prospect->id(),
            documentType: DocumentType::Ine,
            storagePath: 'documents/2026/ine.jpg',
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 250_000,
            fileHash: hash('sha256', 'ine-content'),
        ));

        $this->app->make(ValidateIdentity::class)
            ->execute($prospect->publicId(), $document->publicId(), $this->context(), $this->now);
    }
}
