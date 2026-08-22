<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Infrastructure\Ocr\BullMqOcrService;
use App\Infrastructure\Ocr\SimulatedOcrService;
use App\Infrastructure\Queue\BullMqQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * La prueba que sostiene el unico acoplamiento delicado de T7.
 *
 * `BullMqQueue` escribe directamente el esquema de claves interno de BullMQ, que
 * se determino **observando** lo que hace `Queue.add` en la version 6.1.2, no
 * leyendo documentacion: la libreria no publica ese formato como contrato y
 * puede cambiarlo en una version mayor.
 *
 * Por eso no basta con comprobar que las claves quedan escritas: eso solo
 * probaria que PHP hace lo que PHP cree. Lo que hace falta es que **la libreria
 * de verdad** consuma lo que PHP escribio. Si una actualizacion de BullMQ cambia
 * el formato, esta prueba se pone roja aqui y no en produccion, con trabajos
 * perdiendose en silencio.
 *
 * Necesita Redis y Node con las dependencias del worker instaladas. Si no estan,
 * se omite en vez de fallar: la ausencia de herramientas no es un defecto del
 * codigo, y una prueba que falla por el entorno acaba ignorandose.
 */
final class BullMqContractTest extends TestCase
{
    private const QUEUE = 'contract-test';

    private const PREFIX = 'bull-test';

    private string $workerPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerPath = dirname(base_path());

        if (! is_dir($this->workerPath.'/worker/node_modules/bullmq')) {
            $this->markTestSkipped('Las dependencias del worker no estan instaladas (worker/node_modules).');
        }

        try {
            $this->redis()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis no esta disponible.');
        }

        $this->flushQueue();
    }

    protected function tearDown(): void
    {
        $this->flushQueue();

        parent::tearDown();
    }

    private function redis(): Connection
    {
        return app(RedisFactory::class)->connection('bullmq');
    }

    private function flushQueue(): void
    {
        $keys = $this->redis()->keys(self::PREFIX.':'.self::QUEUE.':*');

        if ($keys !== []) {
            $this->redis()->del(...$keys);
        }
    }

    private function queue(): BullMqQueue
    {
        return new BullMqQueue(
            redis: app(RedisFactory::class),
            connection: 'bullmq',
            prefix: self::PREFIX,
        );
    }

    /** @return array<string, mixed> */
    private function consumeOne(): array
    {
        $result = Process::path($this->workerPath.'/worker')
            ->timeout(30)
            ->run(['node', 'tests/support/consumeOnce.mjs', self::QUEUE, self::PREFIX, '10000']);

        $decoded = json_decode(trim($result->output()), true);

        $this->assertIsArray($decoded, 'El consumidor de BullMQ no devolvio JSON: '.$result->output().$result->errorOutput());

        return $decoded;
    }

    #[Test]
    public function a_real_bullmq_worker_consumes_what_php_enqueues(): void
    {
        $jobId = $this->queue()->add(
            queue: self::QUEUE,
            jobName: 'ocr.extract',
            data: ['document_public_id' => 'abc', 'attempt' => 1],
            options: [
                'attempts' => 3,
                'backoff' => ['type' => 'exponential', 'delay' => 1000],
                'removeOnFail' => false,
            ],
        );

        $this->assertSame('1', $jobId, 'El identificador lo asigna el contador de BullMQ.');

        $consumed = $this->consumeOne();

        $this->assertTrue($consumed['ok'], $consumed['error'] ?? '');
        $this->assertSame('1', $consumed['id']);
        $this->assertSame('ocr.extract', $consumed['name']);
        $this->assertSame(['document_public_id' => 'abc', 'attempt' => 1], $consumed['data']);

        // Las opciones tienen que llegar intactas: si el backoff se perdiera por
        // el camino, los reintentos serian inmediatos y nadie lo notaria hasta
        // tener delante a un proveedor caido.
        $this->assertSame(3, $consumed['attempts']);
        $this->assertSame(['type' => 'exponential', 'delay' => 1000], $consumed['backoff']);

        // PT-03 depende de esto: el trabajo fallido no se borra.
        $this->assertFalse($consumed['removeOnFail']);
    }

    #[Test]
    public function the_ocr_adapter_puts_the_scenario_in_the_job_reference(): void
    {
        // El escenario viaja en el identificador y es el contrato con el worker:
        // asi puede simular sin consultar la base ni la configuracion del
        // backend. Si el formato cambiara de un lado, el otro deja de entenderlo.
        $adapter = new BullMqOcrService(
            queue: $this->queue(),
            scenarios: new SimulatedOcrService(
                logger: new NullLogger,
                scenarioMarkers: ['timeout' => 'sandbox-timeout', 'unreadable' => 'sandbox-unreadable'],
            ),
            logger: new NullLogger,
            queueName: self::QUEUE,
        );

        $document = IdentityDocument::register(
            prospectId: 1,
            documentType: DocumentType::Ine,
            storagePath: 'identity-documents/p1/ine-sandbox-timeout.jpg',
            detectedMimeType: 'image/jpeg',
            fileSizeBytes: 1024,
            fileHash: str_repeat('a', 64),
            originalExtension: 'jpg',
        );

        $jobRef = $adapter->enqueueExtraction($document);

        $this->assertSame('ocrsim-timeout-'.str_repeat('a', 16), $jobRef);

        $consumed = $this->consumeOne();

        $this->assertTrue($consumed['ok'], $consumed['error'] ?? '');
        $this->assertSame($jobRef, $consumed['data']['job_ref']);

        // Y lo que NO viaja: ningun dato personal. Una cola es almacenamiento
        // persistente, y los trabajos fallidos se quedan ahi para inspeccion.
        $this->assertSame(
            ['document_public_id', 'storage_path', 'file_hash', 'job_ref'],
            array_keys($consumed['data']),
        );
    }
}
