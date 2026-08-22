// Consume UN trabajo de la cola indicada y describe por stdout lo que recibio.
//
// Existe para que una prueba de PHP pueda comprobar que lo que escribe
// BullMqQueue lo entiende la libreria de verdad. Vive aqui, y no en un archivo
// temporal, porque Node resuelve `bullmq` desde la ubicacion del script: fuera
// de worker/ no encontraria node_modules.
//
// Uso: node consumeOnce.mjs <cola> [prefijo] [msTiempoLimite]
import { Worker } from 'bullmq';

const [queueName, prefix = 'bull', timeoutMs = '10000'] = process.argv.slice(2);

if (!queueName) {
  console.log(JSON.stringify({ ok: false, error: 'falta el nombre de la cola' }));
  process.exit(1);
}

const connection = { host: process.env.REDIS_HOST ?? '127.0.0.1', port: Number(process.env.REDIS_PORT ?? 6379) };

let done = false;

const worker = new Worker(
  queueName,
  async (job) => {
    done = true;
    console.log(JSON.stringify({
      ok: true,
      id: job.id,
      name: job.name,
      data: job.data,
      attempts: job.opts.attempts ?? null,
      backoff: job.opts.backoff ?? null,
      removeOnFail: job.opts.removeOnFail ?? null,
    }));

    return 'ok';
  },
  { connection, prefix },
);

worker.on('completed', async () => {
  await worker.close();
  process.exit(0);
});

setTimeout(async () => {
  if (!done) {
    console.log(JSON.stringify({ ok: false, error: 'no llego ningun trabajo' }));
  }

  await worker.close();
  process.exit(done ? 0 : 1);
}, Number(timeoutMs));
