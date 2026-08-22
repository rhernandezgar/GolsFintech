// Cola de extraccion OCR.
//
// Quien encola es el backend (App\Infrastructure\Ocr\BullMqOcrService), no este
// worker: aqui solo se declara la cola para poder consultarla e imponer la misma
// politica de reintentos a cualquier trabajo que naciera de este lado.
//
// POLITICA DE REINTENTOS — tiene que coincidir con config('adapters.ocr.bullmq')
// del backend, y ademas con IdentityDocument::MAX_OCR_ATTEMPTS. Si BullMQ
// reintentara mas veces que las que el dominio admite, el documento acabaria en
// un estado que la entidad no acepta.
//
//   attempts 3   intentos TOTALES, incluido el primero
//   backoff      exponencial con base de 1 s: 1 s, 2 s, 4 s
//
// El backoff exponencial no es un adorno: un proveedor que se esta recuperando
// empeora si se le reintenta a ritmo fijo.
import { Queue } from 'bullmq';
import { config } from '../config.js';

export const OCR_JOB_NAME = 'ocr.extract';

export const OCR_JOB_OPTIONS = {
  attempts: 3,
  backoff: { type: 'exponential', delay: 1000 },

  // PT-03: el trabajo fallido NO se borra. Agotados los reintentos tiene que
  // quedar en la cola, con su carga intacta, para poder recuperar la solicitud
  // sin perder los datos del prospecto.
  removeOnFail: false,
  removeOnComplete: { age: 86400 },
};

export function createOcrQueue(connection) {
  return new Queue(config.ocr.queueName, {
    connection,
    prefix: config.prefix,
    defaultJobOptions: OCR_JOB_OPTIONS,
  });
}
