<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import api from '../composables/useApi'
import { useFlowStore } from '../stores/flow'
import { useSessionStore } from '../stores/session'
import AlertMessage from '../components/AlertMessage.vue'

/**
 * P5: simulacion del credito.
 *
 * NINGUNA CIFRA DE ESTA PANTALLA SE CALCULA AQUI. El tipo de credito, la
 * capacidad de pago, el monto propuesto, la tasa, el CAT, la mensualidad y el
 * total los decide el motor de reglas en el servidor; la vista los muestra tal
 * como llegan. Lo unico que el navegador envia es el plazo, y el servidor lo
 * vuelve a validar contra su catalogo autorizado.
 *
 * Por eso no hay aqui ninguna formula de amortizacion, ningun redondeo y
 * ninguna regla de elegibilidad: replicarlas en el navegador crearia una
 * segunda fuente de verdad que se desincroniza en cuanto la politica cambie, y
 * un usuario podria ver condiciones que el servidor no reconoce. Los importes
 * viajan como cadenas decimales y se pintan como cadenas: convertirlos a
 * numero de JavaScript introduciria error de coma flotante en dinero.
 *
 * EL PLAZO ES UNA AYUDA DE INTERFAZ, NO UNA REGLA. El desplegable ofrece el
 * catalogo para que el prospecto no adivine, pero quien decide si un plazo es
 * admisible es `SimulateCreditRequest` y, detras, `Term::fromMonths`. Un plazo
 * fuera de catalogo vuelve como 422 y se muestra tal cual.
 *
 * LA VIGENCIA TAMBIEN LA IMPONE EL SERVIDOR. La cuenta atras es informativa:
 * evita que el prospecto acepte a ciegas una oferta caducada, pero quien
 * rechaza por caducidad es el servidor, contra su propio reloj. Adelantar el
 * reloj del navegador no consigue nada.
 */
const router = useRouter()
const flow = useFlowStore()
const session = useSessionStore()

const TERM_OPTIONS = [6, 12, 18, 24, 36]

const termMonths = ref(12)
const offer = ref(null)
const globalError = ref('')
const errorCode = ref('')
const simulating = ref(false)
const accepting = ref(false)
const now = ref(Date.now())

let clock = null

const secondsLeft = computed(() => {
  if (!offer.value?.expires_at) return null
  return Math.floor((new Date(offer.value.expires_at).getTime() - now.value) / 1000)
})

const isExpired = computed(() => secondsLeft.value !== null && secondsLeft.value <= 0)

