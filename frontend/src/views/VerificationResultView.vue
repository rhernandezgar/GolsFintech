<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../composables/useApi'
import { useFlowStore } from '../stores/flow'
import AlertMessage from '../components/AlertMessage.vue'

/**
 * P4: resultado de la validacion de identidad contra INE y RENAPO.
 *
 * ESTA PANTALLA NO DECIDE NADA. El veredicto lo emite el servidor y aqui solo
 * se pinta lo que devuelve: `verified`, `not_verified` o `pending`. No hay
 * ninguna comprobacion en el navegador que pueda adelantar, contradecir o
 * suavizar ese resultado.
 *
 * LO QUE NO SE MUESTRA, Y ES DELIBERADO. Cuando la validacion no sale
 * verificada, la respuesta trae solo el estado y el folio: nunca `ine_status`,
 * `renapo_status` ni cual de los cuatro criterios fallo. No es que la vista lo
 * oculte —el servidor no lo manda (riesgo R-01)—: decirle a quien intenta
 * suplantar una identidad si fallo INE o RENAPO le dice exactamente que
 * corregir en el siguiente intento. El detalle si queda en la bitacora, y
 * soporte lo reconstruye con el folio.
 *
 * TRES DESENLACES, NO DOS. `pending` no es un rechazo: significa que el
 * proveedor no contesto o esta en proceso, y colapsarlo con `not_verified`
 * negaria credito a alguien con identidad valida por una caida ajena (R-03).
 * Por eso tiene pantalla propia, con reintento y sin lenguaje de rechazo.
 *
 * DOS CAUSAS DISTINTAS DE `pending`, Y NO SE TRATAN IGUAL. El veredicto
 * "verificado" exige documento: sin uno, la vigencia del documento no se puede
 * evaluar y el conjunto queda pendiente. Es deliberado —no se fuerza un
 * veredicto favorable sin evidencia—, pero significa que en la rama manual,
 * donde no se subio identificacion, reintentar no cambia nada nunca: la
 * pantalla seria un callejon sin salida. Por eso, cuando llega `pending` y no
 * hay documento en el expediente, lo que se ofrece es subirlo (P3) en vez de
 * reintentar. Cuando si lo hay, la causa es el proveedor y el reintento si
 * tiene sentido.
 */
const router = useRouter()
const flow = useFlowStore()

/** null | 'verified' | 'not_verified' | 'pending' */
const status = ref(null)
const folio = ref('')
const message = ref('')
const globalError = ref('')
const validating = ref(false)
/** Precondicion de flujo incumplida: P4 exige P2 confirmada. */
const needsDataConfirmation = ref(false)

/**
 * `pending` sin documento no se resuelve esperando: falta evidencia, no
 * respuesta del proveedor. Se distinguen para no ofrecer un reintento que no
 * puede cambiar el resultado.
 */
const needsDocument = computed(
  () => status.value === 'pending' && !flow.documentPublicId,
)

async function validate() {
  globalError.value = ''
  needsDataConfirmation.value = false
  validating.value = true
  try {
    // El prospecto sale del token, nunca de la URL. Lo unico que viaja en el
    // cuerpo es el documento cargado en P3, y solo si hubo uno: en la rama
    // manual no existe y el campo se omite.
    const body = flow.documentPublicId ? { document_public_id: flow.documentPublicId } : {}
    const { data } = await api.post('/identity-validations', body)
    status.value = data.data.status
    folio.value = data.data.verification_folio
    message.value = data.data.message
  } catch (err) {
    const code = err?.response?.data?.error_code
    const httpStatus = err?.response?.status

    if (code === 'PROSPECT_DATA_NOT_CONFIRMED') {
      // Precondicion de flujo: P4 va despues de P2. Se devuelve al prospecto
      // a confirmar en vez de dejarlo en una pantalla sin salida.
      needsDataConfirmation.value = true
      globalError.value = err?.response?.data?.message
        ?? 'Antes de verificar tu identidad tienes que confirmar tus datos.'
      return
    }
    if (httpStatus === 503) {
      // El proveedor lanzo excepcion. Es el mismo caso de fondo que
      // `pending`, y se trata igual: reintento, no rechazo.
      status.value = 'pending'
      message.value = err?.response?.data?.message
        ?? 'Estamos verificando tu identidad. Vuelve a intentarlo en unos minutos.'
      return
    }
    globalError.value = err?.response?.data?.message
      ?? 'No pudimos completar la verificacion. Intentalo de nuevo.'
  } finally {
    validating.value = false
  }
}

function backToData() {
  router.push({ name: 'ProspectDataFormView' })
}

function goOn() {
  router.push({ name: 'CreditSimulationView' })
}

function uploadDocument() {
  router.push({ name: 'DocumentUploadView' })
}

onMounted(validate)
</script>

