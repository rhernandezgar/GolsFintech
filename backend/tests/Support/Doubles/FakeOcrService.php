<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\OcrService;
use App\Infrastructure\Ocr\OcrScenario;

/**
 * Doble programable del puerto de OCR.
 *
 * Existe para el camino de error, no solo para el feliz: `willTimeOut()` es lo que
 * permite a T7 probar que el worker reintenta, y `willFailToEnqueue()` cubre el
 * caso distinto de que la cola misma este caida. Un doble que solo supiera
 * responder que si dejaria sin probar justo la mitad que rompe en produccion.
 *
 * El identificador de trabajo respeta el mismo formato que el adaptador simulado,
 * de modo que lo que una prueba afirme aqui siga siendo cierto contra el adaptador
 * de verdad.
 */
final class FakeOcrService implements OcrService
{
    /** @var list<IdentityDocument> */
    private array $enqueued = [];

    private OcrScenario $scenario = OcrScenario::Extracted;

    private bool $failEnqueue = false;

    public function enqueueExtraction(IdentityDocument $document): string
    {
        if ($this->failEnqueue) {
            throw ExternalServiceUnavailableException::forService('ocr', 'cola no disponible (doble de prueba)');
        }

        $this->enqueued[] = $document;

        return sprintf('ocrsim-%s-%s', $this->scenario->value, substr($document->fileHash(), 0, 16));
    }

    public function willExtract(): self
    {
        $this->scenario = OcrScenario::Extracted;

        return $this;
    }

    /** El proveedor no responde a tiempo: el trabajo debe reintentarse. */
    public function willTimeOut(): self
    {
        $this->scenario = OcrScenario::Timeout;

        return $this;
    }

    /** El documento no es legible: reintentar no lo arregla. */
    public function willBeUnreadable(): self
    {
        $this->scenario = OcrScenario::Unreadable;

        return $this;
    }

    public function willFailToEnqueue(): self
    {
        $this->failEnqueue = true;

        return $this;
    }

    /** @return list<IdentityDocument> */
    public function enqueuedDocuments(): array
    {
        return $this->enqueued;
    }

    public function enqueuedCount(): int
    {
        return count($this->enqueued);
    }
}
