<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../composables/useApi'
import AlertMessage from '../components/AlertMessage.vue'
import FormField from '../components/FormField.vue'

/**
 * P7: consulta administrativa del cliente por su numero (RF-11).
 *
 * ES LA UNICA PANTALLA QUE NO CONSUME LA SESION DEL PROSPECTO. El endpoint
 * exige `can:customer.view.any`, que tienen admin, auditor y analista de
 * riesgos, y que un prospecto o un cliente NO tienen: su alcance es solo lo
 * suyo.
 *
 * LO QUE SE OCULTA AQUI NO ES UN CONTROL. La pantalla consulta `/me` para
 * saber si el usuario puede ejercer la consulta y ahorrarle un 403, pero eso
 * es usabilidad: quien autoriza es el servidor, en cada peticion, y lo volveria
 * a negar aunque esta vista mostrara el formulario igualmente.
 *
 * EL INGRESO DECLARADO ES AUTORIZACION POR CAMPO (RS-05). `declared_income`
 * viaja SOLO al analista de riesgos. Admin y auditor reciben la misma respuesta
 * sin ese campo, y por eso la vista lo pinta condicionado a que venga y no a un
 * rol que ella misma decida: el filtrado ocurre en el servidor, aqui solo se
 * refleja.
 *
 * NO HAY PANTALLA DE ACCESO ADMINISTRATIVO EN LA SPA, y no se inventa una: el
 * prototipo de la Fase 2 no la contempla y el acceso administrativo pasa por el
 * flujo OAuth del backend. Para poder ejercer esta pantalla en desarrollo se
 * admite pegar un token administrativo, y ese campo SOLO existe en desarrollo
 * (`import.meta.env.DEV`): en una compilacion de produccion no se genera.
 */
const isDev = import.meta.env.DEV

const customerNumber = ref('')
const adminToken = ref('')
const customer = ref(null)
const globalError = ref('')
const looking = ref(false)
const permissions = ref([])
const role = ref(null)
const sessionChecked = ref(false)

const canLookUp = computed(() => permissions.value.includes('customer.view.any'))
const showsDeclaredIncome = computed(
  () => customer.value !== null && customer.value.declared_income !== undefined,
)

const customerRows = computed(() => {
  const data = customer.value
  if (!data) return []
  const rows = [
    { label: 'Numero de cliente', value: data.customer_number },
    { label: 'Folio de contrato', value: data.contract_folio },
    { label: 'Monto autorizado', value: `${data.authorized_amount} ${data.currency}` },
    { label: 'Estado de la linea', value: data.line_status },
  ]
  if (data.card_last_four) {
    rows.push({ label: 'Tarjeta', value: `${data.card_brand} ****${data.card_last_four}` })
    rows.push({ label: 'Estado de la tarjeta', value: data.card_status })
    rows.push({ label: 'Vencimiento', value: data.card_expiration })
  } else {
    rows.push({ label: 'Tarjeta', value: 'Sin emitir' })
  }
  return rows
})

/** Cabecera explicita solo si se pego un token; si no, manda la sesion activa. */
function requestConfig() {
  if (isDev && adminToken.value.trim() !== '') {
    return { headers: { Authorization: `Bearer ${adminToken.value.trim()}` } }
  }
  return {}
}

async function lookUp() {
  globalError.value = ''
  customer.value = null

  if (customerNumber.value.trim() === '') {
    globalError.value = 'Escribe un numero de cliente.'
    return
  }

  looking.value = true
  try {
    const { data } = await api.get(
      `/customers/${encodeURIComponent(customerNumber.value.trim())}`,
      requestConfig(),
    )
    customer.value = data.data
  } catch (err) {
    const status = err?.response?.status
    if (status === 404) {
      // El servidor responde 404 tanto si el numero no existe como si esta
      // mal formado, a proposito: distinguirlos convertiria el numero de
      // cliente en un identificador enumerable. La vista no lo desdibuja.
      globalError.value = 'No encontramos un cliente con ese numero.'
    } else if (status === 403) {
      globalError.value = 'Tu sesion no tiene permiso para consultar clientes.'
    } else if (status === 401) {
      globalError.value = 'Esta consulta requiere una sesion administrativa.'
    } else {
      globalError.value = err?.response?.data?.message
        ?? 'No pudimos completar la consulta. Intentalo de nuevo.'
    }
  } finally {
    looking.value = false
  }
}

