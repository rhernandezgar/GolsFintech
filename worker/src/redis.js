// Conexion compartida con Redis.
import IORedis from 'ioredis';
import { config } from './config.js';

// maxRetriesPerRequest debe ser null: BullMQ lo exige para las ordenes
// bloqueantes con las que el worker espera trabajos.
export function createConnection() {
  return new IORedis(config.redisUrl, { maxRetriesPerRequest: null });
}
