<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\OcrService;
use App\Infrastructure\Queue\BullMqQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adaptador de OCR sobre BullMQ: encola y devuelve, sin esperar al proveedor.
 *
 * Es el adaptador que hace real el tramo asincrono de la Figura 2a. El caso de
 * uso no cambia respecto a T4 —sigue llamando a `enqueueExtraction`— y por eso
 * el endpoint puede responder 202 en cuanto esto retorna.
 *
 * QUE VIAJA EN LA COLA, y sobre todo que NO viaja: solo el identificador publico
 * del documento, su ruta de almacenamiento, su hash y el numero de intento.
 * Ningun dato personal. Una cola es almacenamiento persistente —los trabajos
 * fallidos se quedan ahi para inspeccionarlos— y meter una CURP en la carga
 * seria escribirla en claro fuera de la base, contra la regla no negociable 1.
 * El worker lee el documento por su identificador cuando lo necesita.
 *
 * El escenario simulado viaja dentro de `job_ref`, con el mismo formato que emite
 * SimulatedOcrService: `ocrsim-<escenario>-<16 hex>`. Asi el worker sabe que debe
 * simular sin compartir configuracion ni base de datos con el backend. Cuando
 * exista proveedor real, `job_ref` deja de llevar escenario y el worker llama al
 * proveedor; nada mas cambia.
 */
final readonly class BullMqOcrService implements OcrService
{
    public function __construct(
        private BullMqQueue $queue,
        private SimulatedOcrService $scenarios,
        private LoggerInterface $logger,
        private string $queueName = 'ocr',
        private string $jobName = 'ocr.extract',
        /** Reintentos totales, incluido el primer intento. */
        private int $attempts = 3,
        /** Retardo base del backoff exponencial, en milisegundos. */
        private int $backoffDelayMs = 1000,
    ) {}

    public function enqueueExtraction(IdentityDocument $document): string
    {
        // El escenario se resuelve aqui, del lado del backend, y viaja en el
        // identificador: el worker no consulta configuracion del backend.
        $scenario = $this->scenarios->scenarioFor($document);
        $jobRef = sprintf('ocrsim-%s-%s', $scenario->value, substr($document->fileHash(), 0, 16));

        try {
            $jobId = $this->queue->add(
                queue: $this->queueName,
                jobName: $this->jobName,
                data: [
                    'document_public_id' => $document->publicId()->value,
                    'storage_path' => $document->storagePath(),
                    'file_hash' => $document->fileHash(),
                    'job_ref' => $jobRef,
                ],
                options: [
                    'attempts' => $this->attempts,
                    // Exponencial: 1 s, 2 s, 4 s. Un proveedor que se esta
                    // recuperando empeora si se le reintenta a ritmo fijo.
                    'backoff' => ['type' => 'exponential', 'delay' => $this->backoffDelayMs],
                    // El trabajo fallido NO se borra: es lo que permite
                    // recuperar la solicitud sin perder los datos del prospecto
                    // cuando se agotan los reintentos (PT-03).
                    'removeOnFail' => false,
                    'removeOnComplete' => ['age' => 86400],
                ],
            );
        } catch (Throwable $e) {
            // Redis caido o inalcanzable. Se traduce a la excepcion de dominio
            // para que el caso de uso no conozca la infraestructura, y el
            // mensaje al cliente no nombra al proveedor (regla 8).
            $this->logger->error('No se pudo encolar la extraccion OCR', [
                'document_public_id' => $document->publicId()->value,
                'reason' => $e->getMessage(),
            ]);

            throw ExternalServiceUnavailableException::forService(
                'ocr',
                'la cola de extraccion no acepta trabajos'
            );
        }

        $this->logger->info('Extraccion OCR encolada', [
            'queue' => $this->queueName,
            'bullmq_job_id' => $jobId,
            'document_public_id' => $document->publicId()->value,
            'attempt' => $document->ocrAttempts() + 1,
        ]);

        return $jobRef;
    }
}
