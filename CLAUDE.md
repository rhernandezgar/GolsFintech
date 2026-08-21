# CLAUDE.md — GolsFintech

Memoria permanente del proyecto. Se carga automáticamente en cada sesión.
Documentación y mensajes al usuario final en español; **todo el código en inglés**.

## 1. Qué es este proyecto

GolsFintech es una aplicación Fintech para la **gestión de solicitudes de crédito simple**.
El prospecto elige entre capturar sus datos manualmente o subir su identificación oficial
para extracción automática por OCR; el sistema valida su identidad contra INE y RENAPO,
aplica un motor de reglas para determinar tipo de crédito y capacidad de pago, genera una
simulación y, si se acepta, da de alta al cliente con su línea de crédito y su tarjeta
tokenizada. Todo evento del proceso queda en una bitácora de auditoría append-only con
encadenamiento SHA-256.

El diseño completo está en `docs/` (Fase 1: análisis y diseño; Fase 2: arquitectura;
Fase 3: desarrollo seguro). **El diseño no se rediseña: se implementa.**

Arquitectura: monolito modular con **hexagonal (puertos y adaptadores)** en el backend,
más un worker asíncrono independiente en Node.js que solo consume cola (sin endpoints).

## 2. Stack con versiones exactas (verificadas en este servidor)

| Componente | Versión | Nota |
|---|---|---|
| SO | Ubuntu Server 24.04 LTS | comparte servidor con otros servicios |
| PHP | 8.4.24 (CLI) | el diseño menciona 8.5; el servidor tiene 8.4.24. Laravel 13 requiere `^8.3`. No se instala PHP 8.5 sin autorización del usuario |
| Laravel | 13.x (framework 13.26.x) | backend |
| Composer | 2.10.2 | |
| Vue.js | 3 (Composition API) | frontend SPA |
| Node.js | v24.19.0 LTS | worker |
| npm | 11.17.0 | |
| BullMQ | sobre Redis | cola de OCR y notificaciones |
| MySQL | 8.0.46 | 127.0.0.1:3306 |
| Redis | 7.0.15 | 127.0.0.1:6379 |

**Puerto de la aplicación: 6060** (`127.0.0.1:6060`). No se modifica configuración global
de Nginx, PHP-FPM ni MySQL sin consultar antes al usuario.

## 3. Estructura de carpetas

```
/opt/golsfintech
├── CLAUDE.md, PROGRESS.md, BUGS.md, README.md, .gitignore
├── docs/                         # Fases 1, 2 y 3 (diseño; no se modifican)
├── backend/                      # Laravel 13 / PHP 8.4
│   ├── app/
│   │   ├── Domain/               # núcleo: SIN dependencias de Laravel
│   │   │   ├── Prospect/         # entidades y objetos de valor
│   │   │   ├── Credit/           # motor de reglas
│   │   │   ├── Identity/
│   │   │   ├── Audit/
│   │   │   └── Port/             # interfaces (puertos)
│   │   ├── Application/          # casos de uso (orquestación)
│   │   ├── Infrastructure/       # adaptadores: Eloquent, HTTP, colas
│   │   └── Http/                 # controladores, middleware, requests
│   ├── database/migrations/
│   └── tests/{Unit,Feature}/
├── frontend/                     # Vue.js 3 (Composition API)
│   └── src/{views,components,composables,router,stores}/
└── worker/                       # Node.js 24 LTS + BullMQ
    └── src/{queues,processors,adapters}/
```

**Regla de dependencia (hexagonal):** `Domain` no importa nada de `Application`,
`Infrastructure`, `Illuminate` ni `App\Models`. `Application` puede importar `Domain`.
`Infrastructure` puede importar ambos. Nunca al revés.
Verificación: `grep -rl "use Illuminate" backend/app/Domain` no devuelve nada.

## 4. Convención de nombres — TODO el código en inglés

Clases, métodos, variables, tablas, columnas, rutas, claves JSON, archivos y ramas en
inglés. **No se traducen:** RFC, CURP, INE, RENAPO. En español van únicamente: mensajes
al usuario final, comentarios que expliquen reglas de negocio y la documentación `.md`.

| Diseño (ES) | Clase | Tabla |
|---|---|---|
| PROSPECTO | `Prospect` | `prospects` |
| DOCUMENTO_IDENTIDAD | `IdentityDocument` | `identity_documents` |
| VALIDACION_IDENTIDAD | `IdentityValidation` | `identity_validations` |
| SOLICITUD_CREDITO | `CreditApplication` | `credit_applications` |
| SIMULACION_CREDITO | `CreditSimulation` | `credit_simulations` |
| CLIENTE | `Customer` | `customers` |
| LINEA_CREDITO | `CreditLine` | `credit_lines` |
| TARJETA | `Card` | `cards` |
| BITACORA_AUDITORIA | `AuditLog` | `audit_logs` |

