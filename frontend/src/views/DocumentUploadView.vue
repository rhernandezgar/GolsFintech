<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import api, { renewProspectSession } from '../composables/useApi'
import { useFlowStore } from '../stores/flow'
import AlertMessage from '../components/AlertMessage.vue'
import FormField from '../components/FormField.vue'

/**
 * P3: carga de la identificacion oficial y seguimiento de la extraccion.
 *
 * ES LA UNICA PANTALLA ASINCRONA DEL RECORRIDO. El servidor responde 202
 * Accepted en cuanto encola —no espera al OCR, que lo hace un worker aparte y
 * puede reintentarse tres veces— y devuelve `status_url`. Esta vista consulta
 * esa URL hasta que el documento llega a un estado final (`completed` o
 * `failed`). Sin el sondeo, el 202 dejaria al prospecto mirando una pantalla
 * que nunca cambia.
 *
 * El sondeo NO es un bucle apretado: empieza en 1.5 s y crece hasta 5 s. Una
 * SPA preguntando cada 300 ms por cada prospecto es carga que el servidor no
 * tiene por que absorber, y el OCR no termina antes por preguntarle mas.
 *
 * Mientras sonda se renueva la sesion: el token del prospecto vive 30 minutos
 * y una extraccion con reintentos puede acercarse al limite. La renovacion no
 * revoca el token anterior a proposito (bloque 1, precision 1), justamente
 * para que la peticion de sondeo que ya iba por el cable no se quede sin
 * credencial.
 */
const router = useRouter()
const flow = useFlowStore()

const DOCUMENT_TYPES = [
  { value: 'INE', label: 'INE / IFE' },
  { value: 'passport', label: 'Pasaporte' },
  { value: 'other', label: 'Otro documento oficial' },
]

const POLL_INITIAL_MS = 1500
const POLL_MAX_MS = 5000
const POLL_GIVE_UP_MS = 180000

const file = ref(null)
const documentType = ref('INE')
const fileError = ref('')
const globalError = ref('')
const uploading = ref(false)

/** null | 'pending' | 'processing' | 'completed' | 'failed' */
const ocrStatus = ref(null)
const attempt = ref(0)
const trackingId = ref(null)
const gaveUp = ref(false)

let pollTimer = null
let pollDelay = POLL_INITIAL_MS
let pollStartedAt = 0

const isPolling = computed(() => ocrStatus.value === 'pending' || ocrStatus.value === 'processing')
const isDone = computed(() => ocrStatus.value === 'completed')
const hasFailed = computed(() => ocrStatus.value === 'failed')

function onFileChange(event) {
  const [picked] = event.target.files ?? []
  file.value = picked ?? null
  fileError.value = ''
}

/**
 * El servidor devuelve `status_url` absoluta (la construye con `route()`). Se
 * sigue esa URL en vez de armar la ruta aqui —es el servidor quien dice donde
 * preguntar—, pero se consume su ruta relativa para que la peticion salga por
 * el mismo origen que las demas y no dependa de que el host del backend
 * coincida con el de la SPA.
 */
function pollPathFrom(statusUrl) {
  try {
    const parsed = new URL(statusUrl, window.location.origin)
    return parsed.pathname.replace(/^\/api\/v1/, '') + parsed.search
  } catch {
    return null
  }
}

let statusPath = null

async function upload() {
  fileError.value = ''
  globalError.value = ''
  gaveUp.value = false

  if (!file.value) {
    fileError.value = 'Adjunta tu identificacion oficial.'
    return
  }

  const payload = new FormData()
  payload.append('document', file.value)
  payload.append('document_type', documentType.value)

  uploading.value = true
  try {
    const { data } = await api.post('/identity-documents', payload)
    // 202: encolado, no procesado. Lo que interesa del cuerpo es con que
    // preguntar despues y donde.
    trackingId.value = data.data.tracking_id
    ocrStatus.value = data.data.ocr_status
    attempt.value = data.data.attempt
    statusPath = pollPathFrom(data.data.status_url)
    flow.attachDocument(data.data.tracking_id)
    startPolling()
  } catch (err) {
    // El detalle tecnico vive en el registro del servidor; aqui solo el
    // mensaje que el propio backend considero publicable.
    globalError.value = err?.response?.data?.message
      ?? 'No pudimos recibir tu identificacion. Intentalo de nuevo.'
  } finally {
    uploading.value = false
  }
}

function startPolling() {
  pollDelay = POLL_INITIAL_MS
  pollStartedAt = Date.now()
  scheduleNextPoll()
}

function scheduleNextPoll() {
  stopPolling()
  pollTimer = setTimeout(checkStatus, pollDelay)
  pollDelay = Math.min(Math.round(pollDelay * 1.5), POLL_MAX_MS)
}

