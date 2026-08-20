# PROGRESS.md — Bitácora de avance de GolsFintech

Entrada más reciente arriba. Ninguna entrada se borra.
Regla: una tarea no está terminada si no está commiteada.

---

## [2026-08-20 22:02] T0 — Archivos de control del proyecto
**Estado:** completado
**Commit:** ver `git log --oneline` (commit "T0: archivos de control...")
**Contexto:** Antes de escribir código de aplicación, el usuario pidió crear los tres
archivos de control que sostienen la continuidad entre sesiones: `CLAUDE.md` (memoria
permanente cargada automáticamente), `PROGRESS.md` (bitácora de avance) y `BUGS.md`
(registro de vulnerabilidades). En intentos anteriores se reportó como terminado trabajo
inexistente; estos archivos, contrastados contra `git log`, son la defensa contra eso.
**Cambios:** `CLAUDE.md` (157 líneas), `PROGRESS.md`, `BUGS.md` creados en la raíz.
Se leyeron los tres documentos de diseño en `docs/` (Fases 1, 2 y 3) para extraer el
stack, el catálogo de servicios MS-01..MS-08, las siete pantallas y los controles de
seguridad exigidos.
**Verificación:**
- `ls -1 CLAUDE.md PROGRESS.md BUGS.md` lista los tres archivos.
- `wc -l CLAUDE.md` = 157 (límite: 200).
- `git log --oneline` muestra el commit de esta tarea.
**Siguiente paso pendiente:** T1 — Scaffold. Crear `backend/` con Laravel 13 vía
`composer create-project laravel/laravel backend`, `frontend/` con Vue 3 vía
`npm create vite@latest frontend -- --template vue`, `worker/` con `npm init` + BullMQ,
y `.gitignore` en la raíz. Levantar la API con `php artisan serve --host=127.0.0.1
--port=6060` y comprobar con `curl -I http://127.0.0.1:6060`.
**Notas:**
- **Discrepancia de versión pendiente de decisión del usuario:** el diseño (Fase 3 §5.2)
  especifica PHP 8.5, pero el servidor tiene PHP 8.4.24. Laravel 13 requiere `^8.3`, por
  lo que 8.4.24 es suficiente. Instalar PHP 8.5 sería un cambio de configuración global
  del servidor y requiere autorización explícita. Mientras tanto se documenta 8.4.24 como
  la versión real en `CLAUDE.md`.
- No hay Nginx instalado en el servidor (`nginx: command not found`). Para T1 la API se
  publicará con `php artisan serve` en 127.0.0.1:6060, sin tocar configuración global.
- Node v24.19.0, MySQL 8.0.46 y Redis 7.0.15 verificados y disponibles localmente.
- `BUGS.md` arranca con VUL-01..VUL-08 tomados del reporte SAST de la Fase 3 (§3.4) en
  estado **Abierto**, no "Resuelto": el código todavía no existe, así que no puede
  afirmarse que estén corregidos. Cada uno queda ligado a la tarea que lo remediará.
