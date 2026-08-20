// Punto de entrada del worker asincrono de GolsFintech.
// T1 (scaffold): solo verifica la configuracion y queda listo para que T7 registre
// las colas de OCR y notificaciones sobre BullMQ.
import 'dotenv/config';

const redisUrl = process.env.REDIS_URL ?? 'redis://127.0.0.1:6379';

console.log(`[worker] GolsFintech worker · Node ${process.version}`);
console.log(`[worker] Redis configurado en ${redisUrl}`);
console.log('[worker] Scaffold sin colas registradas todavia (pendiente T7).');
