import test from 'node:test';
import assert from 'node:assert/strict';
import { UnrecoverableError } from 'bullmq';
import { createNotificationProcessor } from '../src/processors/notificationProcessor.js';

function jobFor(data, attemptsMade = 0) {
  return { id: '1', attemptsMade, opts: { attempts: 5 }, data };
}

function recordingGateway() {
  const sent = [];

  return { sent, send: async (message) => void sent.push(message) };
}

test('la notificacion completa se entrega a la pasarela', async () => {
  const gateway = recordingGateway();
  const process = createNotificationProcessor({ gateway });

  const outcome = await process(jobFor({
    channel: 'email',
    recipient: 'prospecto@example.test',
    template_key: 'credit.approved',
    parameters: { amount: 25000 },
  }));

  assert.deepEqual(outcome, { delivered: true, attempt: 1 });
  assert.equal(gateway.sent.length, 1);
  assert.equal(gateway.sent[0].templateKey, 'credit.approved');
});

test('una notificacion sin destinatario no se reintenta', async () => {
  const gateway = recordingGateway();
  const process = createNotificationProcessor({ gateway });

  await assert.rejects(
    () => process(jobFor({ channel: 'email', template_key: 'credit.approved' })),
    UnrecoverableError,
  );

  assert.deepEqual(gateway.sent, []);
});
