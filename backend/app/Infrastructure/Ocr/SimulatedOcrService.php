<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\OcrService;
use Psr\Log\LoggerInterface;

/**
 * Adaptador de OCR simulado (RF-03).
 *
 * No abre ninguna conexion ni necesita credenciales: el entorno es de desarrollo.
 * Lo que hace es devolver un identificador de trabajo DETERMINISTA que ademas
 * lleva escrito el desenlace que debe simularse. Asi el worker de T7 puede probar
 * sus reintentos sin proveedor real y sin aleatoriedad: el mismo documento produce
 * siempre el mismo identificador y el mismo desenlace.
 *
 * Formato del identificador: ocrsim-<escenario>-<16 hex del hash del archivo>.
 *
 * Como se elige el escenario, en este orden:
 *   1. `adapters.ocr.simulated.force_scenario`, si esta definido (todo el entorno).
 *   2. Una marca reservada en la ruta del documento, p. ej. "sandbox-timeout".
 *   3. Extraccion correcta.
 *
 * `fail_enqueue` simula algo distinto: que la cola misma este caida. Ahi falla el
 * encolado y no llega a haber trabajo, que es otro camino de error del flujo.
 */
final readonly class SimulatedOcrService implements OcrService
{
    /** @param array<string, string> $scenarioMarkers escenario => marca en la ruta */
    public function __construct(
        private LoggerInterface $logger,
        private ?OcrScenario $forcedScenario = null,
        private array $scenarioMarkers = [],
        private bool $failEnqueue = false,
    ) {
    }

    public function enqueueExtraction(IdentityDocument $document): string
    {
        if ($this->failEnqueue) {
            throw ExternalServiceUnavailableException::forService(
                'ocr',
                'la cola de extraccion no acepta trabajos (simulado)'
            );
        }

        $scenario = $this->scenarioFor($document);
        $jobId = sprintf('ocrsim-%s-%s', $scenario->value, substr($document->fileHash(), 0, 16));

        // Sin datos personales: el documento se identifica por su id publico.
        $this->logger->info('OCR simulado: trabajo encolado', [
            'job_id' => $jobId,
            'document_public_id' => $document->publicId()->value,
            'scenario' => $scenario->value,
            'attempt' => $document->ocrAttempts() + 1,
        ]);

        return $jobId;
    }

    /** Escenario que corresponde a un documento, sin consultar nada externo. */
    public function scenarioFor(IdentityDocument $document): OcrScenario
    {
        if ($this->forcedScenario !== null) {
            return $this->forcedScenario;
        }

        $path = strtolower($document->storagePath());

        foreach ($this->scenarioMarkers as $scenario => $marker) {
            if ($marker !== '' && str_contains($path, strtolower($marker))) {
                return OcrScenario::from($scenario);
            }
        }

        return OcrScenario::Extracted;
    }

    /** Lee el escenario de un identificador de trabajo ya emitido. */
    public static function scenarioOfJobId(string $jobId): ?OcrScenario
    {
        if (preg_match('/^ocrsim-([a-z]+)-[0-9a-f]{16}$/', $jobId, $matches) !== 1) {
            return null;
        }

        return OcrScenario::tryFrom($matches[1]);
    }
}