**Columnas clave:** `prospect_id`, `full_name`, `monthly_income`, `document_type`,
`ocr_result`, `ine_status`, `renapo_status`, `credit_type`, `application_status`,
`proposed_amount`, `estimated_monthly_payment`, `customer_number`, `authorized_amount`,
`line_status`, `tokenized_card_number`, `card_status`, `affected_entity`,
`affected_entity_id`, `event_type`, `event_at`, `previous_hash`, `current_hash`.

**Puertos** (interfaces en `Domain/Port/`): `ProspectRepository`, `DocumentRepository`,
`OcrService`, `IdentityValidator`, `CardIssuer`, `AuditLogger`, `NotificationSender`.

**Vistas de Vue** (`frontend/src/views/`), una por pantalla del diseño:

| Pantalla | Archivo |
|---|---|
| P1 Inicio / elección de método | `WelcomeView.vue` |
| P2 Captura manual de datos | `ProspectDataFormView.vue` |
| P3 Carga de identificación + OCR | `DocumentUploadView.vue` |
| P4 Resultado de validación | `VerificationResultView.vue` |
| P5 Simulación del crédito | `CreditSimulationView.vue` |
| P6 Confirmación de autorización | `AuthorizationConfirmedView.vue` |
| P7 Consulta del cliente | `CustomerLookupView.vue` |

**Roles RBAC:** `prospect`, `customer`, `admin`, `auditor`, `risk_analyst`.

## 5. Bitácora de auditoría — patrón obligatorio

`audit_logs` usa **referencia polimórfica**, no una llave foránea por entidad:
`prospect_id` (ancla que persiste todo el ciclo de vida, incluso tras convertirse en
cliente) + `affected_entity` (nombre de la entidad) + `affected_entity_id`. Además
`event_type`, `actor`, `ip_address`, `event_at`, `previous_hash` y `current_hash` para
el encadenamiento SHA-256. **No se rediseña.** Append-only: sin UPDATE ni DELETE.

## 6. Reglas de seguridad no negociables

1. Nunca registrar CURP, RFC ni número de tarjeta **en claro** en logs ni en la bitácora.
2. El número de tarjeta va **tokenizado**; la aplicación no almacena el PAN completo.
3. Secretos solo en `.env` o vault, **nunca** en el código ni en un commit.
   Verificar `.gitignore` antes de cada commit.
4. Toda validación del cliente se **replica obligatoriamente en el servidor**.
5. Consultas mediante ORM o sentencias preparadas; **nada de SQL concatenado**.
6. **Argon2id** para contraseñas. **Prohibidos MD5 y SHA-1** para cualquier propósito de
   seguridad (SHA-256 sí, para integridad de la bitácora y de documentos).
7. Ninguna tarea se marca como terminada si sus pruebas no pasan.
8. Mensajes de error genéricos al cliente (sin traza, sin nombres de tabla/columna);
   detalle técnico solo en los registros del servidor.
9. Endpoints autenticados por defecto; excepciones declaradas explícitamente.

## 7. Regla operativa de continuidad

**Al iniciar sesión, lee `PROGRESS.md` y `BUGS.md` completos y contrasta su contenido
contra `git log --oneline` y `git status`. Si no coinciden, avísale al usuario antes de
continuar: el código manda sobre el archivo.**

Además:
- No se declara una tarea terminada si no está commiteada. Sin commit, está en progreso.
- El estado se reporta con evidencia de comandos (`git log --oneline`, `ls`, salida de
  pruebas), nunca de memoria de la conversación.
- Si una tarea falla o queda a medias, se registra tal cual en `PROGRESS.md`.
- `PROGRESS.md` se actualiza al terminar cada tarea **y también a mitad de tareas largas**.
  El campo "Siguiente paso pendiente" debe ser accionable sin contexto: archivo, función
  y qué falta exactamente.
- Commit al cerrar cada tarea con el prefijo `T#:`.
- Nunca se borran entradas de `PROGRESS.md` ni de `BUGS.md`.
- Todo defecto de seguridad encontrado se registra en `BUGS.md`, incluidos los que
  introduzca y corrija el propio asistente.

## 8. Qué no hacer

- No introducir microservicios, colas adicionales ni librerías pesadas fuera del diseño.
- No cambiar los nombres de tablas, columnas, puertos ni vistas definidos arriba.
- No tocar configuración global del servidor sin consultar al usuario.

## 9. `scripts/verificar_avance.sh` — script inmutable

Audita el avance real contra el **plan T1–T12**, no contra el código escrito. Su criterio
sale del plan; que una tarea no iniciada salga `[FALTA]` es el resultado correcto.

**Una vez commiteado, el asistente no lo modifica por su cuenta.** Si el script marca mal
algo que sí existe, se le informa al usuario **qué comando falla y por qué**, y el usuario
decide si se ajusta. Nunca se edita en la misma sesión en que se trabaja la tarea que ese
cambio afectaría: ajustar el medidor mientras se trabaja lo medido lo invalida.

Se ejecuta con `bash scripts/verificar_avance.sh`. No usa `set -e` a propósito: un fallo
individual no debe abortar la auditoría.
