# PROGRESS.md — Bitácora de avance de GolsFintech

Entrada más reciente arriba. Ninguna entrada se borra.
Regla: una tarea no está terminada si no está commiteada.

---

## [2026-08-22 11:20] T7 (parcial 2/3) — Worker de Node: consume, reintenta y distingue el error definitivo
**Estado:** EN PROGRESO — segundo bloque de T7. **T7 sigue sin terminar:** falta la capa
HTTP (endpoint 202, consulta de seguimiento y callback del worker) y sus pruebas.
**Commit:** ver `git log --oneline` (commit `T7 (parcial): worker de Node...`)

**Que se escribio.** `worker/src/` deja de ser el scaffold de T1: 11 modulos con
`config.js` (valida al arrancar y muere si la configuracion no sirve), `logger.js`,
`redis.js`, dos colas, dos procesadores, cuatro adaptadores y un `index.js` que registra
los dos `Worker` y no abre ningun puerto.

**La decision que importa, y donde vive.** `processors/ocrProcessor.js` es el unico
archivo que decide que se reintenta:
- `timeout` -> se relanza tal cual; BullMQ aplica el backoff exponencial del trabajo.
- `unreadable` -> se traduce a `UnrecoverableError`, que corta los reintentos en seco.
- Carga incompleta -> tambien `UnrecoverableError`: es un defecto de quien encolo, no
  una indisponibilidad, y no mejora por reintentarse.

El fallo NO se reporta desde el procesador; lo reporta el manejador de `failed` de
`index.js` y solo cuando es definitivo. Repartirlo asi evita avisar dos veces del mismo
trabajo.

**Semantica de BullMQ 6.1.2 comprobada, no supuesta** (misma disciplina que con el
esquema de claves). Se encolo un trabajo que siempre falla y otro que lanza
`UnrecoverableError`, y se observo:
- `job.attemptsMade` es **0 en el primer intento** dentro del procesador, y ya viene
  incrementado en el manejador de `failed`.
- `UnrecoverableError` deja el trabajo en `failed` tras **un solo intento**, aunque
  `attempts` sea 3.
- **`job.finishedOn` queda fijado exactamente en el fallo definitivo** y sin fijar en los
  intermedios. Es mejor discriminador que contar intentos a mano, y es lo que usa
  `index.js`. Se habria acertado por casualidad contando intentos; con
  `UnrecoverableError` de por medio, no.

**Verificacion de punta a punta (2026-08-22, en este servidor).** El backend encolo con
el adaptador real (`OCR_DRIVER=bullmq`, `BullMqOcrService`) tres documentos, uno por
escenario, y el worker los consumio:

| escenario | intentos | desenlace observado |
|---|---|---|
| `extracted` | 1 | reportado al backend como `extracted` |
| `timeout` | **3** | `exhausted` tras agotar los reintentos |
| `unreadable` | **1** | `unreadable`, sin reintentar |

- **Backoff exponencial medido sobre las marcas de tiempo del registro:** 1.02 s entre el
  intento 1 y el 2, 2.01 s entre el 2 y el 3. Es exponencial de verdad, no declarado.
- **PT-03 acreditado:** `ZRANGE bull:ocr:failed 0 -1` -> los trabajos 2 y 3 siguen en la
  cola, y `HGET bull:ocr:2 data` devuelve la carga intacta
  (`document_public_id`, `storage_path`, `file_hash`, `job_ref`). Agotados los reintentos
  no se pierde nada: se puede reencolar sin volver a pedirle el documento al prospecto.
- La cola de notificaciones tambien se probo con el adaptador real: encolado desde
  `BullMqNotificationSender`, consumido por el worker, **y el destinatario no aparece en
  el registro** (regla 1).
- `npm test` en `worker/` -> **10 pruebas aprobadas** (`node --test`, sin Redis ni
  proveedor: los procesadores reciben dobles).
- `bash scripts/verificar_avance.sh` -> **T7: 8 ok / 2 falta**; higiene transversal, las 9
  en OK. Las dos que faltan son el endpoint 202 y su prueba.

**Como se devuelve el resultado al backend.** `adapters/backendClient.js`, en dos modos:
`log` (desarrollo, no llama a nadie) y `http` (client_credentials contra el propio
backend, con el token cacheado hasta poco antes de expirar). **El worker llama al backend;
nunca al reves.** Un puerto abierto en el worker seria una segunda superficie de ataque
sin la autenticacion ni la auditoria que tiene la API — por eso la verificacion en
negativo del script no es una formalidad. Hoy corre en modo `log` porque el endpoint que
recibe el resultado todavia no existe.

**Siguiente paso pendiente (accionable sin contexto):** la capa HTTP del backend.
1. `Http/Controllers/Api/IdentityDocumentController@store` — recibe el archivo, lo pasa
   por `UploadedDocumentStore`, ejecuta `UploadIdentityDocument::execute()` (que ya es
   asincrono y ya existe) y responde **202 Accepted con el identificador de seguimiento**,
   sin esperar al OCR (Fase 2, Figura 2a).
2. `Http/Requests/UploadIdentityDocumentRequest` — validacion replicada en servidor
   (regla 4).
3. Endpoint de consulta del seguimiento: `GET /identity-documents/{publicId}` con el
   `ocr_status` del documento.
4. `POST /internal/ocr-results` — lo que llama `backendClient` en modo `http`. Protegido
   con `EnsureClientIsResourceOwner` de Passport (client_credentials + scope), y aplica
   `completeExtraction()` o `failExtraction()` segun el `status` recibido.
5. Rutas en `routes/api.php`.
6. Pruebas que cierran T7: la del **202** (el script exige
   `assertStatus(202)|assertAccepted`), la de PT-03 en el backend, y `BullMqContractTest`
   —encolar desde PHP y que lo consuma un `Worker` real—, que es lo unico que sostiene el
   acoplamiento con el formato interno de BullMQ.

---

## [2026-08-22 10:35] T7 (parcial 1/2) — Productor BullMQ desde PHP, verificado contra un Worker real
**Estado:** EN PROGRESO — avance parcial commiteado tras un corte de conexion a mitad de
la tarea. **T7 no esta terminada.** Lo commiteado es el lado que encola; falta el worker
de Node y el endpoint 202.
**Commit:** ver `git log --oneline` (commit `T7: productor BullMQ...`)

**Que quedo funcionando, comprobado con comandos y no de memoria:**
- `BullMqQueue::add()` encola desde PHP en el formato nativo de BullMQ, en un unico
  script Lua (atomico: un trabajo con hash pero sin entrada en `wait` no lo procesa
  nadie, y uno en `wait` sin hash rompe al worker).
- `BullMqOcrService` y `BullMqNotificationSender`: adaptadores de los puertos `OcrService`
  y `NotificationSender` con driver `bullmq`, reintentos y backoff exponencial.
- `UploadedDocumentStore` + `DocumentUploadRejected`: controles de VUL-01 (tipo real por
  `finfo`, nombre generado, fuera de la raiz web, limite de 5 MB, hash SHA-256).
- Los drivers nuevos NO estan activos: `OCR_DRIVER` y `NOTIFICATION_DRIVER` siguen en
  `simulated`. Cambiar el transporte antes de que exista el worker dejaria trabajos
  encolados que nadie consume.

