// Procesador de la cola de notificaciones.
//
// Aqui no hay distincion reintentable/definitivo como en el OCR: una pasarela
// que falla suele restablecerse, y el trabajo lleva attempts 5 con backoff
// exponencial de 2 s. La unica excepcion es una carga incompleta, que no mejora
// por reintentarse.
import { UnrecoverableError } from 'bullmq';
import { logger } from '../logger.js';

export function createNotificationProcessor({ gateway }) {
  return async function processNotificationJob(job) {
    const {
      channel,
      recipient,
      template_key: templateKey,
      parameters = {},
    } = job.data ?? {};

    if (!channel || !recipient || !templateKey) {
      throw new UnrecoverableError('La carga de la notificacion esta incompleta.');
    }

    const attempt = job.attemptsMade + 1;

    // Ni el destinatario ni los parametros entran en el registro (regla 1).
    logger.info('Enviando notificacion', {
      job_id: job.id,
      channel,
      template_key: templateKey,
      attempt,
      max_attempts: job.opts.attempts,
    });

    await gateway.send({ channel, recipient, templateKey, parameters });

    return { delivered: true, attempt };
  };
}
