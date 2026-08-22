// Adaptador del proveedor de OCR.
//
// Dos implementaciones tras la misma interfaz (`extract`): la falsa, que es la
// que corre hoy, y la HTTP, para cuando exista convenio con un proveedor. El
// procesador no sabe cual tiene enchufada.
import { config } from '../config.js';
import { ProviderTimeoutError, UnreadableDocumentError } from './errors.js';

// El escenario a simular viaja dentro del identificador del trabajo, con el
// formato que emite el backend: ocrsim-<escenario>-<16 hex>. Es el contrato
// entre los dos procesos, y existe para que el worker pueda simular sin
// consultar la base ni compartir configuracion con el backend.
const JOB_REF_PATTERN = /^ocrsim-([a-z]+)-[0-9a-f]{16}$/;

export function scenarioOfJobRef(jobRef) {
  const match = JOB_REF_PATTERN.exec(String(jobRef ?? ''));

  return match === null ? null : match[1];
}

/**
 * Proveedor simulado y DETERMINISTA: el mismo documento produce siempre el mismo
 * desenlace. Sin aleatoriedad no habria forma de probar los reintentos.
 */
function fakeProvider() {
  return {
    name: 'fake',
    async extract({ jobRef, fileHash }) {
      switch (scenarioOfJobRef(jobRef)) {
        case 'timeout':
          throw new ProviderTimeoutError();

        case 'unreadable':
          throw new UnreadableDocumentError();

        case 'extracted':
        default:
          // Datos de relleno con la forma que espera el backend. No son datos de
          // una persona real: salen del hash del archivo.
          return {
            provider: 'fake',
            confidence: 0.97,
            fields: {
              full_name: 'NOMBRE DE PRUEBA',
              birth_date: '1990-01-01',
              document_number: fileHash.slice(0, 12).toUpperCase(),
            },
          };
      }
    },
  };
}

/**
 * Proveedor real por HTTP. No hay convenio todavia; queda escrito para que
 * cambiar de proveedor sea configuracion y no un rediseno del worker.
 */
function httpProvider() {
  return {
    name: 'http',
    async extract({ documentPublicId, storagePath }) {
      const response = await fetch(config.ocr.endpoint, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ document_public_id: documentPublicId, storage_path: storagePath }),
        signal: AbortSignal.timeout(config.backend.timeoutMs),
      });

      // 422 es el proveedor diciendo que la imagen no sirve: definitivo.
      if (response.status === 422) {
        throw new UnreadableDocumentError();
      }

      // 408 y 5xx son del momento: se reintenta.
      if (!response.ok) {
        throw new ProviderTimeoutError(`El proveedor respondio ${response.status}.`);
      }

      return await response.json();
    },
  };
}

export function createOcrProvider(adapter = config.ocr.adapter) {
  return adapter === 'http' ? httpProvider() : fakeProvider();
}