**Verificacion de esta entrega (2026-08-22, en este servidor):**
- `php artisan tinker` -> `BullMqQueue::add('probe-t7', ...)` devuelve `jobId=1` y deja en
  Redis las seis claves `bull:probe-t7:{id,1,wait,marker,meta,events}`.
- Un `Worker` de BullMQ 6.1.2 de verdad, ejecutado desde `worker/`, consumio ese trabajo:
  `CONSUMED id=1 name=probe.job data={"hello":"world"} attempts=3
  backoff={"type":"exponential","delay":1000}`. La interoperabilidad PHP -> Node esta
  probada de punta a punta, no supuesta.
- `PAO_DISABLE=1 php artisan test` -> **246 pruebas aprobadas, 587 aserciones**.
- `./vendor/bin/pint --dirty` -> limpio.

**Dos detalles no obvios que conviene dejar por escrito:**

1. **El prefijo de Redis de Laravel corrompia las claves de BullMQ.** Las conexiones de
   `config/database.php` anteponen `options.prefix` —aqui `golsfintech-database-`— a cada
   clave. Con el, el backend habria escrito en `golsfintech-database-bull:ocr:wait` y el
   worker de Node habria seguido mirando `bull:ocr:wait`: los trabajos se pierden **en
   silencio**, sin error en ninguno de los dos lados, que es la peor forma de fallar. Por
   eso hay una conexion dedicada `bullmq` con `'prefix' => ''` explicito, y el prefijo
   propio de BullMQ se configura aparte en `config/adapters.php`. Se detecto leyendo
   `config/database.php` **antes** de escribir el productor, y se comprobo despues
   volcando las claves reales.

2. **El formato de BullMQ 6.1.2 se observo, no se leyo.** El esquema de claves es interno
   de la libreria y la documentacion no lo fija como contrato. Se determino haciendo un
   `Queue.add` desde Node y volcando Redis entero para ver que escribio, y solo despues se
   replico desde PHP. Es acoplamiento explicito a una version: lo que impide que una
   actualizacion lo rompa en silencio es `BullMqContractTest`, **que todavia no existe** y
   es parte del trabajo pendiente.

**Nota metodologica relacionada (del chore de `suite_failed`):** que pao emite
`"result":"failed"` tampoco se supuso — se comprobo ejecutando la suite en rojo bajo pao y
mirando el JSON. Resulto ser `"failed"` para phpunit y `"fail"` para pint, que no es lo
mismo y no se habria acertado de memoria.

**Siguiente paso pendiente (accionable sin contexto):**

*(a) El worker de Node.* `worker/src/` solo tiene el scaffold de T1 (`index.js` que
imprime la configuracion) y tres `.gitkeep`. Falta escribir:
  - `worker/src/config.js` — lee `REDIS_URL`, `OCR_QUEUE_NAME`, `NOTIFICATION_QUEUE_NAME`
    y `BULLMQ_PREFIX` de `worker/.env`. **El prefijo tiene que coincidir con
    `config('adapters.bullmq.prefix')` del backend** (ver detalle 1).
  - `worker/src/queues/{ocrQueue,notificationQueue}.js` — conexion ioredis y nombres.
  - `worker/src/processors/ocrProcessor.js` — debe distinguir error reintentable de
    definitivo: `timeout` reintenta; `unreadable` lanza `UnrecoverableError` de BullMQ,
    que corta los reintentos en seco. El escenario viaja en `job_ref` con el formato
    `ocrsim-<escenario>-<16 hex>` que emite `BullMqOcrService::enqueueExtraction()`.
  - `worker/src/processors/notificationProcessor.js`.
  - `worker/src/adapters/` — adaptador falso de OCR y de notificaciones (`OCR_ADAPTER=fake`).
  - `worker/src/index.js` — registrar los dos `Worker`. **Sin servidor HTTP**: el worker
    solo consume de la cola, y la verificacion negativa del script lo comprueba
    (`express|createServer\(|fastify|\.listen\(` debe dar cero archivos).

*(b) El endpoint 202.* El caso de uso ya existe y ya es asincrono
(`Application/UseCase/Identity/UploadIdentityDocument::execute()`, que llama a
`enqueueExtraction` y devuelve). Falta la capa HTTP:
  - `Http/Controllers/Api/IdentityDocumentController@store` — recibe el archivo, lo pasa
    por `UploadedDocumentStore`, ejecuta el caso de uso y responde **202 Accepted con el
    identificador de seguimiento**, nunca esperando al OCR (Fase 2, Figura 2a).
  - `Http/Requests/UploadIdentityDocumentRequest` — validacion de servidor (regla 4).
  - Endpoint de consulta del estado del seguimiento.
  - Rutas en `routes/api.php`, dentro del grupo `auth:api`.
  - Callback de resultado para el worker, protegido con `EnsureClientIsResourceOwner` de
    Passport (token de client_credentials + scope).

*(c) Pruebas que faltan y que cierran T7:*
  - `BullMqContractTest` — encola desde PHP y lo consume un `Worker` de BullMQ real. Es lo
    unico que sostiene el acoplamiento del detalle 2.
  - Prueba del 202: el script exige `assertStatus(202)|assertAccepted` en `backend/tests`.
  - Prueba de PT-03: agotados los reintentos, la solicitud **no pierde los datos del
    prospecto**. Por eso los adaptadores encolan con `removeOnFail: false`.

**Estado de las nueve verificaciones de T7 en `verificar_avance.sh` al cerrar este
avance parcial:** solo pasa `node -v = v24`. Las otras ocho dependen del worker y del
endpoint, que es exactamente lo que queda pendiente.

---

## [2026-08-22 09:40] chore — La regla 6 distingue SHA-1 como hash de HMAC-SHA-1
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `chore: la regla 6 distingue...`)
**Origen:** correccion del usuario. La formulacion anterior —«Prohibidos MD5 y SHA-1 para
cualquier proposito de seguridad»— era demasiado amplia y criptograficamente imprecisa.

**El fondo del asunto.** La resistencia a colisiones de SHA-1 esta rota (SHAttered 2017;
prefijo elegido 2020), pero la seguridad de HMAC no descansa en esa propiedad, sino en que
la funcion se comporte como pseudoaleatoria con la llave. Los ataques conocidos **no se
trasladan a HMAC-SHA-1**, que NIST SP 800-131A mantiene admitido para autenticacion de
mensajes. Prohibirlo por el nombre confundia el algoritmo con el modo de uso.

**Cambios en `CLAUDE.md`:**
- Regla 6 reformulada: prohibido SHA-1 **como funcion de hash** (integridad, firmas,
  huellas de documento, derivacion de claves o identificadores). Remite a 6.3.
- **Seccion 6.3 nueva** — la distincion, con el porque de cada lado y la consecuencia
  practica: `sha1($x)` nunca; `hash_hmac('sha1', $x, $key)` aceptable si lo impone la
  interoperabilidad con un tercero. Hoy no hay ninguno en el proyecto.
- **Seccion 6.4 nueva** — el compromiso operativo del TOTP.

