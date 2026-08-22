// Cola de notificaciones (avisos al prospecto y al cliente).
//
// Mas intentos y mas separacion que el OCR: una pasarela de correo o de SMS
// suele restablecerse sola, y reintentar un aviso no cuesta lo que reprocesar
// una imagen. 2 s, 4 s, 8 s, 16 s. Coincide con
// config('adapters.notification.bullmq') del backend.
import { Queue } from 'bullmq';
import { config } from '../config.js';

export const NOTIFICATION_JOB_NAME = 'notification.send';

export const NOTIFICATION_JOB_OPTIONS = {
  attempts: 5,
  backoff: { type: 'exponential', delay: 2000 },
  removeOnFail: false,
  removeOnComplete: { age: 86400 },
};

export function createNotificationQueue(connection) {
  return new Queue(config.notification.queueName, {
    connection,
    prefix: config.prefix,
    defaultJobOptions: NOTIFICATION_JOB_OPTIONS,
  });
}