<template>
  <section class="card">
    <h1>Verificacion de identidad</h1>

    <AlertMessage v-if="globalError" variant="error" title="No se pudo verificar">
      {{ globalError }}
    </AlertMessage>

    <div v-if="needsDataConfirmation" class="actions">
      <button class="primary" @click="backToData">Volver a mis datos</button>
    </div>

    <!-- En curso -->
    <div v-else-if="validating" class="progress" role="status" aria-live="polite">
      <span class="spinner" aria-hidden="true" />
      <div>
        <p class="progress-title">Estamos verificando tu identidad.</p>
        <p class="progress-detail">Consultamos INE y RENAPO. Tarda unos segundos.</p>
      </div>
    </div>

    <!-- Verificada -->
    <template v-else-if="status === 'verified'">
      <div class="verdict ok">
        <span class="mark" aria-hidden="true">&check;</span>
        <div>
          <p class="verdict-title">Identidad verificada</p>
          <p class="verdict-body">{{ message }}</p>
        </div>
      </div>
      <p class="folio">Folio de verificacion: <code>{{ folio }}</code></p>
      <div class="actions">
        <button class="primary" @click="goOn">Ver mi simulacion</button>
      </div>
    </template>

    <!-- No verificada. Sin detalle de que fallo: R-01. -->
    <template v-else-if="status === 'not_verified'">
      <div class="verdict ko">
        <span class="mark" aria-hidden="true">&times;</span>
        <div>
          <p class="verdict-title">No pudimos verificar tu identidad</p>
          <p class="verdict-body">{{ message }}</p>
        </div>
      </div>
      <p class="folio">Folio de verificacion: <code>{{ folio }}</code></p>
      <p class="support">
        Guarda este folio. Con el, soporte puede revisar tu caso sin que tengas
        que empezar de nuevo.
      </p>
    </template>

    <!-- Pendiente por falta de documento: reintentar no cambiaria nada. -->
    <template v-else-if="needsDocument">
      <AlertMessage variant="info" title="Nos falta tu identificacion">
        Para confirmar tu identidad necesitamos ver tu identificacion oficial.
        Tus datos ya estan guardados; solo falta ese paso.
      </AlertMessage>
      <p v-if="folio" class="folio">Folio de verificacion: <code>{{ folio }}</code></p>
      <div class="actions">
        <button class="primary" @click="uploadDocument">Subir mi identificacion</button>
      </div>
    </template>

    <!-- Pendiente por el proveedor. No es un rechazo (R-03). -->
    <template v-else-if="status === 'pending'">
      <AlertMessage variant="warning" title="Todavia no tenemos respuesta">
        {{ message }}
      </AlertMessage>
      <p v-if="folio" class="folio">Folio de verificacion: <code>{{ folio }}</code></p>
      <p class="support">
        Esto no significa que tu identidad tenga algun problema: el servicio de
        verificacion todavia no ha contestado. Tu solicitud sigue guardada.
      </p>
      <div class="actions">
        <button class="primary" :disabled="validating" @click="validate">
          Intentar de nuevo
        </button>
      </div>
    </template>

    <!-- Fallo que no es ninguno de los tres desenlaces: red caida, 5xx
         inesperado. Tampoco es un rechazo, asi que se ofrece reintento. -->
    <div v-else-if="globalError" class="actions">
      <button class="primary" :disabled="validating" @click="validate">
        Intentar de nuevo
      </button>
    </div>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin-top: 0; color: #0f2c4a; }
.progress {
  display: flex; gap: 1rem; align-items: center;
  padding: 1.25rem; background: #eef2f7; border-radius: 4px; margin: 1.5rem 0 1rem;
}
.progress-title { margin: 0; font-weight: 600; color: #0f2c4a; }
.progress-detail { margin: 0.25rem 0 0; font-size: 0.85rem; color: #4b5665; }
.spinner {
  width: 22px; height: 22px; flex: none; border-radius: 50%;
  border: 3px solid #cfd6df; border-top-color: #0f2c4a;
  animation: spin 0.9s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.verdict {
  display: flex; gap: 1rem; align-items: center;
  padding: 1.25rem; border-radius: 4px; margin: 1.5rem 0 1rem;
}
.verdict.ok { background: #eef8e5; border-left: 4px solid #7ac74f; }
.verdict.ko { background: #fdecec; border-left: 4px solid #c02b2b; }
.mark {
  width: 34px; height: 34px; flex: none; border-radius: 50%;
  display: inline-grid; place-items: center; color: #fff; font-size: 1.2rem; font-weight: 700;
}
.verdict.ok .mark { background: #7ac74f; }
.verdict.ko .mark { background: #c02b2b; }
.verdict-title { margin: 0; font-weight: 600; color: #2e3846; }
.verdict-body { margin: 0.25rem 0 0; font-size: 0.9rem; color: #4b5665; }
.folio { font-size: 0.8rem; color: #6b7686; }
.folio code { background: #f5f7fa; padding: 0.1rem 0.35rem; border-radius: 3px; }
.support { font-size: 0.85rem; color: #4b5665; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.primary { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; background: #0f2c4a; color: #fff; }
.primary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
