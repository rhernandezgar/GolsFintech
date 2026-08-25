import axios from 'axios'
import { useSessionStore } from '../stores/session'

/**
 * Instancia de axios contra la API v1. Un interceptor de peticion pone la
 * cabecera Authorization desde el store; otro de respuesta, en 401, cierra
 * la sesion y redirige a la bienvenida —el token pudo haber sido revocado
 * del lado del servidor por promocion a cliente o por caducidad—.
 */
const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: { Accept: 'application/json' },
})

client.interceptors.request.use((config) => {
  const session = useSessionStore()
  // No se pisa una cabecera puesta por quien hace la llamada: P7 consulta con
  // una credencial administrativa que no es la sesion del recorrido, y sin
  // esta condicion el interceptor la sustituiria por el token del prospecto.
  if (session.accessToken && !config.headers.Authorization) {
    config.headers.Authorization = `Bearer ${session.accessToken}`
  }
  return config
})

client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      const session = useSessionStore()
      session.close()
    }
    return Promise.reject(error)
  },
)

/**
 * Renovacion silenciosa del token del prospecto mientras haya actividad.
 * Se llama desde `useSilentRenewal` y desde puntos concretos (P3 en bucle).
 * En P6 el token cambia por otra via -promoteToCustomer del servidor-, asi
 * que este renovador solo aplica al alcance `prospect-session`.
 */
export async function renewProspectSession() {
  const session = useSessionStore()
  if (!session.isProspect || !session.shouldRenew) return
  const { data } = await client.post('/prospects/me/session')
  session.applySession(data.data.session)
}

export default client
