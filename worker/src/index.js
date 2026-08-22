// Punto de entrada del worker asincrono de GolsFintech.
//
// El worker NO EXPONE NINGUN ENDPOINT HTTP: solo consume de Redis y llama al
// backend para devolver resultados (Fase 2 §d). No es una omision que se pueda
// corregir "cuando haga falta": abrir un puerto aqui seria una segunda
// superficie de ataque, sin la autenticacion ni el registro de auditoria que
// tiene la API.
import { Worker } from 'bullmq';
import { assertUsableConfig, config } from './config.js';
import { createConnection } from './redis.js';
import { logger } from './logger.js';
import { createOcrQueue } from './queues/ocrQueue.js';
import { createNotificationQueue } from './queues/notificationQueue.js';
import { createOcrProcessor } from './processors/ocrProcessor.js';
import { createNotificationProcessor } from './processors/notificationProcessor.js';
import { createOcrProvider } from './adapters/ocrProvider.js';
import { createNotificationGateway } from './adapters/notificationGateway.js';
import { createBackendClient } from './adapters/backendClient.js';

assertUsableConfig();

const connection = createConnection();
const backend = createBackendClient();

const ocrQueue = createOcrQueue(connection);
const notificationQueue = createNotificationQueue(connection);

const ocrWorker = new Worker(
  config.ocr.queueName,
  createOcrProcessor({ provider: createOcrProvider(), backend }),
  { connection, prefix: config.prefix, concurrency: config.ocr.concurrency },
);

const notificationWorker = new Worker(
  config.notification.queueName,
  createNotificationProcessor({ gateway: createNotificationGateway() }),
  { connection, prefix: config.prefix, concurrency: config.notification.concurrency },
);

/**
 * PT-03 — agotados los reintentos, la solicitud no puede perder los datos del
 * prospecto.
 *
 * Dos cosas la sostienen, y hacen falta las dos:
 *
 *  1. El trabajo se queda en la cola. `removeOnFail: false` viaja en las
 *     opciones de cada trabajo, asi que la carga sigue en Redis y se puede
 *     reencolar tal cual.
 *  2. El backend se entera. Sin este aviso el documento se quedaria en
 *     'processing' para siempre, que es peor que fallar: nadie sabria que hay
 *     algo que recuperar.
 *
 * `job.finishedOn` distingue el fallo definitivo del intermedio; se comprobo
 * contra bullmq 6.1.2. En los reintentos intermedios queda sin fijar y aqui no
 * se avisa de nada.
 */
ocrWorker.on('failed', (job, error) => {
  if (job === undefined) {
    logger.error('Trabajo de OCR fallido sin datos del trabajo', { reason: error?.message });

    return;
  }

  const definitive = job.finishedOn !== undefined && job.finishedOn !== null;

  if (!definitive) {
    logger.warn('Intento de OCR fallido; quedan reintentos', {
      job_id: job.id,
      attempt: job.attemptsMade,
      max_attempts: job.opts.attempts,
      reason: error?.message,
    });

    return;
  }

  const reason = error?.name === 'UnrecoverableError' ? 'unreadable' : 'exhausted';

  logger.error('Extraccion OCR fallida de forma definitiva', {
    job_id: job.id,
    document_public_id: job.data?.document_public_id,
    attempts_made: job.attemptsMade,
    reason,
    // El trabajo permanece en la cola con su carga intacta: se puede reencolar
    // sin volver a pedirle nada al prospecto.
    retained_in_queue: true,
  });

  backend
    .report({
      documentPublicId: job.data?.document_public_id,
      jobRef: job.data?.job_ref,
      status: 'failed',
      attempt: job.attemptsMade,
      reason,
    })
    .catch((reportError) => {
      // Si ni siquiera se puede avisar, se deja constancia y el trabajo sigue en
      // la cola: la recuperacion pasa a ser manual, pero nada se pierde.
      logger.error('No se pudo avisar al backend del fallo definitivo', {
        job_id: job.id,
        reason: reportError.message,
      });
    });
});

notificationWorker.on('failed', (job, error) => {
  if (job === undefined || job.finishedOn === undefined || job.finishedOn === null) {
    return;
  }

  logger.error('Notificacion no entregada tras agotar los reintentos', {
    job_id: job.id,
    channel: job.data?.channel,
    template_key: job.data?.template_key,
    attempts_made: job.attemptsMade,
    reason: error?.message,
  });
});

for (const worker of [ocrWorker, notificationWorker]) {
  worker.on('error', (error) => logger.error('Error del worker', { reason: error.message }));
}

const counts = await ocrQueue.getJobCounts('wait', 'active', 'failed');

logger.info('Worker en marcha', {
  node: process.version,
  prefix: config.prefix,
  ocr_queue: config.ocr.queueName,
  ocr_adapter: config.ocr.adapter,
  notification_queue: config.notification.queueName,
  notification_adapter: config.notification.adapter,
  backend_adapter: config.backend.adapter,
  ocr_backlog: counts,
});

// Cierre ordenado: `close()` espera a que terminen los trabajos en curso en vez
// de abandonarlos a medias, que los dejaria bloqueados hasta que expire su
// cerrojo.
let shuttingDown = false;

async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;

  logger.info('Cerrando el worker', { signal });

  await Promise.allSettled([
    ocrWorker.close(),
    notificationWorker.close(),
    ocrQueue.close(),
    notificationQueue.close(),
  ]);

  await connection.quit().catch(() => {});
  process.exit(0);
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
