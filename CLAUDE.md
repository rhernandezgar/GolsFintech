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
├── CLAUDE.md, PROGRESS.md, BUGS.md, SECURITY_CHECKLIST.md, README.md, .gitignore
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
6. **Argon2id** para contraseñas. **Prohibidos MD5 y SHA-1 como función de hash**: nada de
   integridad, firmas, huellas de documento ni derivación de claves o identificadores con
   ellos (SHA-256 sí, para integridad de la bitácora y de documentos). La prohibición es
   sobre la función de hash, **no sobre HMAC**: véase 6.3.
7. Ninguna tarea se marca como terminada si sus pruebas no pasan.
8. Mensajes de error genéricos al cliente (sin traza, sin nombres de tabla/columna);
   detalle técnico solo en los registros del servidor.
9. Endpoints autenticados por defecto; excepciones declaradas explícitamente.

### 6.1 Llave de `PiiHasher` — se aparta del diseño documentado

`curp_hash` y `rfc_hash` no se calculan con SHA-256 a secas, como dice el comentario de
la migración de T2, sino con **HMAC-SHA-256** con llave
(`Infrastructure/Security/PiiHasher`, llave en `config('security.pii_hash_key')`).

**Por qué se aparta:** el espacio de CURP válidas es pequeño y enumerable, así que un
SHA-256 sin llave se revierte por fuerza bruta con solo obtener una copia de la tabla —el
escenario contra el que existe el cifrado de columna—. Con HMAC eso no es viable, y el
hash sigue siendo SHA-256 y determinista, que es lo que la columna necesita. Aprobado por
el usuario en T3.

**La llave no se rota sin migración de datos.** El hash es determinista *respecto a esa
llave*: si cambia, todo `curp_hash` y `rfc_hash` ya escrito deja de corresponder a su
dato. Rotarla sin migrar deja las búsquedas por CURP y RFC en vacío, `existsWithCurp()`
deja de detectar duplicados y un mismo prospecto puede darse de alta dos veces sin que el
sistema lo note. **Rotarla exige recalcular todos los hashes**: descifrar `curp`/`rfc`,
recalcular con la llave nueva y reescribir, en una migración transaccional.

Por eso **la llave de hash no es `APP_KEY`**: `APP_KEY` se rota como operación normal y
arrastraría los hashes. `PII_HASH_KEY` la toma como respaldo solo para no romper el
entorno local; en producción se define aparte. **Vive en el vault, nunca en el `.env` de
producción**, ni en el código ni en un commit.

### 6.2 `BUGS.md` y `SECURITY_CHECKLIST.md` — dos archivos, dos naturalezas

Mezclarlos fue un error: un control preventivo que nadie ha comprobado y un defecto real
reproducible no son la misma cosa, y presentarlos juntos como "vulnerabilidades
encontradas" resta credibilidad a los dos.

**`BUGS.md` — hallazgos verificados sobre este repositorio.** Es lo que se muestra como
evidencia de auditoría. Solo entra ahí lo que provenga de:

1. la salida de `composer audit`, `npm audit` o un análisis estático **ejecutado sobre
   este repositorio**;
2. un defecto de seguridad detectado en el código real y **reproducible**, incluidos los
   que introduzca y corrija el propio asistente;
3. un reporte del usuario sobre algo observado.

Cada entrada indica la **fecha** y **cómo se detectó** (comando o procedimiento). **Si no
se puede señalar la evidencia, no va en `BUGS.md`**: basta una entrada indemostrable para
que el archivo entero deje de servir como evidencia.

**`SECURITY_CHECKLIST.md` — controles preventivos que exige el diseño.** Columnas: `ID |
Control | Origen (fase de diseño) | Tarea que lo implementa (T#) | Estado | CWE / OWASP |
Verificación`. La verificación indica qué comprueba que el control quedó implementado,
idealmente la sección correspondiente de `scripts/verificar_avance.sh`; cuando el script
todavía no lo cubre, se dice explícitamente y se nombra la prueba que lo acreditará.

**Reglas comunes:**

- **Nunca se borra una entrada de ninguno de los dos.** Las resueltas se marcan como
  resueltas; los controles implementados se marcan como implementados.
- **Un control del checklist no se mueve a `BUGS.md` al implementarse:** se marca como
  implementado en su sitio. Solo pasa a `BUGS.md` si más adelante se descubre que quedó
  mal implementado y eso constituye un hallazgo real y reproducible, como entrada nueva
  que cita el ID del control.
- Los identificadores `VUL-xx` se conservan tal cual: están citados en los documentos
  entregados de las tres fases y la trazabilidad con ellos debe mantenerse. La serie es
  **continua entre ambos archivos** y ningún número se reutiliza. VUL-01 a VUL-08 son
  controles preventivos (Fase 3 §3.4, redactados como escenario ilustrativo, no como
  análisis de este código); VUL-09 en adelante son hallazgos verificados.

