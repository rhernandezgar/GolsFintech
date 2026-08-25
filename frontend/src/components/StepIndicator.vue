<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { STEPS, stepIndexFor } from '../composables/useSteps'

const route = useRoute()
const current = computed(() => stepIndexFor(route.name))

function stateFor(index) {
  if (current.value < 0) return 'inactive'
  if (index < current.value) return 'done'
  if (index === current.value) return 'active'
  return 'pending'
}
</script>

<template>
  <nav class="steps" aria-label="Progreso de la solicitud">
    <ol>
      <li v-for="(step, index) in STEPS" :key="step.key" :class="stateFor(index)">
        <span class="dot" aria-hidden="true">{{ index + 1 }}</span>
        <span class="label">{{ step.label }}</span>
      </li>
    </ol>
  </nav>
</template>

<style scoped>
.steps {
  padding: 1rem 1.5rem;
  background: #f5f7fa;
  border-bottom: 1px solid #e2e6ec;
}
ol {
  display: flex;
  gap: 0.75rem;
  list-style: none;
  margin: 0;
  padding: 0;
  overflow-x: auto;
}
li {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: #6b7686;
  white-space: nowrap;
}
.dot {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #dfe5ee;
  color: #6b7686;
  display: inline-grid;
  place-items: center;
  font-weight: 600;
  font-size: 0.75rem;
}
li.done .dot { background: #7ac74f; color: #fff; }
li.done .label { color: #2e3846; }
li.active .dot { background: #0f2c4a; color: #fff; }
li.active .label { color: #0f2c4a; font-weight: 600; }
</style>
