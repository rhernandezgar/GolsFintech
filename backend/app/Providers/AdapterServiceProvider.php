<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Port\AuditLogger;
use App\Domain\Port\CardIssuer;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\IdentityValidator;
use App\Domain\Port\NotificationSender;
use App\Domain\Port\OcrService;
use App\Domain\Port\ProspectRepository;
use App\Infrastructure\Card\SimulatedCardIssuer;
use App\Infrastructure\Identity\IdentityScenario;
use App\Infrastructure\Identity\SimulatedIdentityValidator;
use App\Infrastructure\Notification\SimulatedNotificationSender;
use App\Infrastructure\Ocr\OcrScenario;
use App\Infrastructure\Ocr\SimulatedOcrService;
use App\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use App\Infrastructure\Persistence\Eloquent\EloquentDocumentRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentProspectRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Enchufa los siete puertos del dominio con su adaptador.
 *
 * Es el UNICO lugar del sistema que conoce las dos capas a la vez, y el unico que
 * lee `config/adapters.php`. Ni el dominio ni los casos de uso saben cual
 * adaptador esta activo: piden el puerto y reciben lo que diga la configuracion.
 * Si un `if` sobre el entorno apareciera en Application, el patron habria dejado de
 * cumplir su funcion —y hay una prueba de arquitectura que lo impide.
 *
 * Cada puerto declara su tabla de drivers. Anadir el adaptador real de un servicio
 * externo es anadir una entrada a su tabla y cambiar una variable de entorno; no se
 * toca ninguna otra capa. Un driver inexistente falla al resolver el puerto, con el
 * nombre del puerto y la lista de los disponibles, y no en silencio.
 */
final class AdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // --- Persistencia -------------------------------------------------------
        // El adaptador de pruebas no vive aqui: los dobles en memoria estan en
        // tests/Support/Doubles y cada prueba los enchufa por su cuenta. Apuntar la
        // configuracion de produccion a una clase de tests seria un error de carga.

        $this->bindPort(ProspectRepository::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): ProspectRepository => $app->make(EloquentProspectRepository::class),
        ]);

        $this->bindPort(DocumentRepository::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): DocumentRepository => $app->make(EloquentDocumentRepository::class),
        ]);

        $this->bindPort(AuditLogger::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): AuditLogger => $app->make(EloquentAuditLogger::class),
        ]);

        // --- Servicios externos, todos simulados en T4 --------------------------
        // Sin credenciales y sin red: no hay convenio con INE ni con RENAPO y el
        // entorno es de desarrollo. El adaptador real de cada uno entra en su
        // tarea: OCR en T7, identidad en T7, tarjetas en T9, notificaciones en T8.

        $this->bindPort(OcrService::class, 'adapters.ocr', [
            'simulated' => fn (Application $app): OcrService => new SimulatedOcrService(
                logger: $app->make(LoggerInterface::class),
                forcedScenario: $this->scenario(OcrScenario::class, 'adapters.ocr.simulated.force_scenario'),
                scenarioMarkers: (array) config('adapters.ocr.simulated.scenario_markers', []),
                failEnqueue: $this->flag('adapters.ocr.simulated.fail_enqueue'),
            ),
        ]);

        $this->bindPort(IdentityValidator::class, 'adapters.identity', [
            'simulated' => fn (): IdentityValidator => new SimulatedIdentityValidator(
                forcedScenario: $this->scenario(IdentityScenario::class, 'adapters.identity.simulated.force_scenario'),
                sandboxCurps: (array) config('adapters.identity.simulated.sandbox_curps', []),
            ),
        ]);

        $this->bindPort(CardIssuer::class, 'adapters.card', [
            'simulated' => fn (): CardIssuer => new SimulatedCardIssuer(
                fail: $this->flag('adapters.card.simulated.fail'),
                validityYears: (int) config('adapters.card.simulated.validity_years', 3),
            ),
        ]);

        $this->bindPort(NotificationSender::class, 'adapters.notification', [
            'simulated' => fn (Application $app): NotificationSender => new SimulatedNotificationSender(
                logger: $app->make(LoggerInterface::class),
                fail: $this->flag('adapters.notification.simulated.fail'),
            ),
        ]);
    }

    /**
     * @param  class-string  $port
     * @param  array<string, callable(Application): object>  $drivers
     */
    private function bindPort(string $port, string $configKey, array $drivers): void
    {
        $this->app->bind($port, static function (Application $app) use ($port, $configKey, $drivers): object {
            $driver = (string) $app['config']->get($configKey.'.driver', '');

            if (! isset($drivers[$driver])) {
                throw new InvalidArgumentException(sprintf(
                    'El puerto %s no tiene adaptador para el driver "%s" (%s.driver en config/adapters.php). Disponibles: %s.',
                    $port,
                    $driver,
                    $configKey,
                    implode(', ', array_keys($drivers))
                ));
            }

            return $drivers[$driver]($app);
        });
    }

    /**
     * Lee un escenario de simulacion de la configuracion. Vacio significa "sin
     * forzar": cada entrada decide su desenlace.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function scenario(string $enum, string $configKey)
    {
        $value = config($configKey);

        if ($value === null || $value === '') {
            return null;
        }

        $scenario = $enum::tryFrom((string) $value);

        if ($scenario === null) {
            throw new InvalidArgumentException(sprintf(
                'Escenario de simulacion "%s" desconocido en %s. Disponibles: %s.',
                (string) $value,
                $configKey,
                implode(', ', array_column($enum::cases(), 'value'))
            ));
        }

        return $scenario;
    }

    /** Un "false" que llega como cadena desde el entorno sigue siendo false. */
    private function flag(string $configKey): bool
    {
        return filter_var(config($configKey, false), FILTER_VALIDATE_BOOL);
    }
}
