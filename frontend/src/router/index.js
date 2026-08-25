import { createRouter, createWebHistory } from 'vue-router'
import { useSessionStore } from '../stores/session'

import WelcomeView from '../views/WelcomeView.vue'
import ProspectDataFormView from '../views/ProspectDataFormView.vue'
import DocumentUploadView from '../views/DocumentUploadView.vue'
import VerificationResultView from '../views/VerificationResultView.vue'
import CreditSimulationView from '../views/CreditSimulationView.vue'
import AuthorizationConfirmedView from '../views/AuthorizationConfirmedView.vue'
import CustomerLookupView from '../views/CustomerLookupView.vue'

/**
 * El recorrido tiene una bifurcacion (Fase 1): desde WelcomeView se elige
 * `manual` -> ProspectDataFormView -> VerificationResultView, o `ocr` ->
 * DocumentUploadView -> ProspectDataFormView (para confirmar) ->
 * VerificationResultView. Las dos ramas convergen antes de la validacion
 * de identidad, y el router no las restringe: la SPA propone y el servidor
 * decide (RS-04).
 *
 * `meta.requires` declara la precondicion:
 *   - `prospect-session` exige token de prospecto emitido en P1.
 *   - `customer-session` exige token de cliente (tras P6).
 *   - `admin` exige que el usuario haya iniciado sesion con rol; la
 *     comprobacion real la impone el backend (`can:customer.view.any`).
 */
const routes = [
  { path: '/', name: 'WelcomeView', component: WelcomeView, meta: { step: 0 } },
  { path: '/datos', name: 'ProspectDataFormView', component: ProspectDataFormView,
    meta: { step: 1, requires: 'prospect-session' } },
  { path: '/identificacion', name: 'DocumentUploadView', component: DocumentUploadView,
    meta: { step: 2, requires: 'prospect-session' } },
  { path: '/verificacion', name: 'VerificationResultView', component: VerificationResultView,
    meta: { step: 3, requires: 'prospect-session' } },
  { path: '/simulacion', name: 'CreditSimulationView', component: CreditSimulationView,
    meta: { step: 4, requires: 'prospect-session' } },
  { path: '/autorizacion', name: 'AuthorizationConfirmedView', component: AuthorizationConfirmedView,
    meta: { step: 5, requires: 'customer-or-prospect' } },
  { path: '/clientes', name: 'CustomerLookupView', component: CustomerLookupView,
    meta: { requires: 'admin' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const session = useSessionStore()
  const need = to.meta?.requires

  if (need === 'prospect-session' && !session.isProspect) return { name: 'WelcomeView' }
  if (need === 'customer-or-prospect' && !session.isAuthenticated) return { name: 'WelcomeView' }
  // El bloque administrativo lo protege el backend con `can:` y 401/403; la
  // SPA no simula ese login todavia. Se deja pasar y las llamadas fallan
  // con mensaje claro en la vista.

  // NO se bloquea `identificacion` en la rama manual, aunque la primera
  // version de este guard lo hacia. La premisa era que "manual" significaba
  // "sin documento", y es falsa: lo que elige P1 es como se CAPTURAN los
  // datos, no si hace falta identificacion. El veredicto "verificado" de P4
  // exige documento —sin uno, la vigencia del documento no se puede evaluar y
  // el conjunto queda pendiente—, asi que la rama manual tambien pasa por P3.
  // Con el bloqueo, quien elegia captura manual no podia llegar nunca a P5.
  return true
})

export default router
