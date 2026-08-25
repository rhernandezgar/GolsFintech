import { defineStore } from 'pinia'

const STORAGE_KEY = 'gols.session'

/**
 * Sesion del prospecto (P1) y del cliente (tras P6). El token viaja en
 * memoria y se persiste en `sessionStorage` para sobrevivir a un F5, no en
 * localStorage: se cierra con la pestana. La SPA lo pone en la cabecera
 * Authorization; nada de exponerlo en la URL ni en un formulario oculto.
 *
 * La transicion de token es la quinta precision del bloque 1: en P1 se
 * emite el de prospecto; al aceptar en P6, la respuesta trae el nuevo
 * token de cliente y este store lo sustituye SIN cortar la experiencia
 * (el interceptor de axios lo recoge en la siguiente peticion).
 */
export const useSessionStore = defineStore('session', {
  state: () => {
    const saved = sessionStorage.getItem(STORAGE_KEY)
    const parsed = saved ? JSON.parse(saved) : null
    return {
      accessToken: parsed?.accessToken ?? null,
      scope: parsed?.scope ?? null,
      expiresAt: parsed?.expiresAt ?? null,
      renewBeforeSeconds: parsed?.renewBeforeSeconds ?? 0,
      // El expediente que P1 abre. Es la referencia del prospecto para las
      // pantallas siguientes; nunca viaja en la URL de un endpoint.
      trackingId: parsed?.trackingId ?? null,
    }
  },
  getters: {
    isAuthenticated: (state) => Boolean(state.accessToken),
    isCustomer: (state) => state.scope === 'customer-session',
    isProspect: (state) => state.scope === 'prospect-session',
    /** Segundos restantes de vigencia, negativo si ya caduco. */
    secondsToExpiry: (state) => {
      if (!state.expiresAt) return 0
      return Math.floor((new Date(state.expiresAt).getTime() - Date.now()) / 1000)
    },
    shouldRenew() {
      return this.isAuthenticated && this.secondsToExpiry > 0 && this.secondsToExpiry <= this.renewBeforeSeconds
    },
  },
  actions: {
    openProspectSession({ trackingId, session }) {
      this.trackingId = trackingId
      this.applySession(session)
    },
    /**
     * Se llama tras aceptar la simulacion en P6. El token de prospecto
     * queda revocado del lado del servidor (promoteToCustomer); aqui lo
     * reemplazamos por el de cliente sin desloguear al usuario.
     */
    promoteToCustomer(session) {
      this.applySession(session)
    },
    applySession(session) {
      this.accessToken = session.access_token
      this.scope = session.scope
      this.expiresAt = session.expires_at
      this.renewBeforeSeconds = session.renew_before ?? 0
      this.persist()
    },
    close() {
      this.accessToken = null
      this.scope = null
      this.expiresAt = null
      this.renewBeforeSeconds = 0
      this.trackingId = null
      sessionStorage.removeItem(STORAGE_KEY)
    },
    persist() {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        accessToken: this.accessToken,
        scope: this.scope,
        expiresAt: this.expiresAt,
        renewBeforeSeconds: this.renewBeforeSeconds,
        trackingId: this.trackingId,
      }))
    },
  },
})