**Aviso registrado en 6.3, no resuelto:** la verificacion #2 de `verificar_avance.sh`
busca `sha1(` y tambien `'sha1'` / `"sha1"`, de modo que **marcaria `hash_hmac('sha1', ...)`
como infraccion aunque la regla nueva lo admita**. Hoy no molesta porque no hay ningun
HMAC-SHA-1 en el codigo, y por eso no se toca el script: la seccion 9 exige avisar al
usuario y dejarle la decision. Queda escrito para quien se lo encuentre.

**El TOTP sigue en SHA-256, como pidio el usuario**, pero el motivo se corrige en los
cuatro sitios que citaban la redaccion antigua: no se cambio a SHA-256 porque HMAC-SHA-1
fuese inseguro —no lo es—, sino por higiene, para no dejar el literal `sha1` en el arbol.
Decirlo de otro modo seria justificar una decision correcta con un argumento falso.
Ficheros: `Infrastructure/Security/TotpAuthenticator`, `config/security.php`,
`Auth/LoginController` y `TotpAuthenticatorTest`.

**VUL-10 anotado, no reescrito** (`BUGS.md`): el hallazgo **se mantiene** bajo la redaccion
nueva, porque `sha1()` es hash pelado y derivar un identificador depende justo de la
resistencia a colisiones que SHA-1 ya no tiene. Lo unico que cambia es que cita otra parte
de la regla. Se anadio la nota fechada; no se borro nada.

**Compromiso operativo del TOTP, documentado en 6.4 para que no se pierda:** Google
Authenticator ignora el parametro `algorithm` del URI otpauth y calcula siempre con SHA-1,
de modo que mostraria codigos que este servidor rechaza, sin mensaje que lo explique.
Aegis, FreeOTP y 1Password si lo respetan. **Si el proyecto llega a tener usuarios reales
hay que reevaluar la decision** —no por seguridad, sino porque un 2FA que la mayoria no
puede usar con la aplicacion que ya tiene instalada empuja a desactivarlo, a pedir
excepciones o a apuntar el codigo en cualquier sitio—. El algoritmo esta en
`config('security.totp.algorithm')` para que el cambio sea de configuracion; **cambiarlo
invalida los secretos ya emitidos**, que habria que volver a dar de alta.

**Verificacion:**
- `PAO_DISABLE=1 php artisan test` -> 246 aprobadas, 587 aserciones. Sin cambios de
  comportamiento: solo comentarios y documentacion.
- `./vendor/bin/pint --test` -> `passed`.

**Siguiente paso pendiente:** sin cambios — T7, worker de Node 24 con BullMQ.

## [2026-08-22 09:10] chore — El respaldo de suite_failed() cubre tambien el formato de pao
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `chore: el respaldo de suite_failed()...`)
**Autorizacion:** el usuario autorizo expresamente anadir el patron `"result":"failed"` al
respaldo de `suite_failed()` en `scripts/verificar_avance.sh`. Ningun otro criterio del
script se toco (CLAUDE.md seccion 9).

**Por que, en palabras del usuario:** aunque el codigo de salida ya cubre el caso, un
respaldo que no funciona bajo pao es un respaldo falso, y conviene que sea consistente en
ambos entornos.

**Comprobado antes de escribir el cambio.** No se dio por supuesto el formato: `pint` emite
`"result":"fail"` y `phpunit` bajo pao podia hacer lo mismo. Se provoco un fallo real con
una prueba temporal:

```
$ php artisan test --filter=TempFailingTest          # con pao
{"tool":"phpunit","result":"failed","tests":1,"passed":0,...}
$ echo $?
1
$ PAO_DISABLE=1 php artisan test --filter=TempFailingTest
  Tests:    1 failed (1 assertions)
$ echo $?
1
```

El patron que pidio el usuario —`"result":"failed"`— es exactamente el que emite pao. El
codigo de salida es 1 en ambos entornos, de modo que la comprobacion principal ya
funcionaba; lo que no funcionaba era el respaldo, que solo buscaba `Tests:.*failed` y
`FAILURES!`, ninguno de los cuales aparece en la linea JSON de pao.

**Cambio:** una sola alternativa mas en el `grep -qE` de `suite_failed()`, mas el
comentario que explica por que hay dos formatos que cubrir.

**Verificacion — los cuatro casos, con el codigo de salida forzado a 0 para aislar el
respaldo del criterio principal:**

| Salida | Suite | Respaldo |
|---|---|---|
| pao (JSON) | roja | detecta fallo |
| Collision | roja | detecta fallo |
| pao (JSON) | verde | no dispara |
| Collision | verde | no dispara |

Sin falso positivo en verde: la linea de una corrida verde de pao lleva `"result":"passed"`
y no incluye la clave `failed`, asi que el patron no la toca.

- `bash -n scripts/verificar_avance.sh` -> sin errores de sintaxis.
- `bash scripts/verificar_avance.sh` -> T5 y T6 siguen en OK; ningun cambio en los totales.
- La prueba temporal `tests/Unit/TempFailingTest.php` se elimino; no entra en el commit.

**Siguiente paso pendiente:** sin cambios — T7, worker de Node 24 con BullMQ.

## [2026-08-21 21:35] T5 — Autenticacion OAuth2 + PKCE + 2FA y RBAC de 5 roles
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `T5: ...`)
**Precedido por:** commit `dbb3728` (`chore: formato PSR-12 con Pint`), 46 archivos
reformateados antes de escribir codigo nuevo para que el reformateo no se mezclara con el.

**Decision de stack:** `laravel/passport` ^13.7 (con `league/oauth2-server` 9.4.1), no
Sanctum. Sanctum emite tokens pero no es un servidor OAuth2: no implementa el flujo de
codigo de autorizacion con PKCE que exige la Fase 3 §4.4.

**Lo que se implemento:**

- **Servidor OAuth2 con PKCE S256.** Endpoints bajo el prefijo del catalogo MS-01
  (`config('passport.path') = 'api/v1/auth'`): `GET|POST /auth/authorize`,
  `POST /auth/token`, `POST /auth/refresh`, `DELETE /auth/session`, mas
  `POST /auth/login`.
- **`Http/Middleware/EnforcePkceS256`**, aplicado a todo el grupo de Passport por
  `config('passport.middleware')`. Exige `code_challenge`; admite **solo S256** y rechaza
  `plain` y la **omision del metodo** (RFC 7636 §4.3 la resuelve como `plain`, de modo que
  aceptar la omision equivale a aceptar `plain`: la libreria por si sola lo aceptaba).
  Rechaza ademas `response_type=token` y `grant_type=implicit`.
- **Cliente publico.** `OAuthSpaClientSeeder` crea el cliente con `confidential: false`:
  sin secreto. `FirstPartyClient` omite la pantalla de consentimiento para el cliente de
  primera parte —preguntarle al usuario si autoriza a GolsFintech a acceder a GolsFintech
  no es una decision, y acostumbra a aprobar consentimientos sin leerlos—.
- **Flujo implicito descartado en el codigo**, con el porque escrito en
  `AuthorizationServiceProvider::configureOAuthServer()`, y comprobado por prueba
  (`the_implicit_grant_is_disabled_in_passport` mas dos que exigen el rechazo real de la
  peticion). Tambien se desactivan el flujo de contrasena y el de codigo de dispositivo;
  la migracion de `oauth_device_codes` se elimino y la tabla se elimino de la base.