### 6.3 SHA-1 como función de hash frente a HMAC-SHA-1

La regla 6 prohíbe SHA-1 **como función de hash**. No prohíbe **HMAC-SHA-1**, y la
distinción es criptográfica, no una excepción de conveniencia.

**Por qué SHA-1 pelado está prohibido.** Su resistencia a colisiones está rota en la
práctica: SHAttered (2017) produjo dos PDF distintos con el mismo hash, y el ataque de
prefijo elegido (2020) lo abarató hasta hacerlo asequible. Todo uso que dependa de que dos
entradas distintas no puedan compartir hash —integridad de la bitácora, huella de un
documento, una firma, derivar una clave o un identificador— queda comprometido.

**Por qué HMAC-SHA-1 es otra cosa.** La seguridad de HMAC no descansa en la resistencia a
colisiones de la función interna, sino en que se comporte como una función pseudoaleatoria
con la llave. Los ataques de colisión conocidos contra SHA-1 **no se trasladan a
HMAC-SHA-1**, que sigue sin romperse y que NIST SP 800-131A mantiene admitido para
autenticación de mensajes. Prohibirlo por el nombre sería confundir el algoritmo con el
modo en que se usa.

**Qué significa esto en la práctica:** `sha1($x)` no se escribe nunca;
`hash_hmac('sha1', $x, $key)` es aceptable cuando lo imponga la interoperabilidad con un
tercero. No hay hoy ningún uso de HMAC-SHA-1 en el proyecto, y no se introduce uno sin una
razón de interoperabilidad concreta: pudiendo elegir, se elige SHA-256.

**Aviso sobre la verificación #2 de `scripts/verificar_avance.sh`.** Su grep busca
`sha1(` y también `'sha1'` / `"sha1"`, de modo que marcaría `hash_hmac('sha1', ...)` como
infracción aunque esta sección lo admita. Hoy no molesta porque no hay ningún HMAC-SHA-1 en
el código. **Si alguna vez hace falta introducir uno, hay que avisar al usuario de que el
script lo marcará y dejar que él decida si se ajusta** (sección 9): el asistente no cambia
ese criterio por su cuenta.

### 6.4 TOTP con SHA-256 — compromiso operativo que hay que reevaluar

El segundo factor (`Infrastructure/Security/TotpAuthenticator`, T5) usa **HMAC-SHA-256** y
no el HMAC-SHA-1 habitual en TOTP. RFC 6238 §1.2 contempla expresamente SHA-256, y el
parámetro `algorithm=SHA256` del URI `otpauth://` se lo comunica al autenticador.

Conviene ser preciso sobre el motivo, ahora que 6.3 fija la distinción: **HMAC-SHA-1 no
habría sido inseguro aquí.** La elección de SHA-256 es de higiene —no dejar el literal
`sha1` en el árbol ni discutir caso por caso—, no una corrección de una debilidad real.

**El costo es de compatibilidad, y es serio.** Muchos autenticadores ignoran el parámetro
`algorithm` y calculan siempre con SHA-1, **Google Authenticator entre ellos**: mostrarían
códigos que este servidor rechaza, sin ningún mensaje que explique por qué. Aegis, FreeOTP
y 1Password sí lo respetan.

**Por eso, si el proyecto llega a tener usuarios reales, la decisión se reevalúa.** El
motivo no es de seguridad: es que un segundo factor que la mayoría de la gente no puede
usar con la aplicación que ya tiene instalada empuja a desactivarlo, a pedir excepciones o
a apuntar el código en cualquier sitio. Un 2FA con SHA-1 que todo el mundo usa protege más
que uno con SHA-256 que la gente rodea. El algoritmo vive en
`config('security.totp.algorithm')` precisamente para que ese cambio sea de configuración
y no de código; cambiarlo **invalida los secretos ya dados de alta**, que habría que
volver a emitir.


### 6.5 Qué detecta la cadena de la bitácora y qué no — riesgo residual aceptado

El encadenamiento SHA-256 de `audit_logs` no protege contra todo, y la diferencia entre lo
que detecta y lo que no es una propiedad del diseño, no un defecto de la implementación.
Se escribe aquí porque un control cuyo alcance no está dicho se acaba usando como si no
tuviera límites.

**Lo que detecta.** `audit:verify-chain` comprueba dos invariantes por registro: que su
`previous_hash` sea el `current_hash` del que lo precede, y que su hash recalculado
coincida con el almacenado. Con las dos se detecta cualquier **manipulación parcial**:

- alterar el contenido de un registro —incluidos `ip_address` y `metadata`, que están
  dentro del material del hash precisamente por ser lo que un atacante querría retocar—;
- borrar un registro intermedio, o el primero;
- insertar un registro entre dos existentes, **aunque el atacante calcule bien los hashes
  del que inserta**: no puede arreglar al sucesor sin rehacer todo el tramo posterior.