const countdown = computed(() => {
  const total = secondsLeft.value
  if (total === null || total <= 0) return null
  const minutes = Math.floor(total / 60)
  const seconds = total % 60
  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

/** El desglose se pinta en el orden del prototipo de la Fase 2. */
const breakdown = computed(() => {
  const data = offer.value
  if (!data) return []
  return [
    { label: 'Tipo de credito', value: data.credit_type_label },
    { label: 'Capacidad de pago estimada', value: `${data.payment_capacity} ${data.currency}` },
    { label: 'Monto propuesto', value: `${data.proposed_amount} ${data.currency}` },
    { label: 'Tasa anual', value: `${data.annual_rate_percentage} %` },
    { label: 'CAT', value: `${data.cat_percentage} %` },
    { label: 'Plazo', value: `${data.term_months} meses` },
    { label: 'Pago mensual estimado', value: `${data.estimated_monthly_payment} ${data.currency}` },
    { label: 'Total a pagar', value: `${data.total_payable} ${data.currency}` },
  ]
})

async function simulate() {
  globalError.value = ''
  errorCode.value = ''
  simulating.value = true
  try {
    const { data } = await api.post('/credit-simulations', { term_months: Number(termMonths.value) })
    offer.value = data.data
    flow.attachSimulation(data.data.simulation_public_id)
    now.value = Date.now()
  } catch (err) {
    const payload = err?.response?.data
    errorCode.value = payload?.error_code ?? ''
    // Un 422 del FormRequest llega con `errors`; uno del dominio, con
    // `message` y `error_code`. Los dos se muestran con el texto del
    // servidor: es el unico que conoce la regla que se incumplio.
    globalError.value = payload?.message
      ?? payload?.errors?.term_months?.[0]
      ?? 'No pudimos generar tu simulacion. Intentalo de nuevo.'
  } finally {
    simulating.value = false
  }
}

/**
 * P6 empieza aqui: la aceptacion es la accion, y la pantalla siguiente muestra
 * su resultado. Se hace en este boton y no al montar P6 por dos razones: al
 * montar, una recarga de P6 volveria a intentar aceptar —y el servidor
 * responderia ALREADY_DECIDED, dejando al cliente sin su propia vista—, y la
 * transicion de token conviene que ocurra en el mismo gesto que la autoriza.
 */
async function accept() {
  if (accepting.value) return
  globalError.value = ''
  errorCode.value = ''
  accepting.value = true
  try {
    const { data } = await api.post(`/credit-simulations/${offer.value.simulation_public_id}/accept`)

    // Transicion de token sin corte: el de prospecto ya quedo revocado en el
    // servidor y el de cliente llega en esta misma respuesta. Se sustituye
    // antes de navegar, para que la siguiente peticion salga con la
    // credencial nueva y el usuario no vea ningun reinicio de sesion.
    session.promoteToCustomer(data.data.session)
    flow.completeAsCustomer(data.data.customer)

    router.push({ name: 'AuthorizationConfirmedView' })
  } catch (err) {
    const payload = err?.response?.data
    errorCode.value = payload?.error_code ?? ''

    if (errorCode.value === 'CREDIT_SIMULATION_ALREADY_DECIDED' && flow.customer) {
      // Ya se autorizo antes -un doble click, una recarga- y el servidor lo
      // corta para que no se creen dos clientes. Si todavia tenemos la vista
      // que devolvio aquella aceptacion, se lleva al prospecto a ella en vez
      // de dejarle un error por algo que si salio bien.
      router.push({ name: 'AuthorizationConfirmedView' })
      return
    }

    globalError.value = payload?.message
      ?? 'No pudimos autorizar tu credito. Intentalo de nuevo.'
  } finally {
    accepting.value = false
  }
}

onMounted(() => {
  simulate()
  clock = setInterval(() => { now.value = Date.now() }, 1000)
})

onBeforeUnmount(() => {
  if (clock !== null) clearInterval(clock)
})
</script>

<template>
  <section class="card">
    <h1>Tu simulacion</h1>
    <p class="lead">
      Estas condiciones las calcula nuestro motor de reglas con tus datos
      verificados. Son informativas hasta que las autorices.
    </p>

    <AlertMessage v-if="globalError" variant="error" title="No se pudo continuar">
      {{ globalError }}
      <template v-if="errorCode === 'CREDIT_SIMULATION_EXPIRED'">
        Recalcula para obtener condiciones vigentes.
      </template>
    </AlertMessage>

    <div class="term">
      <label for="term">Plazo</label>
      <select id="term" v-model="termMonths" :disabled="simulating || accepting">
        <option v-for="months in TERM_OPTIONS" :key="months" :value="months">
          {{ months }} meses
        </option>
      </select>
      <button class="ghost" :disabled="simulating || accepting" @click="simulate">
        {{ simulating ? 'Calculando...' : 'Recalcular' }}
      </button>
    </div>

    <div v-if="simulating && !offer" class="progress" role="status" aria-live="polite">
      <span class="spinner" aria-hidden="true" />
      <p class="progress-title">Calculando tu oferta...</p>
    </div>

    <template v-else-if="offer">
      <dl class="breakdown">
        <div v-for="row in breakdown" :key="row.label" class="row">
          <dt>{{ row.label }}</dt>
          <dd>{{ row.value }}</dd>
        </div>
      </dl>

      <p class="folio">Folio de simulacion: <code>{{ offer.simulation_folio }}</code></p>

      <AlertMessage v-if="isExpired" variant="warning" title="Esta simulacion caduco">
        Las condiciones dependen de parametros de riesgo que cambian. Recalcula
        para ver una oferta vigente.
      </AlertMessage>
      <p v-else-if="countdown" class="expiry">
        Vigente durante <strong>{{ countdown }}</strong> mas.
      </p>

      <div class="actions">
        <button
          class="primary"
          :disabled="accepting || simulating || isExpired"
          @click="accept"
        >
          {{ accepting ? 'Autorizando...' : 'Autorizar mi credito' }}
        </button>
      </div>
      <p class="disclaimer">
        Al autorizar aceptas las condiciones de arriba y damos de alta tu linea
        de credito.
      </p>
    </template>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin-top: 0; color: #0f2c4a; }
.lead { color: #4b5665; }
.term {
  display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
  padding: 1rem; background: #f5f7fa; border-radius: 4px; margin: 1.5rem 0;
}
.term label { font-weight: 500; font-size: 0.9rem; color: #2e3846; }
.term select {
  padding: 0.5rem 0.75rem; border: 1px solid #cfd6df; border-radius: 4px;
  background: #fff; font-size: 0.95rem; color: #1f2833;
}
.progress { display: flex; gap: 1rem; align-items: center; padding: 1.25rem; }
.progress-title { margin: 0; font-weight: 600; color: #0f2c4a; }
.spinner {
  width: 22px; height: 22px; flex: none; border-radius: 50%;
  border: 3px solid #cfd6df; border-top-color: #0f2c4a;
  animation: spin 0.9s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.breakdown { margin: 0 0 1rem; border: 1px solid #e2e6ec; border-radius: 4px; overflow: hidden; }
.row {
  display: flex; justify-content: space-between; gap: 1rem;
  padding: 0.7rem 1rem; border-bottom: 1px solid #eef1f5;
}
.row:last-child { border-bottom: 0; background: #f5f7fa; }
.row dt { color: #4b5665; font-size: 0.9rem; margin: 0; }
.row dd { margin: 0; font-weight: 600; color: #1f2833; font-variant-numeric: tabular-nums; }
.folio { font-size: 0.8rem; color: #6b7686; }
.folio code { background: #f5f7fa; padding: 0.1rem 0.35rem; border-radius: 3px; }
.expiry { font-size: 0.85rem; color: #4b5665; }
.expiry strong { font-variant-numeric: tabular-nums; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.disclaimer { font-size: 0.78rem; color: #6b7686; text-align: right; margin-top: 0.5rem; }
.primary, .ghost { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; }
.primary { background: #0f2c4a; color: #fff; }
.ghost { background: transparent; color: #0f2c4a; border: 1px solid #0f2c4a; }
.primary:disabled, .ghost:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