function stopPolling() {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

async function checkStatus() {
  if (statusPath === null) return

  if (Date.now() - pollStartedAt > POLL_GIVE_UP_MS) {
    // No se deja el sondeo corriendo indefinidamente. El documento no se
    // pierde: sigue en el servidor con su identificador y el prospecto puede
    // volver a consultarlo.
    gaveUp.value = true
    stopPolling()
    return
  }

  try {
    // La sesion se renueva mientras dure la espera; si falla, el sondeo
    // continua con el token que ya tenia y sera el 401 quien corte.
    await renewProspectSession().catch(() => {})

    const { data } = await api.get(statusPath)
    ocrStatus.value = data.data.ocr_status
    attempt.value = data.data.attempt

    if (isPolling.value) {
      scheduleNextPoll()
    } else {
      stopPolling()
    }
  } catch (err) {
    // Un fallo puntual de red no aborta la espera: se reintenta con el
    // siguiente intervalo. El 401 lo corta el interceptor de `useApi`.
    if (err?.response?.status === 404) {
      globalError.value = 'No encontramos ese documento.'
      stopPolling()
      return
    }
    scheduleNextPoll()
  }
}

/** PT-03: el fracaso no borra nada. Se reintenta sin volver a capturar datos. */
function retry() {
  ocrStatus.value = null
  trackingId.value = null
  attempt.value = 0
  gaveUp.value = false
  globalError.value = ''
  statusPath = null
}

function resumePolling() {
  gaveUp.value = false
  startPolling()
}

function goOn() {
  // La extraccion escribio los datos en el expediente. El prospecto los
  // confirma en P2 antes de que se valide la identidad.
  router.push({ name: 'ProspectDataFormView' })
}

onBeforeUnmount(stopPolling)
</script>

<template>
  <section class="card">
    <h1>Tu identificacion oficial</h1>
    <p class="lead">
      Sube una foto o un PDF de tu identificacion. Extraeremos tus datos para
      que no tengas que escribirlos.
    </p>

    <AlertMessage v-if="globalError" variant="error" title="No se pudo procesar">
      {{ globalError }}
    </AlertMessage>

    <!-- Antes de subir -->
    <template v-if="ocrStatus === null">
      <FormField
        label="Tipo de documento"
        v-model="documentType"
        type="select"
        :options="DOCUMENT_TYPES"
        required
      />

      <label class="field">
        <span class="field-label">Archivo <span class="req" aria-hidden="true">*</span></span>
        <input type="file" accept="image/jpeg,image/png,application/pdf" @change="onFileChange" />
        <small v-if="fileError" class="msg error">{{ fileError }}</small>
        <small v-else class="msg hint">JPG, PNG o PDF. Maximo 5 MB.</small>
      </label>

      <div class="actions">
        <button class="primary" :disabled="uploading" @click="upload">
          {{ uploading ? 'Subiendo...' : 'Subir identificacion' }}
        </button>
      </div>
    </template>

    <!-- En proceso: el 202 ya llego y estamos sondeando -->
    <template v-else-if="isPolling && !gaveUp">
      <div class="progress" role="status" aria-live="polite">
        <span class="spinner" aria-hidden="true" />
        <div>
          <p class="progress-title">Estamos leyendo tu identificacion.</p>
          <p class="progress-detail">
            Puede tardar unos segundos. No cierres esta pantalla.
            <span v-if="attempt > 1">Intento {{ attempt }} de 3.</span>
          </p>
        </div>
      </div>
      <p class="tracking">Folio de seguimiento: <code>{{ trackingId }}</code></p>
    </template>

    <!-- La espera se agoto, pero el documento sigue en el servidor -->
    <template v-else-if="gaveUp">
      <AlertMessage variant="warning" title="Esto esta tardando mas de lo normal">
        Tu identificacion sigue guardada y no tienes que volver a subirla.
        Puedes seguir esperando o intentarlo de nuevo mas tarde.
      </AlertMessage>
      <p class="tracking">Folio de seguimiento: <code>{{ trackingId }}</code></p>
      <div class="actions">
        <button class="ghost" @click="retry">Subir otro archivo</button>
        <button class="primary" @click="resumePolling">Seguir esperando</button>
      </div>
    </template>

    <!-- Extraccion terminada -->
    <template v-else-if="isDone">
      <AlertMessage variant="success" title="Listo">
        Leimos tu identificacion. En el siguiente paso revisas los datos y los
        confirmas.
      </AlertMessage>
      <p class="tracking">Folio de seguimiento: <code>{{ trackingId }}</code></p>
      <div class="actions">
        <button class="primary" @click="goOn">Revisar mis datos</button>
      </div>
    </template>

    <!-- Fracaso de la extraccion (PT-03) -->
    <template v-else-if="hasFailed">
      <AlertMessage variant="warning" title="No pudimos leer tu identificacion">
        Puede ser por la calidad de la imagen. Prueba con otra foto, con mejor
        luz y sin reflejos. Tus datos no se han perdido.
      </AlertMessage>
      <p class="tracking">Folio de seguimiento: <code>{{ trackingId }}</code></p>
      <div class="actions">
        <button class="ghost" @click="goOn">Escribir mis datos a mano</button>
        <button class="primary" @click="retry">Subir otro archivo</button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin-top: 0; color: #0f2c4a; }
.lead { color: #4b5665; }
.field { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.9rem; }
.field-label { font-size: 0.85rem; color: #2e3846; font-weight: 500; }
.req { color: #c02b2b; }
.field input[type="file"] {
  padding: 0.6rem 0.75rem; border: 1px dashed #cfd6df; border-radius: 4px;
  background: #f8fafc; font-size: 0.9rem;
}
.msg { font-size: 0.78rem; }
.msg.error { color: #c02b2b; }
.msg.hint { color: #6b7686; }
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
.tracking { font-size: 0.8rem; color: #6b7686; }
.tracking code { background: #f5f7fa; padding: 0.1rem 0.35rem; border-radius: 3px; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.primary, .ghost { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; }
.primary { background: #0f2c4a; color: #fff; }
.ghost { background: transparent; color: #0f2c4a; border: 1px solid #0f2c4a; }
.primary:disabled, .ghost:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
