<script setup>
defineProps({
  variant: { type: String, default: 'info' },
  title: { type: String, default: '' },
})
</script>

<template>
  <div class="alert" :class="variant" role="alert">
    <p v-if="title" class="title">{{ title }}</p>
    <p class="body"><slot /></p>
  </div>
</template>

<style scoped>
.alert {
  border-radius: 4px;
  padding: 0.75rem 1rem;
  margin-bottom: 1rem;
  border-left: 4px solid #ccc;
  background: #f5f7fa;
  color: #2e3846;
}
.alert.info    { border-color: #0f2c4a; background: #eef2f7; }
.alert.success { border-color: #7ac74f; background: #eef8e5; }
.alert.warning { border-color: #f2b134; background: #fdf3dc; }
.alert.error   { border-color: #c02b2b; background: #fdecec; color: #7a1a1a; }
.title { margin: 0 0 0.25rem; font-weight: 600; }
.body { margin: 0; font-size: 0.9rem; line-height: 1.4; }
</style>
