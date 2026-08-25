<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useFlowStore } from '../stores/flow'
import { useSessionStore } from '../stores/session'
import AlertMessage from '../components/AlertMessage.vue'

/**
 * P6: confirmacion de la autorizacion.
 *
 * Es una pantalla de presentacion: la aceptacion ya ocurrio en P5, junto al
 * gesto que la autorizo. Aqui no se llama a ningun endpoint, y eso es
 * deliberado —aceptar al montar convertiria cada recarga en un intento nuevo
 * de aceptar—.
 *
 * LA TRANSICION DE TOKEN YA PASO Y NO SE HA NOTADO. Al aceptar, el servidor
 * revoca todos los tokens de prospecto y emite uno con alcance
 * `customer-session`; la respuesta lo trae y el store lo sustituyo antes de
 * navegar hasta aqui. Por eso la insignia de la barra superior ya dice
 * "Cliente" sin que haya habido ningun paso de inicio de sesion. Dejar vivo el
 * token anterior habria sido una escalada silenciosa: el mismo token pasaria a
 * valer para endpoints que no existian cuando se emitio.
 *
 * LA TARJETA PUEDE FALTAR, Y NO ES UN ERROR. El emisor es un tercero y su
 * llamada corre fuera de la transaccion que da de alta al cliente y su linea.
 * Si falla, el credito autorizado NO se pierde: cliente y linea quedan
 * escritos y la tarjeta se emite despues. La pantalla lo dice con ese tono, no
 * con el de un fallo.
 */
const router = useRouter()
const flow = useFlowStore()
const session = useSessionStore()

const customer = computed(() => flow.customer)
const hasCard = computed(() => Boolean(customer.value?.card_last_four))

const creditRows = computed(() => {
  const data = customer.value
  if (!data) return []
  return [
    { label: 'Numero de cliente', value: data.customer_number },
    { label: 'Folio de contrato', value: data.contract_folio },
    { label: 'Monto autorizado', value: `${data.authorized_amount} ${data.currency}` },
    { label: 'Estado de la linea', value: data.line_status },
  ]
})

function startOver() {
  // Cerrar sesion y limpiar el recorrido. El cliente ya esta dado de alta en
  // el servidor; lo que se descarta es el estado local de este tramite.
  flow.reset()
  session.close()
  router.push({ name: 'WelcomeView' })
}
</script>

<template>
  <section class="card">
    <!-- Sin la vista del cliente no hay nada que confirmar: se llego aqui sin
         pasar por la autorizacion, o el almacenamiento se vacio. -->
    <template v-if="!customer">
      <h1>No encontramos tu autorizacion</h1>
      <AlertMessage variant="warning" title="Nada que mostrar aqui todavia">
        Esta pantalla resume un credito ya autorizado. Si acabas de empezar tu
        solicitud, continua desde el principio.
      </AlertMessage>
      <div class="actions">
        <button class="primary" @click="startOver">Ir al inicio</button>
      </div>
    </template>

    <template v-else>
      <div class="verdict">
        <span class="mark" aria-hidden="true">&check;</span>
        <div>
          <h1>Tu credito esta autorizado</h1>
          <p class="verdict-body">
            Ya eres cliente de GolsFintech. Guarda tu numero de cliente: es con
            el que se consulta tu expediente.
          </p>
        </div>
      </div>

      <dl class="breakdown">
        <div v-for="row in creditRows" :key="row.label" class="row">
          <dt>{{ row.label }}</dt>
          <dd>{{ row.value }}</dd>
        </div>
      </dl>

      <h2>Tu tarjeta</h2>

      <div v-if="hasCard" class="card-summary">
        <div class="plastic">
          <span class="brand">{{ customer.card_brand }}</span>
          <!-- Solo los ultimos cuatro digitos. El numero completo no existe en
               esta aplicacion: lo que se almacena es un token del emisor. -->
          <span class="pan">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; {{ customer.card_last_four }}</span>
          <span class="expiry">Vence {{ customer.card_expiration }}</span>
        </div>
        <p class="card-status">Estado: {{ customer.card_status }}</p>
      </div>

      <AlertMessage v-else variant="warning" title="Tu tarjeta se emitira en breve">
        Tu credito quedo autorizado y tu linea esta abierta. La emision de la
        tarjeta la hace nuestro proveedor y todavia no ha terminado; te
        avisaremos en cuanto este lista. No tienes que hacer nada.
      </AlertMessage>

      <p class="note">
        La simulacion que viste era informativa; lo que acabas de autorizar son
        las condiciones definitivas de tu linea de credito.
      </p>

      <div class="actions">
        <button class="ghost" @click="startOver">Terminar</button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.card { background: #fff; padding: 2rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h1 { margin: 0; color: #0f2c4a; font-size: 1.45rem; }
h2 { color: #0f2c4a; font-size: 1.05rem; margin: 1.75rem 0 0.75rem; }
.verdict {
  display: flex; gap: 1rem; align-items: center;
  padding: 1.25rem; border-radius: 4px; margin-bottom: 1.5rem;
  background: #eef8e5; border-left: 4px solid #7ac74f;
}
.mark {
  width: 40px; height: 40px; flex: none; border-radius: 50%; background: #7ac74f;
  display: inline-grid; place-items: center; color: #fff; font-size: 1.35rem; font-weight: 700;
}
.verdict-body { margin: 0.35rem 0 0; font-size: 0.9rem; color: #4b5665; }
.breakdown { margin: 0; border: 1px solid #e2e6ec; border-radius: 4px; overflow: hidden; }
.row {
  display: flex; justify-content: space-between; gap: 1rem;
  padding: 0.7rem 1rem; border-bottom: 1px solid #eef1f5;
}
.row:last-child { border-bottom: 0; }
.row dt { color: #4b5665; font-size: 0.9rem; margin: 0; }
.row dd { margin: 0; font-weight: 600; color: #1f2833; font-variant-numeric: tabular-nums; }
.card-summary { display: flex; flex-direction: column; gap: 0.5rem; }
.plastic {
  display: flex; flex-direction: column; gap: 0.6rem;
  background: linear-gradient(135deg, #0f2c4a, #1d4f7c);
  color: #fff; padding: 1.25rem; border-radius: 8px; max-width: 320px;
}
.plastic .brand { font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.85; }
.plastic .pan { font-size: 1.05rem; letter-spacing: 0.1em; font-variant-numeric: tabular-nums; }
.plastic .expiry { font-size: 0.78rem; opacity: 0.85; }
.card-status { font-size: 0.85rem; color: #4b5665; margin: 0; }
.note { font-size: 0.82rem; color: #6b7686; margin-top: 1.5rem; }
.actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.primary, .ghost { padding: 0.7rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; border: 0; }
.primary { background: #0f2c4a; color: #fff; }
.ghost { background: transparent; color: #0f2c4a; border: 1px solid #0f2c4a; }
</style>
