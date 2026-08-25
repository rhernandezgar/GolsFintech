<script setup>
import AppHeader from './components/AppHeader.vue'
import StepIndicator from './components/StepIndicator.vue'
</script>

<template>
  <div class="app-shell">
    <AppHeader />
    <StepIndicator />
    <main class="app-main">
      <router-view v-slot="{ Component }">
        <component :is="Component" />
      </router-view>
    </main>
    <footer class="app-footer">
      <small>GolsFintech &middot; La simulacion es informativa hasta su autorizacion.</small>
    </footer>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: #f8fafc;
}
.app-main {
  flex: 1;
  padding: 2rem 1.5rem;
  max-width: 720px;
  width: 100%;
  margin: 0 auto;
}
.app-footer {
  padding: 1rem 1.5rem;
  text-align: center;
  color: #6b7686;
  border-top: 1px solid #e2e6ec;
  background: #fff;
}
</style>