- **2FA TOTP** en `Infrastructure/Security/TotpAuthenticator`, sin dependencia externa.
  Obligatorio para los tres perfiles administrativos; **un perfil administrativo sin
  segundo factor dado de alta queda fuera, no exento**. Secreto cifrado a nivel de columna.
- **RBAC de 5 roles con permisos por rol** en `Domain/Access/Role` y
  `Domain/Access/Permission`, traslado literal de la tabla de alcance de la Fase 3 §4.9.
  `Role::isReadOnly()` se **deriva** de los permisos: no puede contradecirlos.
- **Autorizacion a nivel de objeto** en `Policies/CreditApplicationPolicy`, con anclaje
  `users.prospect_id`. **Autorizacion por campo** para los ingresos declarados.
- **Argon2id** en `config/hashing.php` (64 MiB, 4 pasadas).
- `php artisan user:create` para el alta de perfiles administrativos. No hay endpoint de
  alta: no existe auto-registro de personal administrativo.

**Se aparta de lo habitual — el algoritmo del TOTP es SHA-256, no SHA-1.**
La implementacion corriente de TOTP usa HMAC-SHA-1, pero la regla de seguridad no
negociable 6 prohibe SHA-1 para cualquier proposito de seguridad. RFC 6238 §1.2 contempla
SHA-256 y el parametro `algorithm=SHA256` del URI otpauth lo transmite al autenticador.
**El costo es de compatibilidad:** Google Authenticator ignora ese parametro y calcula
siempre con SHA-1, de modo que mostraria codigos que este servidor rechaza. Aegis, FreeOTP
y 1Password si lo respetan. El algoritmo queda en `config('security.totp.algorithm')` por
si el operador necesita decidir otra cosa con el criterio a la vista. **Esto conviene
confirmarlo con el usuario antes de que haya usuarios reales dados de alta.**

**Un defecto propio, detectado por el script y registrado:** `LoginController` derivaba la
clave del contador de intentos con `sha1()`. Lo encontro la verificacion #2 de la higiene
transversal de `verificar_avance.sh`, no una revision manual. Corregido a
`hash('sha256', ...)` y registrado como **VUL-10** en `BUGS.md`. La misma corrida marco la
verificacion #9 por dos descripciones en espanol en la firma de `user:create`; se pasaron
a ingles (la ayuda de la linea de ordenes la lee quien opera el servidor, no el usuario
final). Las 9 verificaciones vuelven a `[OK]`.

**Una correccion de diseno a mitad de camino, que conviene conocer:** `EnforceReadOnlyRole`
se aplicaba primero a **todo** el grupo `api`. Con `isReadOnly()` derivado de los permisos,
eso dejaba fuera de servicio `POST /auth/refresh` y habria dejado tambien
`DELETE /auth/session`: **un auditor no habria podido cerrar su propia sesion** y habria
tenido que esperar a que su token caducara solo. Lo detecto la prueba
`the_refresh_token_renews_the_access_token` con un 403 inesperado. Dos cambios: (a) el
catalogo de permisos incorpora los de escritura del prospecto sobre su propio expediente
—capturar datos, subir identificacion, aceptar simulacion—, que la Fase 3 §4.9 le atribuye
y sin los cuales `isReadOnly()` mentia sobre el; (b) el middleware se aplica al grupo de
**recursos de negocio**, no a los endpoints de sesion. Resultado: los roles de solo lectura
son exactamente **auditor y cliente**, y la prueba lo exige con una lista cerrada.

**Cambios (47 archivos, 4 773 lineas anadidas):**
- `app/Domain/Access/{Role,Permission}.php` — nuevos, sin dependencias de framework.
- `app/Domain/Audit/AuditEventType.php` — 7 eventos nuevos de acceso y consulta.
- `app/Infrastructure/Security/{TotpAuthenticator,FirstPartyClient}.php` — nuevos.
- `app/Infrastructure/Persistence/Eloquent/CreditApplicationRecord.php` — nuevo. Se
  enlaza por `public_id` y no por el id autoincremental: un identificador secuencial
  invita a recorrer las solicitudes ajenas cambiando un numero.
- `app/Http/Middleware/{EnforcePkceS256,EnsureRole,EnforceReadOnlyRole}.php` — nuevos.
- `app/Http/Controllers/{Auth,Api}/` — 6 controladores nuevos; `app/Policies/` — 1 politica.
- `app/Providers/AuthorizationServiceProvider.php` — Gates por permiso, politica y ajustes
  del servidor OAuth2. Sin `Gate::before`: un "el administrador puede todo" anularia las
  restricciones explicitas de la Fase 3 §4.9.
- `app/Console/Commands/CreateStaffUserCommand.php`, `database/seeders/OAuthSpaClientSeeder.php`.
- `database/migrations/2026_08_21_210000_add_access_control_to_users_table.php` +
  4 migraciones de Passport.
- `config/{hashing,passport}.php` nuevos; `config/{auth,app,security}.php` ampliados.
- `routes/api.php` nuevo; `bootstrap/app.php` con `apiPrefix: 'api/v1'`.
- `.env.example` — bloque de autenticacion y control de acceso.

**Verificacion:**
- `bash scripts/verificar_avance.sh` -> **T5: OK (7 ok / 0 falta / 0 revisar)**, con
  `php artisan test (auth/RBAC) en verde: 103 pruebas`. Totales **74 OK / 16 FALTA / 1 REVISAR** (antes de T5: 61 / 26 / 2).
- `PAO_DISABLE=1 php artisan test` -> **246 pruebas, 246 aprobadas, 587 aserciones**
  (antes 147). Pruebas nuevas (99): `RoleAccessMatrixTest` (17, sin base de datos),
  `TotpAuthenticatorTest` (33, contra los vectores del apendice B de RFC 6238),
  `OAuthPkceFlowTest` (12), `TwoFactorLoginTest` (16) y `ApiAccessControlTest` (21).
- Higiene transversal: las 9 verificaciones en `[OK]`.
- `./vendor/bin/pint --test` -> `passed`.
- Regla hexagonal: `grep -rl "use Illuminate" backend/app/Domain` sin resultados; el nuevo
  `Domain/Access` no importa nada del framework.

**Las tres capas del 401/403, comprobadas por separado** (`ApiAccessControlTest`):
401 sin token o con token invalido; 403 por rol insuficiente (el auditor no escribe, el
analista de riesgos no lee la bitacora); **403 por objeto** —un prospecto autenticado, con
el permiso generico de consultar solicitudes, no puede consultar la de otro prospecto—, que
es el fallo que la Fase 3 §4.9 senala como el mas frecuente en APIs que si autentican bien.
Se comprueba ademas que la negacion por objeto no revela folio, titular ni nombre de tabla.

**Siguiente paso pendiente:** T7 — OCR e identidad. Segun `verificar_avance.sh` §T7 faltan
8 de 9 verificaciones. Antes de T7 conviene cerrar T4/T5 con el usuario dos cosas: (a) si
se acepta SHA-256 como algoritmo del TOTP con el costo de compatibilidad descrito arriba;
(b) la rotacion del refresh token en cada uso, que hoy Passport hace pero ninguna prueba
exige (VUL-03 sigue En progreso por eso y por la parte de la SPA, que es de T10).

**Pendiente explicito de T12:** anadir Pint al hook pre-commit, junto con el detector de
secretos que ya exige §T12.

