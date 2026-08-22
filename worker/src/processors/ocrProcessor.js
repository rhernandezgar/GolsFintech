// Procesador de la cola de OCR.
//
// LO QUE DECIDE ESTE ARCHIVO: que error se reintenta y cual no.
//
//   timeout     -> se relanza tal cual. BullMQ lo reintenta con el backoff
//                  exponencial declarado en el trabajo (1 s, 2 s, 4 s).
//   unreadable  -> se traduce a UnrecoverableError, que corta los reintentos en
//                  seco: el trabajo pasa a 'failed' en el primer intento.
//
// Se comprobo observando a BullMQ 6.1.2, no suponiendolo: con
// UnrecoverableError el trabajo falla una sola vez aunque attempts sea 3, y
// `job.finishedOn` queda fijado justo en el fallo definitivo —y no en los
// intermedios—, que es lo que usa index.js para saber cuando avisar al backend.
//
// El resultado correcto SI se reporta desde aqui. El fallo NO: lo reporta el
// manejador de 'failed' de index.js, y solo cuando es definitivo. Repartirlo asi
// evita el error de avisar dos veces del mismo trabajo.
import { UnrecoverableError } from 'bullmq';
import { UnreadableDocumentError } from '../adapters/errors.js';
import { logger } from '../logger.js';

export function createOcrProcessor({ provider, backend }) {
  return async function processOcrJob(job) {
    const {
      document_public_id: documentPublicId,
      storage_path: storagePath,
      file_hash: fileHash,
      job_ref: jobRef,
    } = job.data ?? {};

    // Una carga incompleta no mejora por reintentarse: es un defecto de quien
    // encolo, no una indisponibilidad.
    if (!documentPublicId || !storagePath || !fileHash) {
      throw new UnrecoverableError('La carga del trabajo esta incompleta.');
    }

    // attemptsMade es 0 en el primer intento (comprobado en bullmq 6.1.2).
    const attempt = job.attemptsMade + 1;

    logger.info('Extraccion OCR en curso', {
      job_id: job.id,
      document_public_id: documentPublicId,
      attempt,
      max_attempts: job.opts.attempts,
    });

    let result;

    try {
      result = await provider.extract({ documentPublicId, storagePath, fileHash, jobRef });
    } catch (error) {
      if (error instanceof UnreadableDocumentError) {
        logger.warn('Documento ilegible: no se reintenta', {
          job_id: job.id,
          document_public_id: documentPublicId,
          attempt,
        });

        throw new UnrecoverableError(error.message);
      }

      // Reintentable. Se relanza para que BullMQ aplique el backoff.
      logger.warn('Fallo transitorio del proveedor de OCR: se reintentara', {
        job_id: job.id,
        document_public_id: documentPublicId,
        attempt,
        reason: error.message,
      });

      throw error;
    }

    await backend.report({ documentPublicId, jobRef, status: 'extracted', attempt, result });

    logger.info('Extraccion OCR completada', {
      job_id: job.id,
      document_public_id: documentPublicId,
      attempt,
    });

    return { status: 'extracted', attempt };
  };
}
