// Configuracion del worker. Se lee una sola vez al arrancar y se valida aqui:
// un worker mal configurado debe morir en el arranque, no descubrirlo al procesar
// el primer trabajo.
import 'dotenv/config';

function int(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const value = Number.parseInt(raw, 10);
  if (!Number.isInteger(value) || value < 0) {
    throw new Error(`${name} debe ser un entero no negativo; se recibio "${raw}"`);
  }
  return value;
}

function choice(name, fallback, allowed) {
  const value = process.env[name] ?? fallback;
  if (!allowed.includes(value)) {
    throw new Error(`${name} debe ser uno de ${allowed.join(' | ')}; se recibio "${value}"`);
  }
  return value;
}

export const config = {
  redisUrl: process.env.REDIS_URL ?? 'redis://127.0.0.1:6379',

  // TIENE que coincidir con config('adapters.bullmq.prefix') del backend. Si no
  // coincide, el backend escribe en un juego de claves que este worker no mira
  // nunca y los trabajos se pierden sin que nadie vea un error.
  prefix: process.env.BULLMQ_PREFIX ?? 'bull',

  ocr: {
    queueName: process.env.OCR_QUEUE_NAME ?? 'ocr',
    adapter: choice('OCR_ADAPTER', 'fake', ['fake', 'http']),
    endpoint: process.env.OCR_PROVIDER_URL ?? null,
    concurrency: int('OCR_CONCURRENCY', 2),
  },

  notification: {
    queueName: process.env.NOTIFICATION_QUEUE_NAME ?? 'notifications',
    adapter: choice('NOTIFICATION_ADAPTER', 'fake', ['fake', 'http']),
    endpoint: process.env.NOTIFICATION_GATEWAY_URL ?? null,
    concurrency: int('NOTIFICATION_CONCURRENCY', 4),
  },

  // Como devuelve el worker el resultado al backend. 'log' es el modo de
  // desarrollo: no llama a nadie y deja constancia en la salida.
  backend: {
    adapter: choice('BACKEND_ADAPTER', 'log', ['log', 'http']),
    baseUrl: process.env.BACKEND_BASE_URL ?? 'http://127.0.0.1:6060',
    tokenUrl: process.env.BACKEND_TOKEN_URL ?? null,
    clientId: process.env.BACKEND_CLIENT_ID ?? null,
    clientSecret: process.env.BACKEND_CLIENT_SECRET ?? null,
    timeoutMs: int('BACKEND_TIMEOUT_MS', 5000),
  },
};

export function assertUsableConfig(cfg = config) {
  if (cfg.ocr.adapter === 'http' && !cfg.ocr.endpoint) {
    throw new Error('OCR_ADAPTER=http exige OCR_PROVIDER_URL');
  }
  if (cfg.notification.adapter === 'http' && !cfg.notification.endpoint) {
    throw new Error('NOTIFICATION_ADAPTER=http exige NOTIFICATION_GATEWAY_URL');
  }
  if (cfg.backend.adapter === 'http') {
    // El worker se autentica con client_credentials contra el propio backend.
    // Sin credenciales no puede reportar nada, y un resultado que no llega es
    // un documento que se queda en 'processing' para siempre.
    for (const key of ['tokenUrl', 'clientId', 'clientSecret']) {
      if (!cfg.backend[key]) {
        throw new Error(`BACKEND_ADAPTER=http exige BACKEND_${key.replace(/[A-Z]/g, (c) => `_${c}`).toUpperCase()}`);
      }
    }
  }
  return cfg;
}
