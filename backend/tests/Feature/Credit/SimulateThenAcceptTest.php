<?php

declare(strict_types=1);

namespace Tests\Feature\Credit;

use App\Application\DTO\ProspectDataInput;
use App\Application\UseCase\Credit\AcceptCreditOffer;
use App\Application\UseCase\Credit\SimulateCredit;
use App\Application\UseCase\Identity\ValidateIdentity;
use App\Application\UseCase\Prospect\CaptureProspectData;
use App\Application\UseCase\Prospect\ConfirmProspectData;
use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\CreditSimulationExpiredException;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Port\DocumentRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prueba de integracion de la cadena P5 -> P6, sobre MySQL, sin dobles: el
 * caso de uso `SimulateCredit` persiste la simulacion, se lee de la base por
 * su UUID y se acepta con `AcceptCreditOffer`.
 *
 * Sin esta prueba, el eslabon de persistencia se podia haber roto entre P5 y
 * P6 sin que ningun test unitario lo delatara —cada uno con su doble en
 * memoria—.
 */
final class SimulateThenAcceptTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $simulatedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulatedAt = new DateTimeImmutable('2026-08-22 12:00:00');
    }

    private function context(): AuditContext
    {
        return new AuditContext('prospect:test', '198.51.100.7');
    }

    /**
     * Recorrido completo P1 -> P4 hasta dejar al prospecto listo para simular:
     * datos capturados y confirmados, y validacion de identidad verificada.
     */
    private function prospectReadyToSimulate(string $curp = 'HEGG560427MVZRRL04'): Prospect
    {
        $prospect = $this->app->make(StartProspectCapture::class)
            ->execute(CaptureMethod::Manual, '2026-08-01', $this->context(), $this->simulatedAt);

        $this->app->make(CaptureProspectData::class)->execute(
            $prospect->publicId(),
            new ProspectDataInput(
                fullName: 'Ana Perez Lopez',
                curp: $curp,
                rfc: 'GODE561231GR8',
                age: 34,
                sex: 'M',
                monthlyIncome: '20000.00',
                businessType: null,
                email: 'ana.perez@example.mx',
                phone: '5512345678',
            ),
            $this->context(),
            $this->simulatedAt,
        );
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->simulatedAt);

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
            ->execute($prospect->publicId(), $document->publicId(), $this->context(), $this->simulatedAt);

        return $prospect;
    }

    public function test_the_simulation_can_be_recovered_by_its_uuid_and_accepted(): void
    {
        $prospect = $this->prospectReadyToSimulate();

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->simulatedAt);

        // La simulacion se persistio: la fila existe con su UUID y su vigencia.
        $this->assertSame(1, DB::table('credit_simulations')->count());
        $this->assertSame(1, DB::table('credit_applications')->count());
        $stored = $this->app->make(CreditApplicationRepository::class)
            ->findSimulationByPublicId(Uuid::fromString($offer->simulationPublicId));
        $this->assertNotNull($stored);

        // Se acepta con el UUID que devuelve la simulacion. El nombre del
        // cliente y su linea salen de las mismas cifras que se calcularon.
        $customer = $this->app->make(AcceptCreditOffer::class)->execute(
            Uuid::fromString($offer->simulationPublicId),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: $this->simulatedAt->modify('+2 minutes'),
        );

        $this->assertGreaterThan(0, $customer->customerId);
        $this->assertGreaterThan(0, $customer->creditLineId);
        $this->assertSame($offer->proposedAmount, $customer->authorizedAmount);
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('credit_lines')->count());
        // La tarjeta se pudo emitir con el adaptador simulado: expediente completo.
        $this->assertSame(1, DB::table('cards')->count());
    }

    public function test_a_simulation_beyond_its_ttl_cannot_be_accepted(): void
    {
        $prospect = $this->prospectReadyToSimulate();

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->simulatedAt);

        // El TTL por defecto es 1800 s (30 min). Un intento a los 31 debe
        // caducar por la vigencia de la simulacion, no por la sesion.
        $this->expectException(CreditSimulationExpiredException::class);
        $this->app->make(AcceptCreditOffer::class)->execute(
            Uuid::fromString($offer->simulationPublicId),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: $this->simulatedAt->modify('+31 minutes'),
        );
    }

    public function test_the_ttl_is_configurable_and_a_shorter_window_kicks_in(): void
    {
        config(['credit.simulation_ttl_seconds' => 60]);
        // La reconstruccion del binding recoge el nuevo TTL. Sin este forget,
        // el singleton anterior mantendria el valor de la primera resolucion.
        $this->app->forgetInstance(SimulateCredit::class);

        $prospect = $this->prospectReadyToSimulate();

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->simulatedAt);

        $expected = $this->simulatedAt->modify('+60 seconds')->format(DateTimeImmutable::ATOM);
        $this->assertSame($expected, $offer->expiresAt);

        $this->expectException(CreditSimulationExpiredException::class);
        $this->app->make(AcceptCreditOffer::class)->execute(
            Uuid::fromString($offer->simulationPublicId),
            $prospect->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: $this->simulatedAt->modify('+2 minutes'),
        );
    }

    public function test_a_simulation_of_another_prospect_cannot_be_accepted_by_this_token(): void
    {
        // Object-level authorization (T5): conocer el UUID no basta. El
        // duenio genera la simulacion, el extranio prueba a aceptarla y falla
        // porque la solicitud de su prospecto no es la de esa simulacion.
        $owner = $this->prospectReadyToSimulate('HEGG560427MVZRRL04');
        $stranger = $this->prospectReadyToSimulate('XEXX010101HNEXXXA4');

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($owner->publicId(), 12, $this->context(), $this->simulatedAt);

        $this->expectException(\RuntimeException::class);
        $this->app->make(AcceptCreditOffer::class)->execute(
            Uuid::fromString($offer->simulationPublicId),
            $stranger->publicId(),
            contractVersion: 'v1.0',
            context: $this->context(),
            now: $this->simulatedAt->modify('+2 minutes'),
        );
    }
}
