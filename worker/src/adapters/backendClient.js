// Devuelve al backend el resultado de la extraccion.
//
// El worker no expone ningun endpoint: solo consume de la cola y LLAMA al
// backend. La direccion importa —un worker con puerto abierto seria una segunda
// superficie de ataque sin autenticacion propia— y por eso aqui solo hay cliente.
//
// En modo 'http' se autentica con client_credentials contra el propio backend y
// reutiliza el token hasta poco antes de que expire: pedir uno por trabajo
// multiplicaria por dos las llamadas sin ganar nada.
import { config } from '../config.js';
import { logger } from '../logger.js';

const TOKEN_SAFETY_MARGIN_MS = 30_000;

function logClient() {
  return {
    name: 'log',
    async report(outcome) {
      logger.info('Resultado de OCR (modo log, no se envia al backend)', {
        document_public_id: outcome.documentPublicId,
        status: outcome.status,
        attempt: outcome.attempt,
        reason: outcome.reason,
      });
    },
  };
}

function httpClient() {
  let cachedToken = null;
  let expiresAt = 0;

  async function token() {
    if (cachedToken !== null && Date.now() < expiresAt - TOKEN_SAFETY_MARGIN_MS) {
      return cachedToken;
    }

    const response = await fetch(config.backend.tokenUrl, {
      method: 'POST',
      headers: { 'content-type': 'application/json', accept: 'application/json' },
      body: JSON.stringify({
        grant_type: 'client_credentials',
        client_id: config.backend.clientId,
        client_secret: config.backend.clientSecret,
        scope: 'ocr-result',
      }),
      signal: AbortSignal.timeout(config.backend.timeoutMs),
    });

    if (!response.ok) {
      throw new Error(`No se pudo obtener el token del backend (${response.status}).`);
    }

    const body = await response.json();
    cachedToken = body.access_token;
    expiresAt = Date.now() + Number(body.expires_in ?? 0) * 1000;

    return cachedToken;
  }

  return {
    name: 'http',
    async report(outcome) {
      const response = await fetch(`${config.backend.baseUrl}/api/v1/internal/ocr-results`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          accept: 'application/json',
          authorization: `Bearer ${await token()}`,
        },
        body: JSON.stringify({
          document_public_id: outcome.documentPublicId,
          job_ref: outcome.jobRef,
          status: outcome.status,
          attempt: outcome.attempt,
          result: outcome.result ?? null,
          reason: outcome.reason ?? null,
        }),
        signal: AbortSignal.timeout(config.backend.timeoutMs),
      });

      if (response.status === 401) {
        // El token caduco antes de lo previsto: se descarta el cacheado para que
        // el siguiente intento pida uno nuevo en vez de reintentar con el malo.
        cachedToken = null;
      }

      if (!response.ok) {
        throw new Error(`El backend rechazo el resultado (${response.status}).`);
      }
    },
  };
}

export function createBackendClient(adapter = config.backend.adapter) {
  return adapter === 'http' ? httpClient() : logClient();
}
