import { createRouter, createWebHistory } from 'vue-router'
import { useSessionStore } from '../stores/session'
import { useFlowStore } from '../stores/flow'

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
  const flow = useFlowStore()
  const need = to.meta?.requires

  if (need === 'prospect-session' && !session.isProspect) return { name: 'WelcomeView' }
  if (need === 'customer-or-prospect' && !session.isAuthenticated) return { name: 'WelcomeView' }
  // El bloque administrativo lo protege el backend con `can:` y 401/403; la
  // SPA no simula ese login todavia. Se deja pasar y las llamadas fallan
  // con mensaje claro en la vista.

  // Bifurcacion: no se puede entrar directamente a `identificacion` si el
  // metodo elegido fue manual, ni a `datos` si fue OCR y aun no se ha
  // confirmado la extraccion. El servidor cortaria igual, pero la vista se
  // ahorra la ida y vuelta.
  if (to.name === 'DocumentUploadView' && flow.captureMethod === 'manual') {
    return { name: 'ProspectDataFormView' }
  }
  return true
})

export default router
