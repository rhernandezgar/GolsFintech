import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * El backend escucha en 127.0.0.1:6060 (CLAUDE.md seccion 2). En desarrollo la
 * SPA se sirve desde otro puerto, asi que `/api` se reenvia por proxy en vez de
 * apuntar axios al host del backend.
 *
 * No es solo comodidad: con el proxy, el navegador ve UN solo origen. Sin el
 * habria que abrir CORS en el backend para el puerto de desarrollo, que es
 * configuracion de produccion escrita para resolver un problema de desarrollo.
 */
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:6060',
        changeOrigin: true,
      },
    },
  },
})
