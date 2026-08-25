<script setup>
import { reactive, ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '../composables/useApi'
import { useFlowStore } from '../stores/flow'
import FormField from '../components/FormField.vue'
import AlertMessage from '../components/AlertMessage.vue'

/**
 * P2: captura del expediente. El servidor acepta captura parcial (PATCH),
 * asi que el formulario se puede llenar por pasos. Al pulsar "Guardar",
 * se hace PATCH; al pulsar "Confirmar", POST /confirm, que exige el
 * expediente completo y responde con la lista de campos faltantes si algo
 * queda a medias.
 *
 * LAS DOS RAMAS CONVERGEN AQUI. En la rama manual esta es la primera
 * pantalla con datos; en la rama OCR se llega despues de P3 y el expediente
 * ya viene relleno del lado del servidor. En ese caso el formulario aparece
 * vacio a proposito: `GET /prospects/me` NO devuelve los datos personales
 * —solo `has_data`—, y esta pantalla no los inventa. Lo que se envia en el
 * PATCH es solo lo que el prospecto escriba, asi que confirmar sin tocar
 * nada conserva lo que extrajo el OCR y escribir un campo lo corrige.
 */
const router = useRouter()
const flow = useFlowStore()
const serverHasData = ref(false)
const cameFromOcr = computed(() => flow.captureMethod === 'ocr')

const form = reactive({
  full_name: '',
  curp: '',
  rfc: '',
  age: '',
  sex: '',
  monthly_income: '',
  address: '',
  business_type: '',
  email: '',
  phone: '',
})
const errors = reactive({})
const globalError = ref('')
const info = ref('')
const saving = ref(false)
const confirming = ref(false)

const sexOptions = [
  { value: '', label: 'Selecciona' },
  { value: 'H', label: 'Hombre' },
  { value: 'M', label: 'Mujer' },
  { value: 'X', label: 'Prefiero no decirlo' },
]

function clearErrors() {
  Object.keys(errors).forEach((k) => delete errors[k])
  globalError.value = ''
}

function applyValidationErrors(payload) {
  if (payload?.errors) {
    Object.entries(payload.errors).forEach(([field, msgs]) => {
      errors[field] = Array.isArray(msgs) ? msgs[0] : String(msgs)
    })
    return true
  }
  if (payload?.missing_fields) {
    payload.missing_fields.forEach((field) => {
      errors[field] = 'Este campo es obligatorio para continuar.'
    })
    return true
  }
  return false
}

/** Solo envia lo que el usuario haya rellenado. El servidor no cambia lo omitido. */
function currentPatch() {
  const patch = {}
  Object.entries(form).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) patch[key] = value
  })
  if (patch.age !== undefined) patch.age = Number(patch.age)
  return patch
}

async function save() {
  clearErrors()
  info.value = ''
  saving.value = true
  try {
    await api.patch('/prospects/me', currentPatch())
    info.value = 'Datos guardados. Puedes seguir editandolos o confirmar cuando esten completos.'
  } catch (err) {
    if (!applyValidationErrors(err?.response?.data)) {
      globalError.value = err?.response?.data?.message
        ?? 'No se pudieron guardar los datos.'
    }
  } finally {
    saving.value = false
  }
}

async function confirm() {
  clearErrors()
  info.value = ''
  confirming.value = true
  try {
    // Antes de confirmar, un ultimo guardado para no perder cambios en
    // pantalla que aun no se hayan enviado.
    await api.patch('/prospects/me', currentPatch())
    await api.post('/prospects/me/confirm')
    // Las dos ramas convergen en la validacion de identidad: confirmar los
    // datos es lo que habilita P4, se haya llegado aqui por captura manual o
    // despues del OCR.
    router.push({ name: 'VerificationResultView' })
  } catch (err) {
    if (!applyValidationErrors(err?.response?.data)) {
      globalError.value = err?.response?.data?.message
        ?? 'No se pudo confirmar la captura. Revisa los campos.'
    }
  } finally {
    confirming.value = false
  }
}

onMounted(async () => {
  // Solo el estado del tramite: `has_data` dice si el expediente ya tiene
  // datos —los haya escrito el prospecto o extraido el OCR—. Ningun dato
  // personal viaja en esta respuesta, ni siquiera enmascarado.
  try {
    const { data } = await api.get('/prospects/me')
    serverHasData.value = data.data.has_data === true
  } catch {
    // Si el estado no se puede leer, el formulario funciona igual: es
    // informativo, no una precondicion.
  }
})
</script>

<template>
  <section class="card">
    <h1>Tus datos</h1>
    <p class="lead">
      Puedes guardar y volver despues. Al confirmar, el sistema comprobara
      que todo este completo.
    </p>

    <AlertMessage v-if="cameFromOcr && serverHasData" variant="info" title="Ya tenemos tus datos">
      Los extrajimos de tu identificacion. Escribe un campo solo si quieres
      corregirlo; lo que dejes en blanco se queda como esta. Al confirmar
      comprobaremos que el expediente este completo.
    </AlertMessage>

    <AlertMessage v-if="globalError" variant="error" title="Revisa el formulario">
      {{ globalError }}
    </AlertMessage>
    <AlertMessage v-if="info" variant="success">{{ info }}</AlertMessage>

    <FormField label="Nombre completo" v-model="form.full_name" :error="errors.full_name" required />
    <FormField label="CURP" v-model="form.curp" :error="errors.curp"
               hint="18 caracteres, con digito verificador." required />
    <FormField label="RFC (opcional)" v-model="form.rfc" :error="errors.rfc"
               hint="12 o 13 caracteres." />
    <FormField label="Edad" v-model="form.age" type="number" :error="errors.age" required />
    <FormField label="Sexo" v-model="form.sex" type="select" :options="sexOptions"
               :error="errors.sex" required />
    <FormField label="Ingreso mensual (MXN)" v-model="form.monthly_income" type="text"
               :error="errors.monthly_income"
               hint="Numero con hasta dos decimales (ej. 20000.00)." required />

    <details class="optional">
      <summary>Datos opcionales</summary>
      <FormField label="Domicilio" v-model="form.address" :error="errors.address" />
      <FormField label="Giro del negocio" v-model="form.business_type" :error="errors.business_type" />
      <FormField label="Correo" v-model="form.email" type="email" :error="errors.email" />
      <FormField label="Telefono" v-model="form.phone" :error="errors.phone" />
    </details>

    <div class="actions">
      <button class="ghost" :disabled="saving || confirming" @click="save">
        {{ saving ? 'Guardando...' : 'Guardar sin confirmar' }}
      </button>
      <button class="primary" :disabled="saving || confirming" @click="confirm">
        {{ confirming ? 'Confirmando...' : 'Confirmar y continuar' }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin-top: 0; color: #0f2c4a; }
.lead { color: #4b5665; }
.optional { margin: 0.5rem 0 1.5rem; }
.optional summary { cursor: pointer; padding: 0.5rem 0; font-weight: 500; color: #0f2c4a; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem; }
.primary, .ghost { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; }
.primary { background: #0f2c4a; color: #fff; }
.ghost { background: transparent; color: #0f2c4a; border: 1px solid #0f2c4a; }
.primary:disabled, .ghost:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
