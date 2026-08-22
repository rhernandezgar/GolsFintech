import test from 'node:test';
import assert from 'node:assert/strict';

// La regla no negociable 1 tiene que sostenerse tambien en la salida del worker:
// una cola y un registro son almacenamiento persistente igual que una tabla.
test('el registro no deja pasar datos personales', async () => {
  const written = [];
  const original = process.stdout.write.bind(process.stdout);
  process.stdout.write = (chunk) => (written.push(String(chunk)), true);

  try {
    // Se importa con el silencio desactivado para poder leer lo que escribe.
    delete process.env.WORKER_LOG_SILENT;
    const { logger } = await import(`../src/logger.js?fresh=${Date.now()}`);
    logger.info('prueba', {
      curp: 'GOMR900101HDFXXX01',
      rfc: 'GOMR900101ABC',
      recipient: 'persona@example.test',
      card_number: '4111111111111111',
      document_public_id: 'ok-visible',
    });
  } finally {
    process.stdout.write = original;
  }

  const line = written.join('');

  for (const secret of ['GOMR900101HDFXXX01', 'GOMR900101ABC', 'persona@example.test', '4111111111111111']) {
    assert.ok(!line.includes(secret), `el registro filtro ${secret}`);
  }

  assert.ok(line.includes('ok-visible'), 'el identificador publico si debe verse');
  assert.ok(line.includes('[redacted]'));
});
