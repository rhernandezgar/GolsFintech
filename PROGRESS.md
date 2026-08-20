# PROGRESS.md — Bitácora de avance de GolsFintech

Entrada más reciente arriba. Ninguna entrada se borra.
Regla: una tarea no está terminada si no está commiteada.

---

## [2026-08-20 22:26] T2 — Migraciones de las 9 tablas
**Estado:** en progreso (bloqueado por credenciales de MySQL)
**Commit:** ver `git log --oneline` (commit `T2 (en progreso): ...`)
**Contexto:** Crear las nueve tablas del diseno con los nombres exactos acordados,
incluida `audit_logs` con referencia polimorfica (`prospect_id` + `affected_entity` +
`affected_entity_id`) en lugar de una llave foranea por entidad.
**Cambios:**
- 9 migraciones nuevas en `backend/database/migrations/`:
  `2026_08_20_221000_create_prospects_table.php`,
  `..._221100_create_identity_documents_table.php`,
  `..._221200_create_identity_validations_table.php`,
  `..._221300_create_credit_applications_table.php`,
  `..._221400_create_credit_simulations_table.php`,
  `..._221500_create_customers_table.php`,
  `..._221600_create_credit_lines_table.php`,
  `..._221700_create_cards_table.php`,
  `..._221800_create_audit_logs_table.php`.
- `backend/tests/Feature/DatabaseSchemaTest.php`: comprueba que existan las nueve tablas,
  que las columnas clave usen los nombres acordados y que `audit_logs` NO tenga una
  columna `*_id` por entidad (la referencia debe ser polimorfica).
- `backend/phpunit.xml`: la suite de pruebas apunta a MySQL sobre la base
  `golsfintech_test` (antes SQLite en memoria; la extension `pdo_sqlite` no esta
  instalada en este servidor).
**Decisiones de esquema tomadas:**
- `curp` y `rfc` son TEXT porque se guardaran cifrados a nivel de columna; se acompanan
  de `curp_hash` / `rfc_hash` (SHA-256) para buscar y detectar duplicados sin descifrar.
- `cards` guarda `tokenized_card_number` y `last_four`; el PAN completo no tiene columna.
- `audit_logs` no lleva `timestamps()` de Eloquent: el registro es inmutable y su unica
  marca temporal es `event_at`, que forma parte del material del hash.
- Cada tabla lleva un `public_id` UUID para exponerse en la API sin filtrar identificadores
  secuenciales.
**Verificacion:** `php -l` sin errores de sintaxis en las nueve migraciones.
`php artisan migrate:status` **falla todavia**: `SQLSTATE[HY000] [1045] Access denied for
user 'golsfintech'@'localhost'`. La base y el usuario aun no existen.
**Siguiente paso pendiente:** el usuario debe crear en MySQL las bases `golsfintech` y
`golsfintech_test` con el usuario `golsfintech`@`127.0.0.1` de minimo privilegio, y poner
la contrasena en `backend/DB_PASSWORD` del archivo `backend/.env`. Hecho eso, ejecutar
`php artisan migrate` y `php artisan migrate:status` (deben listarse las 12 migraciones:
las 3 de Laravel mas las 9 del diseno) y `php artisan test --filter=DatabaseSchemaTest`.
**Notas:** No se ejecuto ningun comando contra MySQL: el usuario decidio crear la base y
el usuario el mismo, para que la credencial no pase por esta conversacion.

---

## [2026-08-20 22:16] T1 — Scaffold de backend, frontend y worker
**Estado:** completado
**Commit:** ver `git log --oneline` (commit con prefijo `T1:`)
**Contexto:** Primera tarea del plan. Levantar los tres proyectos con las versiones del
diseno, crear la estructura de carpetas hexagonal exigida, un `.gitignore` que impida que
entren secretos o dependencias al repositorio, y dejar la API respondiendo en el puerto
6060 sin tocar configuracion global del servidor.
**Cambios:**
- `backend/` — Laravel 13 (framework 13.26.1) via `composer create-project`. Se crearon
  `app/Domain/{Prospect,Credit,Identity,Audit,Port}`, `app/Application`,
  `app/Infrastructure/{Persistence,Http,Queue}` y `app/Http/{Controllers,Middleware,Requests}`
  con `.gitkeep`. Se elimino `database/database.sqlite` generado por el instalador.
- `backend/.env` y `.env.example` — `APP_NAME=GolsFintech`, `APP_URL=http://127.0.0.1:6060`,
  `DB_CONNECTION=mysql` (base `golsfintech`, aun sin crear: es T2) y
  `SESSION_DRIVER/CACHE_STORE/QUEUE_CONNECTION=redis`, conforme al stack de la Fase 1 §5.2.
- `frontend/` — Vue 3 + Vite (`npm create vite --template vue`) con `vue-router@4`, `pinia`
  y `axios`. Subcarpetas `src/{views,components,composables,router,stores}` con `.gitkeep`.
- `worker/` — Node 24 LTS, `type: module`, con `bullmq`, `ioredis` y `dotenv`. Estructura
  `src/{queues,processors,adapters}` y `src/index.js` como punto de entrada (todavia sin
  colas registradas: eso es T7). `.env.example` con las claves del worker.
- `.gitignore` en la raiz: ignora `docs/`, `.env` y variantes (excepto `.env.example`),
  `vendor/`, `node_modules/`, `dist/`, llaves (`*.pem`, `*.key`, `*.p12`), `auth.json`,
  `storage/` de Laravel y artefactos de editor.
- `README.md` en la raiz con arquitectura, versiones verificadas y puesta en marcha.
**Verificacion:**
- `curl -I http://127.0.0.1:6060` -> `HTTP/1.1 200 OK` (`X-Powered-By: PHP/8.4.24`),
  servido por `php artisan serve --host=127.0.0.1 --port=6060`.
- `php artisan --version` -> `Laravel Framework 13.26.1`.
- `node -v` -> `v24.19.0`; `node worker/src/index.js` arranca e imprime la configuracion.
- `php artisan test` -> 2 pruebas, 2 aprobadas, 2 aserciones.
- `npm run build` en `frontend/` -> compilacion correcta en 202 ms.
- `git diff --cached --name-only | grep -cE "node_modules|/vendor/"` -> `0`; el unico
  archivo `.env*` en el stage es `.env.example` (backend y worker).
**Siguiente paso pendiente:** T2 — Migraciones. Falta (a) crear la base `golsfintech` y
un usuario MySQL de minimo privilegio (requiere consultar credenciales con el usuario, ya
que no hay acceso root confirmado), (b) escribir las 9 migraciones en
`backend/database/migrations/` con los nombres exactos de la seccion 3 del encargo
(`prospects`, `identity_documents`, `identity_validations`, `credit_applications`,
`credit_simulations`, `customers`, `credit_lines`, `cards`, `audit_logs` con referencia
polimorfica `prospect_id` + `affected_entity` + `affected_entity_id`), y (c) comprobar con
`php artisan migrate:status`.
**Notas:**
- Decisiones confirmadas por el usuario: se trabaja con **PHP 8.4.24** (el diseno menciona
  8.5; Laravel 13 requiere `^8.3`) y `docs/` **no** se versiona.
- No hay Nginx instalado; la API se publica con `php artisan serve`, que es un proceso del
  proyecto y no altera configuracion global. Si mas adelante se requiere Nginx o PHP-FPM,
  se consultara antes.
- El instalador de Laravel dejo `SESSION_DRIVER=database` con SQLite por defecto y la
  extension `pdo_sqlite` no esta instalada en este servidor; se cambio a Redis, que si
  esta disponible y ademas es lo que indica el diseno.

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
