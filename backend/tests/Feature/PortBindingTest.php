<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\UseCase\Identity\UploadIdentityDocument;
use App\Domain\Identity\Curp;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\CardIssuer;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\IdentityValidator;
use App\Domain\Port\NotificationSender;
use App\Domain\Port\OcrService;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use App\Infrastructure\Card\SimulatedCardIssuer;
use App\Infrastructure\Identity\SimulatedIdentityValidator;
use App\Infrastructure\Notification\SimulatedNotificationSender;
use App\Infrastructure\Ocr\SimulatedOcrService;
use App\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use App\Infrastructure\Persistence\Eloquent\EloquentDocumentRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentProspectRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Doubles\FakeCardIssuer;
use Tests\Support\Doubles\FakeIdentityValidator;
use Tests\Support\Doubles\FakeNotificationSender;
use Tests\Support\Doubles\FakeOcrService;
use Tests\Support\Doubles\InMemoryAuditLogger;
use Tests\Support\Doubles\InMemoryDocumentRepository;
use Tests\Support\Doubles\InMemoryProspectRepository;
use Tests\TestCase;

/**
 * Los siete puertos del diseno, resueltos desde el contenedor tal y como los pide
 * un caso de uso. Es la comprobacion automatica de lo que audita la seccion T4 de
 * scripts/verificar_avance.sh.
 *
 * Lo que de verdad se prueba aqui no es que el enlace exista, sino que el
 * intercambio dependa SOLO de la configuracion: si hiciera falta tocar codigo para
 * cambiar de adaptador, el patron de puertos y adaptadores no estaria sirviendo
 * para nada.
 */
final class PortBindingTest extends TestCase
{
    /** @return array<string, array{0: class-string, 1: class-string}> */
    public static function portProvider(): array
    {
        return [
            'ProspectRepository' => [ProspectRepository::class, EloquentProspectRepository::class],
            'DocumentRepository' => [DocumentRepository::class, EloquentDocumentRepository::class],
            'AuditLogger' => [AuditLogger::class, EloquentAuditLogger::class],
            'OcrService' => [OcrService::class, SimulatedOcrService::class],
            'IdentityValidator' => [IdentityValidator::class, SimulatedIdentityValidator::class],
            'CardIssuer' => [CardIssuer::class, SimulatedCardIssuer::class],
            'NotificationSender' => [NotificationSender::class, SimulatedNotificationSender::class],
        ];
    }

    /**
     * @param  class-string  $port
     * @param  class-string  $expectedAdapter
     */
    #[DataProvider('portProvider')]
    public function test_every_port_resolves_to_the_adapter_that_the_configuration_selects(
        string $port,
        string $expectedAdapter,
    ): void {
        $adapter = $this->app->make($port);

        $this->assertInstanceOf($expectedAdapter, $adapter);
        $this->assertInstanceOf($port, $adapter);
        // Ningun adaptador vive fuera de Infrastructure.
        $this->assertStringStartsWith('App\\Infrastructure\\', $adapter::class);
    }

    /**
     * @param  class-string  $port
     * @param  class-string  $expectedAdapter
     */
    #[DataProvider('portProvider')]
    public function test_every_port_has_a_test_double_available(string $port, string $expectedAdapter): void
    {
        $doubles = [
            ProspectRepository::class => InMemoryProspectRepository::class,
            DocumentRepository::class => InMemoryDocumentRepository::class,
            AuditLogger::class => InMemoryAuditLogger::class,
            OcrService::class => FakeOcrService::class,
            IdentityValidator::class => FakeIdentityValidator::class,
            CardIssuer::class => FakeCardIssuer::class,
            NotificationSender::class => FakeNotificationSender::class,
        ];

        $this->assertArrayHasKey($port, $doubles, 'Falta el doble de prueba del puerto '.$port);
        $this->assertContains($port, class_implements($doubles[$port]) ?: []);
    }

    public function test_an_unknown_driver_fails_naming_the_port_and_the_options(): void
    {
        config(['adapters.ocr.driver' => 'proveedor-que-no-existe']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/OcrService.*proveedor-que-no-existe.*simulated/s');

        $this->app->make(OcrService::class);
    }

    public function test_the_simulation_scenario_comes_from_the_configuration(): void
    {
        // Mismo prospecto, misma CURP corriente: lo unico que cambia es una clave
        // de configuracion, y con eso el rechazo de eKYC queda disponible para T10.
        $prospect = $this->prospect();

        $verified = $this->app->make(IdentityValidator::class)->validate($prospect, null);

        $this->assertSame(VerificationStatus::Verified, $verified->renapoStatus);
        // Sin documento cargado la vigencia no puede comprobarse, asi que el
        // conjunto queda Pendiente: la identidad no se da por buena ni se rechaza.
        $this->assertSame(OverallValidationStatus::Pending, $verified->overallStatus());

        config(['adapters.identity.simulated.force_scenario' => 'rejected']);

        $this->assertSame(
            OverallValidationStatus::Rejected,
            $this->app->make(IdentityValidator::class)->validate($prospect, null)->overallStatus()
        );
    }

    public function test_an_unknown_simulation_scenario_is_rejected_at_resolution(): void
    {
        config(['adapters.identity.simulated.force_scenario' => 'medio-verificado']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/medio-verificado/');

        $this->app->make(IdentityValidator::class);
    }

    public function test_a_use_case_receives_its_ports_without_knowing_which_adapter_is_active(): void
    {
        $useCase = $this->app->make(UploadIdentityDocument::class);

        $this->assertInstanceOf(UploadIdentityDocument::class, $useCase);
    }

    public function test_a_test_double_can_replace_the_adapter_without_touching_the_use_case(): void
    {
        $this->app->bind(OcrService::class, static fn (): OcrService => new FakeOcrService());

        $this->assertInstanceOf(FakeOcrService::class, $this->app->make(OcrService::class));
        $this->assertInstanceOf(UploadIdentityDocument::class, $this->app->make(UploadIdentityDocument::class));
    }

    private function prospect(): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-21 09:00:00'));
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString('HEGG560427MVZRRL04'),
            rfc: null,
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
        );

        return $prospect;
    }
}
