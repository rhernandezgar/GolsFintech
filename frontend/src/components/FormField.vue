<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: '' },
  label: { type: String, required: true },
  type: { type: String, default: 'text' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: { type: Boolean, default: false },
  autocomplete: { type: String, default: 'off' },
  disabled: { type: Boolean, default: false },
  options: { type: Array, default: () => [] },
})
defineEmits(['update:modelValue'])

const state = computed(() => (props.error ? 'error' : 'default'))
</script>

<template>
  <label class="field" :class="state">
    <span class="field-label">
      {{ label }}
      <span v-if="required" class="req" aria-hidden="true">*</span>
    </span>

    <select
      v-if="type === 'select'"
      :value="modelValue"
      :disabled="disabled"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>

    <input
      v-else
      :type="type"
      :value="modelValue"
      :autocomplete="autocomplete"
      :disabled="disabled"
      @input="$emit('update:modelValue', $event.target.value)"
    />

    <small v-if="error" class="msg error">{{ error }}</small>
    <small v-else-if="hint" class="msg hint">{{ hint }}</small>
  </label>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.9rem;
}
.field-label {
  font-size: 0.85rem;
  color: #2e3846;
  font-weight: 500;
}
.req { color: #c02b2b; }
input, select {
  padding: 0.6rem 0.75rem;
  border: 1px solid #cfd6df;
  border-radius: 4px;
  font-size: 0.95rem;
  background: #fff;
  color: #1f2833;
}
input:disabled, select:disabled { background: #f5f7fa; color: #6b7686; }
input:focus, select:focus {
  outline: 2px solid #0f2c4a;
  outline-offset: 1px;
  border-color: #0f2c4a;
}
.field.error input, .field.error select { border-color: #c02b2b; background: #fff5f5; }
.msg { font-size: 0.78rem; }
.msg.error { color: #c02b2b; }
.msg.hint { color: #6b7686; }
</style>
