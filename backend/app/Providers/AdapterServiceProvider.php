<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Port\AuditLogger;
use App\Domain\Port\CardIssuer;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Port\IdentityValidator;
use App\Domain\Port\NotificationSender;
use App\Domain\Port\OcrService;
use App\Domain\Port\ProspectRepository;
use App\Domain\Port\TransactionManager;
use App\Infrastructure\Card\SimulatedCardIssuer;
use App\Infrastructure\Identity\IdentityScenario;
use App\Infrastructure\Identity\SimulatedIdentityValidator;
use App\Infrastructure\Notification\BullMqNotificationSender;
use App\Infrastructure\Notification\SimulatedNotificationSender;
use App\Infrastructure\Ocr\BullMqOcrService;
use App\Infrastructure\Ocr\OcrScenario;
use App\Infrastructure\Ocr\SimulatedOcrService;
use App\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use App\Infrastructure\Persistence\Eloquent\EloquentCreditApplicationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCustomerRegistry;
use App\Infrastructure\Persistence\Eloquent\EloquentDocumentRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentIdentityValidationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentProspectRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentTransactionManager;
use App\Infrastructure\Queue\BullMqQueue;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
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
        // --- Transporte de la cola ----------------------------------------------
        // BullMqQueue no es un puerto: es la pieza compartida por los
        // adaptadores que encolan, y su configuracion —conexion, prefijo— tiene
        // que ser una sola para que backend y worker miren las mismas claves.

        $this->app->singleton(BullMqQueue::class, static fn (Application $app): BullMqQueue => new BullMqQueue(
            redis: $app->make(RedisFactory::class),
            connection: (string) config('adapters.bullmq.connection', 'bullmq'),
            prefix: (string) config('adapters.bullmq.prefix', 'bull'),
            maxLenEvents: (int) config('adapters.bullmq.max_len_events', 10000),
        ));

        // El simulado se registra aparte porque lo usan dos drivers: como
        // adaptador completo con driver 'simulated', y como resolutor de
        // escenarios dentro del driver 'bullmq'. Sin este enlace, un
        // $app->make() lo construiria por autowiring y perderia su
        // configuracion, que es justo lo que decide el desenlace simulado.
        $this->app->singleton(SimulatedOcrService::class, fn (Application $app): SimulatedOcrService => new SimulatedOcrService(
            logger: $app->make(LoggerInterface::class),
            forcedScenario: $this->scenario(OcrScenario::class, 'adapters.ocr.simulated.force_scenario'),
            scenarioMarkers: (array) config('adapters.ocr.simulated.scenario_markers', []),
            failEnqueue: $this->flag('adapters.ocr.simulated.fail_enqueue'),
        ));

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

        // Unidad de trabajo para las escrituras que cruzan varios puertos. La
        // carga de una identificacion toca documento, prospecto y bitacora: sin
        // esto, un fallo a mitad dejaba la fila del documento persistida y sin
        // su evento en la bitacora (VUL-15).
        $this->bindPort(TransactionManager::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): TransactionManager => $app->make(
                EloquentTransactionManager::class
            ),
        ]);

        $this->bindPort(IdentityValidationRepository::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): IdentityValidationRepository => $app->make(
                EloquentIdentityValidationRepository::class
            ),
        ]);

        $this->bindPort(CreditApplicationRepository::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): CreditApplicationRepository => $app->make(
                EloquentCreditApplicationRepository::class
            ),
        ]);

        $this->bindPort(CustomerRegistry::class, 'adapters.persistence', [
            'eloquent' => static fn (Application $app): CustomerRegistry => $app->make(EloquentCustomerRegistry::class),
        ]);

        // --- Servicios externos -------------------------------------------------
        // Sin credenciales y sin red hacia proveedores: no hay convenio con INE
        // ni con RENAPO y el entorno es de desarrollo. Identidad y tarjetas
        // siguen simulados; su adaptador contra proveedor entra en su tarea.
        //
        // OCR y notificaciones ganan en T7 el driver 'bullmq', que SI es
        // transporte real: encola en Redis y lo consume el worker de Node. Lo
        // que sigue simulado ahi es el proveedor, no la cola.

        $this->bindPort(OcrService::class, 'adapters.ocr', [
            'simulated' => fn (Application $app): OcrService => $app->make(SimulatedOcrService::class),

            'bullmq' => fn (Application $app): OcrService => new BullMqOcrService(
                queue: $app->make(BullMqQueue::class),
                // El simulado se reutiliza solo para resolver que escenario
                // corresponde a cada documento; no encola nada.
                scenarios: $app->make(SimulatedOcrService::class),
                logger: $app->make(LoggerInterface::class),
                queueName: (string) config('adapters.ocr.bullmq.queue', 'ocr'),
                jobName: (string) config('adapters.ocr.bullmq.job', 'ocr.extract'),
                attempts: (int) config('adapters.ocr.bullmq.attempts', 3),
                backoffDelayMs: (int) config('adapters.ocr.bullmq.backoff_ms', 1000),
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

            'bullmq' => fn (Application $app): NotificationSender => new BullMqNotificationSender(
                queue: $app->make(BullMqQueue::class),
                logger: $app->make(LoggerInterface::class),
                queueName: (string) config('adapters.notification.bullmq.queue', 'notifications'),
                jobName: (string) config('adapters.notification.bullmq.job', 'notification.send'),
                attempts: (int) config('adapters.notification.bullmq.attempts', 5),
                backoffDelayMs: (int) config('adapters.notification.bullmq.backoff_ms', 2000),
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
