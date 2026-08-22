// Adaptador de la pasarela de notificaciones.
//
// El destinatario NO se registra en ningun sitio: es un dato personal y la carga
// del trabajo se queda en Redis mientras el trabajo exista (regla 1).
import { config } from '../config.js';
import { logger } from '../logger.js';

function fakeGateway() {
  return {
    name: 'fake',
    async send({ channel, templateKey }) {
      logger.info('Notificacion entregada (simulada)', { channel, template_key: templateKey });

      return { delivered: true, provider: 'fake' };
    },
  };
}

function httpGateway() {
  return {
    name: 'http',
    async send({ channel, recipient, templateKey, parameters }) {
      const response = await fetch(config.notification.endpoint, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ channel, recipient, template_key: templateKey, parameters }),
        signal: AbortSignal.timeout(config.backend.timeoutMs),
      });

      if (!response.ok) {
        // Todo fallo de la pasarela es reintentable: no hay aqui un equivalente
        // al documento ilegible, y un aviso no entregado se vuelve a intentar.
        throw new Error(`La pasarela de notificaciones respondio ${response.status}.`);
      }

      return { delivered: true, provider: 'http' };
    },
  };
}

export function createNotificationGateway(adapter = config.notification.adapter) {
  return adapter === 'http' ? httpGateway() : fakeGateway();
}