## [2026-08-21 20:35] chore — Deteccion de suite en verde por codigo de salida
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `chore: la suite en verde se detecta...`)
**Autorizacion:** el usuario autorizo expresamente modificar **solo** la deteccion de
suite en verde de `scripts/verificar_avance.sh`, en T6 y en las verificaciones
equivalentes de T5 (CLAUDE.md seccion 9). Ningun otro criterio del script se toco.
**El falso positivo, reproducido:** el criterio anterior era
`printf '%s' "$OUT" | grep -qiE "FAIL|Errors"` sobre la salida de `php artisan test`. La
salida normal de esa orden (Collision) **imprime el nombre de cada prueba**, y T4 agrego
pruebas que se llaman `the simulated ocr can fail to enqueue`,
`the simulated card issuer can fail` y `the simulated notification sender can fail`,
porque comprueban justo el camino de error. Un `grep -i` por "fail" las confunde con un
fallo:

```
$ PAO_DISABLE=1 php artisan test --testsuite=Unit   # 107 passed, codigo de salida 0
$ bash scripts/verificar_avance.sh
  [FALTA]   php artisan test --testsuite=Unit no esta en verde
  » T6: FALTA  (4 ok / 1 falta / 0 revisar)
```

**Por que no se habia visto:** este servidor tiene `laravel/pao`, que sustituye la salida
de Collision por una linea JSON cuando detecta que quien ejecuta es un agente. Esa linea
no lleva nombres de prueba, asi que en las corridas del asistente el criterio no se
disparaba y T6 salia OK. En una corrida normal —sin agente, o con `PAO_DISABLE=1`— si se
dispara. Es decir: **el script daba resultados distintos al usuario y al asistente sobre
el mismo codigo**, que es exactamente lo que un medidor no debe hacer.
**Cambios:**
- `scripts/verificar_avance.sh`: funcion `suite_failed()` nueva junto al ayudante
  `artisan()`. Manda el **codigo de salida** de `php artisan test`, que es la senal
  canonica; como respaldo, un patron **sensible a mayusculas** sobre la linea de resumen
  (`Tests:.*failed` o `FAILURES!`). Se aplica en T6 y en la verificacion de auth/RBAC de
  T5. El mensaje de fallo ahora incluye el codigo de salida.
- T6 conserva la comprobacion de "No tests executed", ahora como caso aparte y sensible a
  mayusculas: una suite vacia tampoco acredita la tarea.
**Verificacion:**
- Suite verde sin pao: antes `T6: FALTA (4 ok / 1 falta)`; ahora `T6: OK (5 ok / 0 falta)`.
- Suite verde con pao: `T6: OK`, igual que antes (no hay regresion).
- Suite realmente roja (prueba temporal que falla a proposito, ya borrada): `[FALTA] php
  artisan test --testsuite=Unit no esta en verde (codigo de salida 1)` en los dos
  entornos, con y sin pao. El cambio no afloja el criterio.
**Siguiente paso pendiente:** sin cambios — T5, autenticacion OAuth2 + PKCE + 2FA y RBAC
de 5 roles (ver la entrada de T4).

**Nota:** con `laravel/pao` activo la salida es una linea JSON (`{"result":"failed",...}`),
que **no** casa con ninguno de los dos patrones de respaldo; ahi la deteccion se apoya
enteramente en el codigo de salida, que es correcto en ambos casos. Si se quiere que el
respaldo tambien cubra esa forma, habria que anadir el patron `"result":"failed"`, y eso
requiere autorizacion aparte.

---

## [2026-08-21 20:05] T4 — Los 7 puertos: interfaz + adaptador real + adaptador falso + enlaces
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `T4: ...`)
**Contexto:** T3 dejo los siete puertos declarados y solo tres enlazados. Faltaban los
cuatro servicios externos (OCR, identidad, tarjetas y notificaciones), un doble de prueba
por puerto y que el intercambio de adaptador dependiera unicamente de la configuracion.
**Cambios:**
- `config/adapters.php` (nuevo): un driver por puerto. Es el UNICO lugar que decide que
  adaptador queda activo. Los cuatro servicios externos van **simulados**: no hay convenio
  con INE ni con RENAPO, el entorno es de desarrollo y ningun adaptador abre conexiones ni
  usa credenciales.
- `app/Providers/AdapterServiceProvider.php` (nuevo): enlaza los siete puertos leyendo esa
  configuracion, con una tabla de drivers por puerto. Un driver inexistente falla al
  resolver nombrando el puerto y los drivers disponibles, no en silencio. Los tres enlaces
  de persistencia se mudaron aqui desde `AppServiceProvider`, que se queda con los
  servicios propios (PiiHasher, AuditChain, motor de reglas).
- Adaptadores simulados, **deterministas y capaces de fallar**:
  - `Infrastructure/Ocr/SimulatedOcrService` + `OcrScenario`: devuelve un identificador de
    trabajo `ocrsim-<escenario>-<16 hex del hash>`, que lleva escrito el desenlace para que
    el worker de T7 pueda simular timeout (reintentable) o documento ilegible (no
    reintentable) sin proveedor real. Escenario por `force_scenario` de configuracion o por
    marca reservada en la ruta (`sandbox-timeout`, `sandbox-unreadable`). `fail_enqueue`
    simula la cola caida, que es un error distinto del procesamiento.
  - `Infrastructure/Identity/SimulatedIdentityValidator` + `IdentityScenario`: CURP de
    laboratorio con desenlace fijo (rechazo, indisponibilidad, marca antifraude), como el
    sandbox de cualquier proveedor de eKYC. Empiezan por **XEXX** —el prefijo generico
    oficial de persona extranjera— y por tanto no pueden ser la CURP de nadie real. Folio
    de verificacion determinista, derivado del id publico del prospecto.
  - `Infrastructure/Card/SimulatedCardIssuer`: token `tok_sim_...` y cuatro digitos, nunca
    un PAN; `IssuedCard` rechaza por su cuenta cualquier token con forma de tarjeta.
  - `Infrastructure/Notification/SimulatedNotificationSender`: no envia nada, deja
    constancia en el registro con el **destinatario enmascarado** (un correo o un telefono
    tambien son datos personales y el registro es persistente).
- `Domain/Exception/ExternalServiceUnavailableException` (nuevo): los adaptadores traducen
  SU fallo a un tipo que el dominio conoce. `userMessage()` no nombra el proveedor ni el
  motivo tecnico (regla de seguridad 8, VUL-05).
- Dobles de prueba en `tests/Support/Doubles/` (7): `InMemoryProspectRepository`,
  `InMemoryDocumentRepository`, `InMemoryAuditLogger`, `FakeOcrService` (`willTimeOut()`,
  `willBeUnreadable()`, `willFailToEnqueue()`), `FakeIdentityValidator` (`willReject()`,
  `willBeUnavailable()`, `willFlagFraud()`, `willThrowUnavailable()`), `FakeCardIssuer` y
  `FakeNotificationSender`, ambos con `willFail()`.
- Pruebas nuevas (42): `tests/Unit/Infrastructure/SimulatedAdaptersTest` (18: determinismo
  y fallo de los cuatro simuladores), `tests/Unit/Application/UploadIdentityDocumentTest`
  (4: un caso de uso completo contra dobles, sin base de datos ni framework),
  `tests/Feature/PortBindingTest` (19: los 7 puertos resueltos del contenedor, driver
  desconocido, escenario por configuracion y sustitucion por doble) y una regla de
  arquitectura nueva en `DomainDependencyTest`.
