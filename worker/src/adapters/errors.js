// Los dos desenlaces de error del proveedor de OCR, que NO se tratan igual.
//
// La distincion es la razon de ser de estas dos clases: reintentar tiene sentido
// cuando el fallo es del momento (el proveedor no contesto), y no lo tiene
// cuando el fallo es del insumo (la imagen no se puede leer). Reintentar tres
// veces una foto borrosa gasta cuota, retrasa la respuesta al prospecto y
// termina en el mismo sitio.
//
// Quien convierte esto en politica es processors/ocrProcessor.js: el ilegible se
// traduce a UnrecoverableError de BullMQ, que corta los reintentos en seco.

/** Fallo transitorio: el proveedor no respondio a tiempo. SE REINTENTA. */
export class ProviderTimeoutError extends Error {
  constructor(message = 'El proveedor de OCR no respondio a tiempo.') {
    super(message);
    this.name = 'ProviderTimeoutError';
    this.retryable = true;
  }
}

/** Fallo definitivo: el proveedor respondio, pero la imagen no es legible. NO se reintenta. */
export class UnreadableDocumentError extends Error {
  constructor(message = 'El documento no es legible.') {
    super(message);
    this.name = 'UnreadableDocumentError';
    this.retryable = false;
  }
}