Hace falta comprobar las dos. Con solo el recálculo de contenido se detecta la alteración
y se escapan el borrado y la inserción, porque en esos dos casos cada fila superviviente
sigue siendo coherente consigo misma; lo que cambia es la costura entre registros.

**Lo que NO detecta, y es lo que hay que tener presente:**

1. **El truncado del final.** Si se borran los últimos registros, lo que queda sigue
   siendo una cadena perfectamente coherente. No hay nada dentro de la tabla que diga
   cuántos registros debería haber.
2. **La reescritura completa del tramo final.** El hash **no lleva llave** y el material
   es público, así que quien tenga escritura sobre la tabla puede recalcular una cadena
   entera coherente desde el punto que quiera manipular hasta el final.

Las dos son la misma limitación de fondo: la cadena acredita la **consistencia interna**
del registro, no su **completitud**. Contra eso solo sirve un punto de referencia externo.

**Mitigación adoptada.** `audit:verify-chain` imprime el hash de la punta y acepta
`--expect-tip`: se guarda ese hash **fuera de esta base de datos** —en el sistema de
integración continua, en un vault o en el acta de una revisión— y se le pasa al comando.
Si la bitácora se truncó o se reescribió, la punta no coincide y el comando termina en 2.
Es una mitigación real y barata, y es lo que se ha implementado.

**Por qué se acepta el riesgo residual en lugar de cerrarlo.** Las tres formas de cerrarlo
del todo se descartaron, cada una por su motivo:

- **Firmar la cadena con HMAC y llave en el vault** eliminaría la reescritura completa,
  pero **rompe que un auditor externo pueda recalcular la cadena**, que es una propiedad
  que sí queremos conservar: hoy `AuditLogController` publica `previous_hash` y
  `current_hash` justamente para eso. Un control de integridad que solo el propio sistema
  puede verificar vale menos como evidencia frente a un tercero.
- **Almacenamiento WORM** y **publicación periódica de la punta en un tercero** resuelven
  el problema, pero se apartan de la arquitectura de la Fase 2 y meten infraestructura
  nueva (sección 8: no se introducen componentes fuera del diseño).

**Aprobado por el usuario el 2026-08-22, tras T8.** Queda registrado también en
`SECURITY_CHECKLIST.md` como riesgo residual aceptado con su justificación: es la clase de
decisión que una auditoría quiere ver documentada, no silenciada.

**Qué reabriría la decisión.** Que la bitácora pase a tener valor probatorio frente a un
tercero —una autoridad, un litigio— o que el modelo de amenazas incorpore al administrador
de la base de datos como atacante. En ese momento el ancla externa deja de bastar, porque
depende de que alguien la haya guardado y la compare.

## 7. Regla operativa de continuidad

**Al iniciar sesión, lee `PROGRESS.md`, `BUGS.md` y `SECURITY_CHECKLIST.md` completos y
contrasta su contenido contra `git log --oneline` y `git status`. Si no coinciden, avísale
al usuario antes de continuar: el código manda sobre el archivo.**

Además:
- No se declara una tarea terminada si no está commiteada. Sin commit, está en progreso.
- El estado se reporta con evidencia de comandos (`git log --oneline`, `ls`, salida de
  pruebas), nunca de memoria de la conversación.
- Si una tarea falla o queda a medias, se registra tal cual en `PROGRESS.md`.
- `PROGRESS.md` se actualiza al terminar cada tarea **y también a mitad de tareas largas**.
  El campo "Siguiente paso pendiente" debe ser accionable sin contexto: archivo, función
  y qué falta exactamente.
- Commit al cerrar cada tarea con el prefijo `T#:`.
- Nunca se borran entradas de `PROGRESS.md`, `BUGS.md` ni `SECURITY_CHECKLIST.md`.
- Todo defecto de seguridad **verificado** se registra en `BUGS.md`, incluidos los que
  introduzca y corrija el propio asistente; los controles preventivos del diseño van en
  `SECURITY_CHECKLIST.md`. El criterio de cada archivo esta en la seccion 6.2.

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

**Única excepción — la lista de palabras de la verificación #8** (convención de idioma,
`LANG_WORDS` en el bloque de higiene transversal): el asistente **sí** puede ampliarla
cuando aparezca un término del dominio que se haya colado en español y la lista todavía no
cubra. Ampliarla no es modificar el criterio del script, es completarlo: el criterio
—«todo el código en inglés», sección 4— no cambia. Solo se **añaden** palabras; quitar una
palabra, cambiar las excepciones (RFC, CURP, INE, RENAPO), alterar los directorios que se
revisan o cualquier otro cambio al script sigue requiriendo autorización del usuario.

Se ejecuta con `bash scripts/verificar_avance.sh`. No usa `set -e` a propósito: un fallo
individual no debe abortar la auditoría.