- `.env.example`: variables de los cinco drivers y de los escenarios de simulacion.
**Verificacion:**
- `bash scripts/verificar_avance.sh` -> **T4: OK (7 ok / 0 falta / 0 revisar)**, los siete
  puertos con `tinker: si`. Totales 61 OK / 26 FALTA / 2 REVISAR.
- `php artisan test` -> **147 pruebas, 147 aprobadas, 359 aserciones** (antes 105).
- Higiene transversal: las 9 verificaciones en `[OK]`.
- `DomainDependencyTest::test_the_application_layer_does_not_decide_which_adapter_is_active`
  falla la integracion continua si en `app/Application` aparece `env(`, `config(`, `app(`,
  `APP_ENV`, `::environment(` o `getenv(`.
**Siguiente paso pendiente:** T5 — Autenticacion OAuth2 + PKCE + 2FA y RBAC de 5 roles.
Segun `verificar_avance.sh` §T5 faltan las seis: servidor OAuth2 en `backend/composer.json`
(hoy no hay ninguno declarado), PKCE (`code_challenge`), 2FA, los 5 roles
(`prospect`, `customer`, `admin`, `auditor`, `risk_analyst`) y pruebas que exijan 401 sin
token y 403 con rol insuficiente.

**Notas — decisiones que conviene revisar:**

1. **"Adaptador real" de los cuatro servicios externos = el simulado.** Para OCR,
   identidad, tarjetas y notificaciones no existe hoy un adaptador contra proveedor: no
   hay convenio con INE ni con RENAPO y el entorno es de desarrollo. Lo que vive en
   `Infrastructure` es el simulador, y **es codigo real de la aplicacion**, no un doble de
   pruebas: se selecciona por configuracion y corre en el entorno. El adaptador contra
   proveedor entra en su tarea (OCR e identidad en T7, notificaciones en T8, tarjetas en
   T9) anadiendo una entrada a la tabla de drivers de `AdapterServiceProvider`. La seccion
   T4 del script sale en OK con esto porque su criterio es que cada puerto tenga interfaz,
   adaptador en `Infrastructure`, doble y enlace resoluble; conviene tenerlo presente al
   leer ese OK.
2. **Los escenarios de simulacion son deterministas a proposito.** Un simulador que
   respondiera al azar volveria intermitentes todas las pruebas que dependan de el. El
   desenlace se deriva de la entrada (ruta del documento, CURP) o se fuerza por
   configuracion, nunca de un `rand()`.
3. **El escenario del OCR viaja dentro del identificador del trabajo.** Es el contrato
   entre backend y worker: T7 no necesita leer la configuracion del backend ni consultar
   la base para saber que debe simular. Si en T7 se decide otro mecanismo, hay que cambiar
   las dos puntas.
4. **Los dobles de prueba no se enlazan desde `config/adapters.php`.** Viven en `tests/` y
   los enchufa cada prueba. Apuntar la configuracion de la aplicacion a una clase de
   `tests/` romperia la carga en produccion, donde `autoload-dev` no existe.

---

## [2026-08-21 19:15] chore — Separacion de hallazgos verificados y controles preventivos
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `chore: separa hallazgos verificados...`)
**Contexto:** `BUGS.md` mezclaba dos cosas distintas. Las ocho entradas VUL-01 a VUL-08 no
salieron de analizar este codigo: se redactaron en la Fase 3 (§3.4) como escenario
ilustrativo del tipo de defectos que este stack suele producir. Son controles preventivos
validos, pero llamarlos "vulnerabilidades encontradas" es inexacto, y una sola entrada
indemostrable le quita valor probatorio a todo el archivo.
**Cambios:**
- `SECURITY_CHECKLIST.md` (nuevo): las 8 VUL-xx como controles preventivos, con columnas
  ID / Control / Origen (fase de diseno) / Tarea que lo implementa (T#) / Estado / CWE-OWASP
  / Verificacion. La verificacion cita la seccion concreta de `verificar_avance.sh` que
  acredita cada control y, donde el script todavia no lo cubre (VUL-01 tipo real del
  archivo, VUL-03 almacenamiento del token), lo dice explicitamente y nombra la prueba que
  lo acreditara. Los identificadores se conservan tal cual: estan citados en los
  documentos entregados de las tres fases.
- `BUGS.md` (reescrito): solo hallazgos verificados sobre este repositorio. Queda **una**
  entrada, VUL-09 (tasas no monotonas, detectada y corregida en T6), con columna nueva
  «Como se detecto» que cita el comando reproducible. Se agrega la seccion «Analisis
  ejecutados», con fecha, comando y resultado de `composer audit`, los dos `npm audit` y
  la higiene transversal: una ejecucion sin hallazgos tambien es evidencia, y es lo que
  permite afirmar que la tabla esta corta porque no hay mas, no porque no se haya buscado.
  Queda anotado que **no** se ha ejecutado ningun analisis estatico: no hay herramienta
  SAST en `require-dev` (`laravel/pint` es formateador), y eso es parte de T12.
- `CLAUDE.md`: seccion **6.2** nueva con el criterio de admision de cada archivo y las
  reglas comunes (no se borra nada; un control implementado se marca en su sitio y **no**
  se mueve a `BUGS.md`; solo pasa a `BUGS.md` si mas adelante se descubre mal implementado
  y eso constituye un hallazgo real). Actualizadas la seccion 3 (estructura) y la 7
  (lectura de inicio de sesion y regla de no borrado).
- `scripts/verificar_avance.sh`, seccion T12 **con autorizacion expresa del usuario**
  (CLAUDE.md seccion 9): cuenta los dos archivos por separado, exige que cada hallazgo de
  `BUGS.md` cite el comando que lo detecto y comprueba que ningun identificador VUL-xx
  aparezca en los dos archivos a la vez.
**Verificacion:**
- `bash scripts/verificar_avance.sh` -> T12 pasa de **4 ok / 1 falta / 1 revisar** a
  **7 ok / 1 falta / 0 revisar**. Lo que sigue en `[FALTA]` es el hook pre-commit con
  detector de secretos, que es trabajo real de T12.
- Lectura del script: `BUGS.md: 1 hallazgo(s) verificado(s); 0 abierto(s)`,
  `SECURITY_CHECKLIST.md: 8 control(es) preventivo(s); 0 implementado(s), 2 en progreso,
  6 pendiente(s)`, `Ningun identificador VUL-xx esta duplicado entre los dos archivos`.
- Totales de la auditoria: 54 OK / 33 FALTA / 2 REVISAR (antes 51 / 33 / 3).
**Siguiente paso pendiente:** sin cambios — T4, los 7 puertos con adaptador real, adaptador
falso y enlaces (ver la entrada de T6).

**Nota:** el `BUGS.md` reescrito se creo por error dentro de `backend/` por un cambio de
directorio arrastrado entre comandos; se movio a la raiz antes de commitear. Lo detecto la
verificacion nueva de identificadores duplicados, que reporto las 8 VUL-xx en los dos
archivos a la vez porque seguia leyendo el `BUGS.md` viejo de la raiz.

---

## [2026-08-21 19:05] T6 — Motor de reglas de credito con pruebas unitarias sin BD
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `T6: ...`)
**Contexto:** El motor de reglas (`Domain/Credit`) se habia escrito ya dentro de T3, junto
con el resto del nucleo, pero quedo sin cerrar: la politica de tasas era incoherente y una
prueba unitaria seguia en rojo. T6 se cierra fuera de orden —antes que T4 y T5— porque el
codigo que audita ya existia desde T3; lo que faltaba era la correccion y la cobertura de
casos limite.
**Cambios:**
- `backend/app/Domain/Credit/CreditPolicy.php`: progresion de tasas DESCENDENTE conforme
  mejora el perfil (microcredito 60.00 %, personal 28.50 %, negocio 24.00 %). La anterior
  era 60 / 36 / 42, donde el tramo de mejor perfil salia mas caro que el intermedio.
  La tasa personal queda **anclada al prototipo P5** de las Fases 2 y 3.
