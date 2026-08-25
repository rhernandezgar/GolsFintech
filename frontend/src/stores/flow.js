import { defineStore } from 'pinia'

const STORAGE_KEY = 'gols.flow'

/**
 * Estado del recorrido del prospecto: por que paso va y las referencias
 * que arrastra (documento cargado, simulacion emitida, cliente creado). No
 * guarda datos personales -CURP, RFC, ingreso- ni la oferta calculada: eso
 * lo devuelve el servidor en cada llamada y ninguna decision se toma en
 * el navegador (RS-04).
 *
 * Se persiste en `sessionStorage`, igual que la sesion y por el mismo motivo:
 * un F5 a mitad del tramite no deberia mandar al prospecto de vuelta al
 * principio, y se cierra con la pestana. Lo que se guarda son identificadores
 * publicos y la vista del cliente que el servidor ya devolvio, nunca datos que
 * no hubieran viajado igualmente en la respuesta.
 */
function restore() {
  try {
    const saved = sessionStorage.getItem(STORAGE_KEY)
    return saved ? JSON.parse(saved) : null
  } catch {
    return null
  }
}

export const useFlowStore = defineStore('flow', {
  state: () => {
    const parsed = restore()
    return {
      // Ruta elegida en P1: 'manual' (P2 -> P4) o 'ocr' (P3 -> P4).
      captureMethod: parsed?.captureMethod ?? null,
      // P3: identificador del documento cargado. Se necesita al validar en P4.
      documentPublicId: parsed?.documentPublicId ?? null,
      // P5: identificador de la simulacion vigente. Se necesita al aceptar en P6.
      simulationPublicId: parsed?.simulationPublicId ?? null,
      // P6: numero de cliente asignado tras la autorizacion.
      customerNumber: parsed?.customerNumber ?? null,
      // P6: la vista del cliente tal como la devolvio el servidor al aceptar.
      // P6 la pinta; no se recalcula nada a partir de ella.
      customer: parsed?.customer ?? null,
    }
  },
  actions: {
    startCapture(method) {
      this.captureMethod = method
      this.persist()
    },
    attachDocument(publicId) {
      this.documentPublicId = publicId
      this.persist()
    },
    attachSimulation(publicId) {
      this.simulationPublicId = publicId
      this.persist()
    },
    /**
     * Se llama con la respuesta de P6. Guarda la vista del cliente para que
     * la pantalla de confirmacion sobreviva a una recarga: repetir la
     * aceptacion no la recuperaria —el servidor responde
     * CREDIT_SIMULATION_ALREADY_DECIDED, que es justo el control que impide
     * que un doble click cree dos clientes—.
     */
    completeAsCustomer(customer) {
      this.customerNumber = customer?.customer_number ?? null
      this.customer = customer ?? null
      this.persist()
    },
    reset() {
      this.captureMethod = null
      this.documentPublicId = null
      this.simulationPublicId = null
      this.customerNumber = null
      this.customer = null
      sessionStorage.removeItem(STORAGE_KEY)
    },
    persist() {
      try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
          captureMethod: this.captureMethod,
          documentPublicId: this.documentPublicId,
          simulationPublicId: this.simulationPublicId,
          customerNumber: this.customerNumber,
          customer: this.customer,
        }))
      } catch {
        // Sin almacenamiento -modo privado, cuota llena- el recorrido sigue
        // funcionando dentro de la misma carga de pagina.
      }
    },
  },
})
