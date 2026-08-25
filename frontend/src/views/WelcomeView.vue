<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import api from '../composables/useApi'
import { useSessionStore } from '../stores/session'
import { useFlowStore } from '../stores/flow'
import FormField from '../components/FormField.vue'
import AlertMessage from '../components/AlertMessage.vue'

/**
 * P1: eleccion del metodo de captura y aceptacion del aviso de privacidad.
 *
 * Es el unico endpoint del recorrido sin token (quinta excepcion a la regla
 * 9): aqui NACE la credencial. Por eso lleva CAPTCHA obligatorio, ademas
 * del throttle por IP del servidor. La pantalla acepta el token de prueba
 * en desarrollo -mismo que el backend `simulated`-.
 */
const router = useRouter()
const session = useSessionStore()
const flow = useFlowStore()

const form = reactive({
  captureMethod: 'manual',
  privacyNoticeAccepted: false,
  captchaToken: '',
})
const errors = reactive({ captureMethod: '', privacyNoticeAccepted: '', captchaToken: '' })
const globalError = ref('')
const submitting = ref(false)

async function submit() {
  errors.privacyNoticeAccepted = form.privacyNoticeAccepted ? '' : 'Debes aceptar el aviso de privacidad.'
  errors.captchaToken = form.captchaToken ? '' : 'Confirma que no eres un robot.'
  globalError.value = ''
  if (errors.privacyNoticeAccepted || errors.captchaToken) return

  submitting.value = true
  try {
    const { data } = await api.post('/prospects', {
      capture_method: form.captureMethod,
      privacy_notice_accepted: form.privacyNoticeAccepted,
      captcha_token: form.captchaToken,
    })
    // El backend abre el expediente y emite el token de prospecto. Guardar
    // primero la sesion; luego la SPA decide a que pantalla ir segun la
    // bifurcacion elegida.
    session.openProspectSession({
      trackingId: data.data.tracking_id,
      session: data.data.session,
    })
    flow.reset()
    flow.startCapture(form.captureMethod)
    router.push({ name: form.captureMethod === 'ocr' ? 'DocumentUploadView' : 'ProspectDataFormView' })
  } catch (err) {
    // Mensaje generico: puede ser CAPTCHA rechazado, throttle, o el aviso
    // no aceptado. El detalle vive en la bitacora del servidor.
    globalError.value = err?.response?.data?.message
      ?? 'No pudimos iniciar tu solicitud. Intentalo de nuevo en unos segundos.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="card">
    <h1>Solicita tu credito en menos de 5 minutos</h1>
    <p class="lead">
      Vas a proporcionar datos personales. Puedes escribirlos a mano o subir
      tu identificacion oficial y dejar que el sistema los extraiga.
    </p>

    <AlertMessage v-if="globalError" variant="error" title="No se pudo iniciar">
      {{ globalError }}
    </AlertMessage>

    <fieldset class="method">
      <legend>Como quieres proporcionar tus datos?</legend>
      <label>
        <input type="radio" value="manual" v-model="form.captureMethod" />
        <span>Escribirlos yo mismo (P2)</span>
      </label>
      <label>
        <input type="radio" value="ocr" v-model="form.captureMethod" />
        <span>Subir mi identificacion y que se extraigan (P3)</span>
      </label>
    </fieldset>

    <label class="consent">
      <input type="checkbox" v-model="form.privacyNoticeAccepted" />
      <span>He leido y acepto el aviso de privacidad (LFPDPPP).</span>
    </label>
    <small v-if="errors.privacyNoticeAccepted" class="err">{{ errors.privacyNoticeAccepted }}</small>

    <FormField
      label="Codigo de verificacion (CAPTCHA)"
      v-model="form.captchaToken"
      :error="errors.captchaToken"
      hint="En desarrollo, escribe `captcha-ok`."
      required
    />

    <div class="actions">
      <button class="primary" :disabled="submitting" @click="submit">
        {{ submitting ? 'Iniciando...' : 'Comenzar solicitud' }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.card {
  background: #fff;
  padding: 2rem;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}
h1 { margin-top: 0; color: #0f2c4a; }
.lead { color: #4b5665; }
.method {
  border: 1px solid #e2e6ec;
  border-radius: 4px;
  padding: 1rem;
  margin: 1.5rem 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.method legend { padding: 0 0.5rem; font-weight: 500; }
.method label { display: flex; gap: 0.5rem; align-items: center; }
.consent { display: flex; gap: 0.5rem; align-items: flex-start; margin-bottom: 0.5rem; }
.err { color: #c02b2b; font-size: 0.78rem; }
.actions { margin-top: 1.5rem; text-align: right; }
.primary {
  background: #0f2c4a; color: #fff; border: 0; padding: 0.7rem 1.5rem;
  border-radius: 4px; font-weight: 600; cursor: pointer;
}
.primary:disabled { background: #7a8494; cursor: not-allowed; }
</style>