- Invariante nuevo en el constructor de `CreditPolicy`
  (`assertRatesDecreaseAsTheProfileImproves()`): microcredito > personal > negocio, o
  `InvalidArgumentException` al construir. Una tabla de tasas incoherente no produce
  ninguna excepcion por si sola —el motor calcula igual—, asi que sin este invariante el
  error solo se ve leyendo las tres cifras juntas.
- `backend/tests/Unit/Domain/CreditRulesEngineTest.php`: la asercion de tasa esperaba
  `36.00` y quedo obsoleta con la correccion; ahora exige `28.50`. Ademas 7 pruebas
  nuevas de casos limite:
  - edad 17 y 75 rechazadas, 18 y 74 aceptadas (limites inclusivos de la politica);
  - ingreso justo en el umbral de cada tramo (3 000 / 8 000 / 15 000) y un centavo por
    debajo de cada uno, que debe caer al tramo anterior;
  - monto por encima del techo de **los tres** tipos (30 000 / 150 000 / 500 000), con
    ingresos elegidos para que la restriccion activa sea el techo y no el aforo;
  - la tasa personal contra el prototipo P5 y su cobertura del monto de 35 000;
  - la progresion descendente de las tres tasas;
  - regresion exacta de la tabla anterior (60 / 36 / 42), que ahora debe ser rechazada.
- Borrado del archivo vacio `HTTP` en la raiz del repositorio (0 bytes, sin seguimiento;
  residuo de una redireccion de shell). No estaba versionado ni referenciado.
**Verificacion:**
- `php artisan test --testsuite=Unit` -> **84 pruebas, 84 aprobadas, 172 aserciones**
  (antes: 77 pruebas, 76 aprobadas, 1 fallando).
- `php artisan test` (suite completa) -> 105 pruebas, 105 aprobadas, 268 aserciones.
- `bash scripts/verificar_avance.sh` -> **T6: OK (5 ok / 0 falta / 0 revisar)**; higiene
  transversal con las 9 verificaciones en `[OK]`.
- Prototipo P5 leido directamente de la Figura 6 de `docs/Fase2_Arquitectura_Diseno_Tecnico.docx`
  (imagen incrustada, no texto): tipo credito simple, capacidad 4 800, monto 35 000, tasa
  anual fija 28.5 %, CAT informativo 32.4 % sin IVA, plazo 18 meses, pago 2 430, total
  43 740.
**Siguiente paso pendiente:** T4 — Los 7 puertos con adaptador real, adaptador falso y
enlaces. Faltan los adaptadores de `OcrService`, `IdentityValidator`, `CardIssuer` y
`NotificationSender`, hoy declarados y sin enlazar en `AppServiceProvider::register()`,
mas un doble de prueba por puerto y su enlace en el contenedor del entorno de pruebas.

**Notas — decisiones que conviene revisar:**

1. **El pago mensual y el CAT del prototipo P5 no salen de la formula de amortizacion.**
   Con 35 000 a 18 meses y 28.5 % anual, el motor calcula pago mensual **2 412.25** y CAT
   **32.53 %**; el prototipo muestra 2 430 y 32.4 %. La diferencia (17.75 al mes, 0.13
   puntos de CAT) viene de que las cifras del mockup son ilustrativas y estan redondeadas,
   no derivadas del sistema frances. **No se toco el calculo para hacerlo coincidir**: la
   formula del motor es la correcta y ajustarla a un mockup seria falsear el calculo
   financiero. Lo que se anclo al prototipo es la **tasa**, que es el parametro de
   politica. Si la entrega academica exige que P5 muestre exactamente 2 430, lo que debe
   corregirse es la imagen del documento, no `AmortizationCalculator`.
2. **Las demas cifras de `CreditPolicy` siguen siendo propuestas sin fuente documental.**
   Umbrales de ingreso, techos por tipo, aforo del 30 %, rango de edad y minimo de
   originacion no aparecen en las Fases 1 a 3; el diseno solo exige *determinar* tipo de
   credito y capacidad de pago. Estan reunidas en una sola clase para que el area de
   riesgos las ajuste sin tocar el algoritmo.

---

## [2026-08-21 14:30] T3 — Capas Domain / Application / Infrastructure
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `T3: ...`)
**Contexto:** Levantar el nucleo hexagonal: entidades y objetos de valor sin dependencia
del framework, los siete puertos del diseno, los casos de uso de orquestacion y los
primeros adaptadores reales sobre Eloquent.
**Cambios:**
- `backend/app/Domain/` (55 clases): `Shared/` (Money, Uuid, Folio, Email, PhoneNumber),
  `Identity/` (Curp, Rfc, IdentityDocument, IdentityValidationResult y enums),
  `Prospect/` (Prospect + CaptureMethod, CaptureStatus, Sex),
  `Credit/` (Term, AnnualRate, CreditPolicy, AmortizationCalculator, CreditRulesEngine,
  CreditOffer, CreditApplication, CreditSimulation y enums), `Audit/` (AuditEvent,
  AuditChain, SensitiveDataMasker, AuditContext, AuditEventType), `Card/`,
  `Notification/`, `Exception/` (11 excepciones propias) y `Port/` (los 7 puertos).
- `backend/app/Application/` (8 clases): casos de uso `StartProspectCapture`,
  `CaptureProspectData`, `ConfirmProspectData`, `UploadIdentityDocument`,
  `SimulateCredit` y los DTO de entrada y salida.
- `backend/app/Infrastructure/` (9 clases): `ProspectRecord`, `IdentityDocumentRecord`,
  `AuditLogRecord`, sus mapeadores, `EloquentProspectRepository`,
  `EloquentDocumentRepository`, `EloquentAuditLogger` y `Security/PiiHasher`.
- `backend/app/Providers/AppServiceProvider.php`: enlaces puerto -> adaptador.
- `backend/config/security.php`: llave de hash de identificadores personales.
- `backend/database/migrations/2026_08_21_101500_make_prospect_full_name_nullable.php`.
- 12 archivos de prueba nuevos en `backend/tests/`.
**Verificacion:**
- `php artisan test` -> 98 pruebas, 98 aprobadas, 248 aserciones.
- `bash scripts/verificar_avance.sh` -> **T3: OK (13 ok / 0 falta / 0 revisar)**; higiene
  transversal con las 9 verificaciones en `[OK]`, incluida la de idioma.
