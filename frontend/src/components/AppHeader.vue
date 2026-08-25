<script setup>
import { computed } from 'vue'
import { useSessionStore } from '../stores/session'

const session = useSessionStore()

const label = computed(() => {
  if (session.isCustomer) return 'Cliente'
  if (session.isProspect) return 'Solicitud en tramite'
  return null
})
</script>

<template>
  <header class="app-header">
    <div class="brand">GolsFintech</div>
    <div class="session-badge" v-if="label">
      <span class="dot" :class="{ customer: session.isCustomer }" aria-hidden="true" />
      {{ label }}
    </div>
  </header>
</template>

<style scoped>
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1.5rem;
  background: #0f2c4a;
  color: #fff;
  box-shadow: 0 1px 0 rgba(0, 0, 0, 0.12);
}
.brand {
  font-weight: 600;
  letter-spacing: 0.02em;
}
.session-badge {
  font-size: 0.85rem;
  display: inline-flex;
  gap: 0.5rem;
  align-items: center;
  opacity: 0.9;
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #f2b134;
}
.dot.customer {
  background: #7ac74f;
}
</style>