async function loadSession() {
  try {
    const { data } = await api.get('/me', requestConfig())
    permissions.value = data.data.permissions ?? []
    role.value = data.data.role ?? null
  } catch {
    permissions.value = []
    role.value = null
  } finally {
    sessionChecked.value = true
  }
}

onMounted(loadSession)
</script>

<template>
  <section class="card">
    <h1>Consulta de cliente</h1>
    <p class="lead">
      Busca un expediente por su numero de cliente. Cada consulta queda
      registrada en la bitacora de auditoria, encontremos al cliente o no.
    </p>

    <AlertMessage v-if="sessionChecked && !canLookUp" variant="warning" title="Sesion administrativa requerida">
      Esta pantalla es para los perfiles administrativo, de auditoria y de
      analisis de riesgos.
      <template v-if="role"> Tu sesion actual es <strong>{{ role }}</strong>.</template>
    </AlertMessage>

    <!-- Ayuda de desarrollo. No existe en una compilacion de produccion. -->
    <details v-if="isDev" class="dev">
      <summary>Usar un token administrativo (solo desarrollo)</summary>
      <FormField
        label="Token de acceso"
        v-model="adminToken"
        hint="La SPA no tiene pantalla de acceso administrativo: el acceso real pasa por el flujo OAuth del backend."
      />
      <button class="ghost" @click="loadSession">Comprobar permisos con este token</button>
    </details>

    <FormField
      label="Numero de cliente"
      v-model="customerNumber"
      hint="El numero que se le asigno al autorizar su credito."
      required
    />

    <div class="actions">
      <button class="primary" :disabled="looking" @click="lookUp">
        {{ looking ? 'Consultando...' : 'Consultar' }}
      </button>
    </div>

    <AlertMessage v-if="globalError" variant="error" title="Sin resultados">
      {{ globalError }}
    </AlertMessage>

    <template v-if="customer">
      <h2>Expediente</h2>
      <dl class="breakdown">
        <div v-for="row in customerRows" :key="row.label" class="row">
          <dt>{{ row.label }}</dt>
          <dd>{{ row.value }}</dd>
        </div>
        <!-- Solo llega al analista de riesgos (RS-05). Se pinta si viene, no
             segun un rol que decida esta vista. -->
        <div v-if="showsDeclaredIncome" class="row restricted">
          <dt>
            Ingreso declarado
            <span class="tag">Restringido</span>
          </dt>
          <dd>{{ customer.declared_income ?? 'Sin registrar' }}</dd>
        </div>
      </dl>
      <p class="note">
        Esta respuesta no incluye CURP ni RFC. Para saber el estado de un
        expediente no hace falta ningun dato personal.
      </p>
    </template>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin-top: 0; color: #0f2c4a; }
h2 { color: #0f2c4a; font-size: 1.05rem; margin: 1.75rem 0 0.75rem; }
.lead { color: #4b5665; }
.dev {
  border: 1px dashed #cfd6df; border-radius: 4px; padding: 0.75rem 1rem;
  margin-bottom: 1.25rem; background: #fbfcfd;
}
.dev summary { cursor: pointer; font-size: 0.85rem; color: #6b7686; font-weight: 500; }
.breakdown { margin: 0; border: 1px solid #e2e6ec; border-radius: 4px; overflow: hidden; }
.row {
  display: flex; justify-content: space-between; gap: 1rem;
  padding: 0.7rem 1rem; border-bottom: 1px solid #eef1f5;
}
.row:last-child { border-bottom: 0; }
.row.restricted { background: #fdf3dc; }
.row dt { color: #4b5665; font-size: 0.9rem; margin: 0; }
.row dd { margin: 0; font-weight: 600; color: #1f2833; font-variant-numeric: tabular-nums; }
.tag {
  display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.4rem;
  background: #f2b134; color: #4a3708; border-radius: 3px;
  font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700;
}
.note { font-size: 0.82rem; color: #6b7686; margin-top: 1rem; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin: 0.5rem 0 1.25rem; }
.primary, .ghost { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; }
.primary { background: #0f2c4a; color: #fff; }
.ghost { background: transparent; color: #0f2c4a; border: 1px solid #0f2c4a; font-size: 0.85rem; padding: 0.5rem 1rem; }
.primary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
