// Lo que estas pruebas defienden es la decision central del worker: que error se
// reintenta y cual no. Sin Redis y sin proveedor: el procesador recibe dobles.
import test from 'node:test';
import assert from 'node:assert/strict';
import { UnrecoverableError } from 'bullmq';
import { createOcrProcessor } from '../src/processors/ocrProcessor.js';
import { createOcrProvider, scenarioOfJobRef } from '../src/adapters/ocrProvider.js';
import { ProviderTimeoutError, UnreadableDocumentError } from '../src/adapters/errors.js';

const FILE_HASH = 'a'.repeat(64);

function jobFor(jobRef, attemptsMade = 0) {
  return {
    id: '1',
    attemptsMade,
    opts: { attempts: 3 },
    data: {
      document_public_id: '11111111-2222-3333-4444-555555555555',
      storage_path: 'identity-documents/p1/ine.jpg',
      file_hash: FILE_HASH,
      job_ref: jobRef,
    },
  };
}

function recordingBackend() {
  const reports = [];

  return { reports, report: async (outcome) => void reports.push(outcome) };
}

test('el identificador del trabajo lleva el escenario, y solo con el formato acordado', () => {
  assert.equal(scenarioOfJobRef(`ocrsim-timeout-${'0'.repeat(16)}`), 'timeout');
  assert.equal(scenarioOfJobRef(`ocrsim-unreadable-${'f'.repeat(16)}`), 'unreadable');
  assert.equal(scenarioOfJobRef(`ocrsim-extracted-${'9'.repeat(16)}`), 'extracted');

  // Formatos que no son el contrato no se interpretan a la ligera.
  assert.equal(scenarioOfJobRef('ocrsim-timeout-corto'), null);
  assert.equal(scenarioOfJobRef('cualquier-cosa'), null);
  assert.equal(scenarioOfJobRef(undefined), null);
});

test('un timeout se relanza tal cual para que BullMQ lo reintente', async () => {
  const backend = recordingBackend();
  const process = createOcrProcessor({ provider: createOcrProvider('fake'), backend });

  await assert.rejects(
    () => process(jobFor(`ocrsim-timeout-${'1'.repeat(16)}`)),
    (error) => {
      // La distincion es exactamente esta: NO es un UnrecoverableError, asi que
      // BullMQ vuelve a intentarlo con el backoff exponencial del trabajo.
      assert.ok(error instanceof ProviderTimeoutError);
      assert.ok(!(error instanceof UnrecoverableError));

      return true;
    },
  );

  // No se avisa al backend en un fallo intermedio: eso lo decide index.js
  // cuando el fallo es definitivo, y avisar aqui duplicaria el reporte.
  assert.deepEqual(backend.reports, []);
});

test('un documento ilegible corta los reintentos en seco', async () => {
  const backend = recordingBackend();
  const process = createOcrProcessor({ provider: createOcrProvider('fake'), backend });

  await assert.rejects(
    () => process(jobFor(`ocrsim-unreadable-${'2'.repeat(16)}`)),
    (error) => {
      // UnrecoverableError es lo unico que hace que BullMQ no reintente.
      // Reintentar una foto borrosa gasta cuota y termina en el mismo sitio.
      assert.ok(error instanceof UnrecoverableError);

      return true;
    },
  );

  assert.deepEqual(backend.reports, []);
});

test('una extraccion correcta se reporta al backend con el numero de intento', async () => {
  const backend = recordingBackend();
  const process = createOcrProcessor({ provider: createOcrProvider('fake'), backend });

  // attemptsMade es 0 en el primer intento (comprobado contra bullmq 6.1.2):
  // con 1 aqui, el intento reportado tiene que ser el 2.
  const outcome = await process(jobFor(`ocrsim-extracted-${'3'.repeat(16)}`, 1));

  assert.deepEqual(outcome, { status: 'extracted', attempt: 2 });
  assert.equal(backend.reports.length, 1);
  assert.equal(backend.reports[0].status, 'extracted');
  assert.equal(backend.reports[0].attempt, 2);
  assert.equal(backend.reports[0].documentPublicId, '11111111-2222-3333-4444-555555555555');
});

test('una carga incompleta no se reintenta: es un defecto de quien encolo', async () => {
  const backend = recordingBackend();
  const process = createOcrProcessor({ provider: createOcrProvider('fake'), backend });
  const job = jobFor(`ocrsim-extracted-${'4'.repeat(16)}`);
  delete job.data.file_hash;

  await assert.rejects(() => process(job), UnrecoverableError);
});

test('el proveedor simulado es determinista: el mismo documento, el mismo desenlace', async () => {
  const provider = createOcrProvider('fake');
  const input = { documentPublicId: 'x', storagePath: 'p', fileHash: FILE_HASH, jobRef: `ocrsim-extracted-${'5'.repeat(16)}` };

  assert.deepEqual(await provider.extract(input), await provider.extract(input));
});

test('los dos errores del proveedor declaran si se reintentan', () => {
  assert.equal(new ProviderTimeoutError().retryable, true);
  assert.equal(new UnreadableDocumentError().retryable, false);
});
