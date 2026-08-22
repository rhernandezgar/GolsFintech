// Registro estructurado en una linea por evento.
//
// REGLA NO NEGOCIABLE 1: aqui no se escribe ni CURP, ni RFC, ni numero de
// tarjeta, ni el destinatario de una notificacion. Los trabajos se identifican
// por su identificador publico, que no dice nada de la persona.
const REDACTED = new Set([
  'curp', 'rfc', 'card_number', 'pan', 'recipient', 'email', 'phone',
  'full_name', 'nombre', 'parameters',
]);

function safe(context) {
  return Object.fromEntries(
    Object.entries(context ?? {}).map(([key, value]) => [key, REDACTED.has(key) ? '[redacted]' : value]),
  );
}

// Las pruebas silencian la salida: lo que se comprueba ahi es el comportamiento
// del procesador, no el ruido del registro.
const silent = process.env.WORKER_LOG_SILENT === '1';

function emit(level, message, context) {
  if (silent) return;

  const line = { ts: new Date().toISOString(), level, message, ...safe(context) };
  const stream = level === 'error' ? process.stderr : process.stdout;
  stream.write(`${JSON.stringify(line)}\n`);
}

export const logger = {
  info: (message, context) => emit('info', message, context),
  warn: (message, context) => emit('warn', message, context),
  error: (message, context) => emit('error', message, context),
};