- `grep -rl "use Illuminate" backend/app/Domain` -> sin resultados. Ademas la regla queda
  cubierta por `tests/Unit/Architecture/DomainDependencyTest.php`, que falla la
  integracion continua si alguien contamina la capa.
- Digito verificador comprobado contra vectores reales: CURP `HEGG560427MVZRRL04`
  (ejemplo de RENAPO), RFC `SAT970701NN3` (persona moral, RFC del SAT) y
  `GODE561231GR8` (persona fisica, RFC de pruebas del CFDI).
**Siguiente paso pendiente:** T4 — Los 7 puertos con adaptador real, adaptador falso y
enlaces. Faltan por implementar los adaptadores de `OcrService`, `IdentityValidator`,
`CardIssuer` y `NotificationSender` (los tres primeros hoy declarados y sin enlazar en
`AppServiceProvider::register()`), mas un doble de prueba por puerto en
`backend/tests/` y su enlace en el contenedor para el entorno de pruebas.

**Notas — decisiones que conviene revisar:**
1. **`prospects.full_name` era NOT NULL y se corrigio.** El esquema de T2 admitia
   `capture_status = 'started'` (P1, el prospecto solo eligio metodo de captura) pero
   exigia nombre desde el insert: ese estado era irrepresentable y el evento
   `prospect.started` se quedaba sin entidad a la que apuntar. Se corrigio con migracion
   nueva, sin tocar la de T2 ya commiteada; no cambia nombre ni tipo de columna, solo su
   nulabilidad. Detectado porque `ProspectJourneyTest` fallaba con
   `SQLSTATE[23000] ... Column 'full_name' cannot be null`.
2. **`curp_hash` y `rfc_hash` se calculan con HMAC-SHA-256, no con SHA-256 a secas.**
   El comentario de la migracion de T2 dice "SHA-256 determinista"; sigue siendo SHA-256
   y sigue siendo determinista, pero con llave (`config('security.pii_hash_key')`, por
   omision `APP_KEY`). Motivo: el espacio de CURP validas es pequeno y enumerable, asi
   que un SHA-256 simple se revierte por fuerza bruta con solo obtener una copia de la
   tabla. **Si el usuario prefiere SHA-256 sin llave, se revierte cambiando unicamente
   `Infrastructure/Security/PiiHasher`.**
3. **Los parametros del motor de reglas no vienen del diseno.** Los documentos de las
   fases 1 a 3 exigen determinar tipo de credito y capacidad de pago, pero no fijan
   cifras. Se implementaron como parametros en `Domain/Credit/CreditPolicy::default()`
   —aforo 30 %, edad 18-74, ingresos minimos 3 000 / 8 000 / 15 000, techos
   30 000 / 150 000 / 500 000, tasas 60 % / 36 % / 42 % anual, plazos 6-12-18-24-36— y
   estan reunidos en una sola clase para que el area de riesgos los ajuste sin tocar el
   algoritmo. **Son propuestas, no reglas del diseno: quedan a confirmacion del usuario.**
4. **Puertos sin adaptador, a proposito.** `OcrService`, `IdentityValidator`, `CardIssuer`
   y `NotificationSender` estan declarados y sin enlazar. Enlazar un adaptador falso aqui
   daria por implementado algo que no lo esta; se hace en T4.
**Notas — seguridad:**
- `BUGS.md`: VUL-02 y VUL-04 pasan de Abierto a **En progreso**. VUL-02 tiene ya el
  catalogo de plazos en el dominio (`Term`) y VUL-04 el enmascarado en el constructor de
  `AuditEvent`; ninguno se declara Resuelto porque el endpoint (T10) y la bitacora
  completa (T8) todavia no existen.
- La bitacora no guarda la CURP ni siquiera enmascarada: identifica por `prospect_id` y
  registra `has_curp`. El enmascarado exime a los booleanos, de modo que `has_rfc => true`
  sigue siendo informacion util de auditoria sin transportar dato personal.
- `AuditLogRecord` bloquea UPDATE y DELETE en el propio modelo (append-only), ademas de
  no existir ninguna ruta que los invoque.

---

## [2026-08-20 23:15] T2 — Migraciones de las 9 tablas (cierre)
**Estado:** completado
**Commit:** ver `git log --oneline` (commit `T2: cierre`)
**Contexto:** Cierre de la tarea que quedo bloqueada por falta de base de datos. El
usuario ejecuto `scripts/setup-database.sh`, que creo `golsfintech` y `golsfintech_test`
con el usuario `golsfintech` de minimo privilegio.
**Cambios:** ninguno en las migraciones respecto al commit `edf9601`; en esta entrada se
registra la ejecucion y la verificacion. `scripts/setup-database.sh` recibio tres
correcciones (commits `fab9286`, `7407661`, `e206fe5`).
**Verificacion:**
- `php artisan migrate --force` -> 12 migraciones DONE (3 de Laravel + las 9 del diseno).
- `php artisan migrate:status` -> las 12 en estado `[1] Ran`.
- `SHOW TABLES` -> `prospects`, `identity_documents`, `identity_validations`,
  `credit_applications`, `credit_simulations`, `customers`, `credit_lines`, `cards`,
  `audit_logs` presentes con los nombres exactos acordados.
- `DESCRIBE audit_logs` -> `prospect_id`, `affected_entity`, `affected_entity_id`,
  `event_type`, `actor`, `ip_address`, `metadata`, `event_at`, `previous_hash`,
  `current_hash` (`current_hash` con indice UNIQUE). Sin llave foranea por entidad.
- `php artisan test` -> 5 pruebas, 5 aprobadas, 48 aserciones (incluye
  `DatabaseSchemaTest`, que corre sobre `golsfintech_test`).
- `ls -l backend/.env` -> `-rw-------` (600).
**Siguiente paso pendiente:** T3 — Capas Domain, Application e Infrastructure. Crear las
entidades y objetos de valor en `backend/app/Domain/` (Prospect, Credit, Identity, Audit),
los casos de uso en `app/Application/` y los adaptadores en `app/Infrastructure/`,
respetando la regla de dependencia. Verificar con
`grep -rl "use Illuminate" backend/app/Domain` (no debe devolver nada).
**Notas — defecto propio detectado y corregido:**
`scripts/setup-database.sh` abortaba en silencio tras el primer mensaje. Causa: la linea
`DB_PASSWORD="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"`; al cerrar `head` la
tuberia, `tr` muere por SIGPIPE (codigo 141) y `set -o pipefail` + `set -e` abortan el
script sin imprimir nada. Se reprodujo con
`bash -c 'set -euo pipefail; p=$(tr -dc "A-Za-z0-9" </dev/urandom | head -c 32)'` -> 141.
Corregido usando `python3 secrets` y anadiendo un `trap ERR` que informa linea y codigo.
No se registra en BUGS.md por no ser un defecto de seguridad de la aplicacion, sino un
error funcional de un script de aprovisionamiento.
**Notas — hallazgo del entorno:**
Este MySQL tiene la resolucion de nombres activada: una conexion TCP desde `127.0.0.1`
llega al servidor como `'golsfintech'@'localhost'`, por lo que una cuenta creada solo
para `'127.0.0.1'` se rechaza con ERROR 1045. El script crea ambas.

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
