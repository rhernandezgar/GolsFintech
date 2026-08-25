import { defineStore } from 'pinia'

/**
 * Estado del recorrido del prospecto: por que paso va y las referencias
 * que arrastra (documento cargado, simulacion emitida, cliente creado). No
 * guarda datos personales -CURP, RFC, ingreso- ni la oferta calculada: eso
 * lo devuelve el servidor en cada llamada y ninguna decision se toma en
 * el navegador (RS-04).
 */
export const useFlowStore = defineStore('flow', {
  state: () => ({
    // Ruta elegida en P1: 'manual' (P2 -> P4) o 'ocr' (P3 -> P4).
    captureMethod: null,
    // P3: identificador del documento cargado. Se necesita al validar en P4.
    documentPublicId: null,
    // P5: identificador de la simulacion vigente. Se necesita al aceptar en P6.
    simulationPublicId: null,
    // P6: numero de cliente asignado tras la autorizacion.
    customerNumber: null,
  }),
  actions: {
    startCapture(method) {
      this.captureMethod = method
    },
    attachDocument(publicId) {
      this.documentPublicId = publicId
    },
    attachSimulation(publicId) {
      this.simulationPublicId = publicId
    },
    completeAsCustomer(customerNumber) {
      this.customerNumber = customerNumber
    },
    reset() {
      this.captureMethod = null
      this.documentPublicId = null
      this.simulationPublicId = null
      this.customerNumber = null
    },
  },
})
