# PROGRESS.md — Bitácora de avance de GolsFintech

Entrada más reciente arriba. Ninguna entrada se borra.
Regla: una tarea no está terminada si no está commiteada.

---

## [2026-08-24 12:15] T9a (parcial 4/6) — Endpoints de P2: captura parcial y confirmacion
**Estado:** EN PROGRESO — bloque 4 de T9a. Faltan endpoints P4 (bloque 5) y P5/P6/P7
(bloque 6, subdividido por endpoint). Vistas de Vue van en T9b, tarea aparte.
**Commit:** ver `git log --oneline` (commit `T9a (parcial): endpoints de P2`).
**Evidencia:** `php artisan test` -> 366 pruebas / 1012 aserciones en verde (11 nuevas);
`./vendor/bin/pint --test` limpio; `bash scripts/verificar_avance.sh` -> **86 OK /
6 FALTA / 2 REVISAR** (+1 OK respecto al baseline anterior, gracias a la verificacion
complementaria del script; T9 sigue en FALTA porque su criterio son las 7 vistas de Vue).

### Cierre de la regresion heredada

La sesion previa dejo el arbol sin commitear con dos hallazgos:

- **T6 en rojo:** `ProspectTest::test_data_cannot_be_confirmed_before_being_captured`
  esperaba `InvalidStateTransitionException`, pero el cambio de dominio que exigia la
  precision 2 —confirmar con lista de campos faltantes— hace que `confirmData()` lance
  `ProspectDataIncompleteException`. La prueba se actualizo para esperar la excepcion
  nueva y verificar `missingFields()`.
- **T10 con "2 campo(s) exponen CURP/RFC/PAN en claro":** falso positivo del script.
  Los dos matches estaban en `CaptureProspectDataRequest`, que son reglas de VALIDACION
  DE ENTRADA (el cliente envia CURP y RFC con esos nombres), no proyecciones de salida.
  Comprobado con grep que ningun controlador ni recurso devuelve CURP en claro. Con
  autorizacion del usuario se afino el script para mirar solo `Http/Controllers` y
  `Http/Resources`, y se anadio una verificacion complementaria que cubre el hueco
  —accesos `->curp/->rfc/->tokenized_card_number` en esas capas sin `mask/hash/last_four`
  en la misma linea—. Commit `chore:` aparte.

Ademas se reformulo un comentario en `ProspectController` que mencionaba `getMessage()`
literalmente y hacia subir el contador de REVISAR sin motivo real.

### Que se hizo en el bloque 4

**Dominio:**
- `Prospect::mergePartialData(...)` — actualizacion parcial con todos los campos como
  opcionales. Transiciona a `data_captured` solo si hay al menos un campo presente; un
  PATCH con cuerpo vacio no mueve el estado.
- `Prospect::missingConfirmationFields(): list<string>` — lista de campos obligatorios
  que aun no estan capturados. `confirmData()` la usa para lanzar
  `ProspectDataIncompleteException` con el detalle, en lugar del anterior
  `InvalidStateTransitionException` con mensaje generico.
- `Domain/Exception/ProspectDataIncompleteException` — excepcion propia con codigo estable
  `PROSPECT_DATA_INCOMPLETE` y `missingFields()`. Separada de `InvalidStateTransition`
  a proposito: confirmar con campos vacios es regla de negocio, no fallo de flujo.

**Aplicacion:**
- `Application/DTO/ProspectDataPatch` — DTO parcial con todos los campos nullable.
- `Application/UseCase/Prospect/UpdateProspectDraft` — orquesta el merge parcial, valida
  cada campo presente con su objeto de valor (CURP con digito, RFC con estructura,
  telefono con formato, correo strict) y comprueba unicidad de CURP contra otros
  expedientes. Emite `prospect.data_captured` con `updated_fields`. `CaptureProspectData`
  se mantiene intacto: es el flujo OCR "todo de una vez".

**HTTP:**
- `CaptureProspectDataRequest` — todos los campos como `sometimes|nullable`. La
  validacion estricta aplica solo a los presentes.
- `ConfirmProspectDataRequest` — cuerpo vacio; la comprobacion de completitud vive en
  el dominio y el controlador la traduce a 422 con `missing_fields`.
- `ProspectController::update` y `::confirm` — cargan el expediente desde el token
  (`$request->user()->prospect`), aplican `Gate::authorize('updateOwn', $prospect)`
  como defensa en profundidad, y mapean `DomainException` a 422 con `error_code` estable.
- Rutas nuevas: `PATCH /api/v1/prospects/me` (`prospects.me.update`) y
  `POST /api/v1/prospects/me/confirm` (`prospects.me.confirm`), dentro del grupo
  `scopes:prospect-session`.

**Autorizacion:**
- `Policies/ProspectPolicy::updateOwn(User, ProspectRecord)` — verifica permiso
  `CaptureOwnProspectData` y titularidad (`user->prospect_id === prospect->id`). Con la
  ruta actual, el prospecto sale del token y no hay identificador ajeno que nombrar; la
  politica es defensa en profundidad para el dia que aparezca una ruta con `{prospect}`.
- Registrada en `AuthorizationServiceProvider` con `Gate::policy(ProspectRecord::class,
  ProspectPolicy::class)`.

**Pruebas:**
- `Tests\Feature\Prospect\ProspectDataCaptureTest` — 11 pruebas nuevas: 401 sin token
  (PATCH y POST), 422 por FormRequest (CURP con longitud incorrecta), 422 por dominio
  (CURP con digito equivocado, `error_code: INVALID_CURP`), 422 en confirm sin datos y
  con datos parciales (con `missing_fields` verificado), 200 en captura parcial y en
  flujo completo, aislamiento por token (dos prospectos, cada PATCH toca su fila y no
  la del otro), 403 sobre expediente ajeno (comprobacion directa de la politica), y
  aserto de que la respuesta del PATCH no devuelve CURP en claro (RS-03 en verificacion).
- `ApiAccessControlTest::without_a_token_every_endpoint_answers_401` amplia para incluir
  las dos rutas nuevas de P2.

### Una decision que conviene tener a la vista

**El guard de Passport se cachea entre peticiones en tests.** En la misma prueba, emitir
dos tokens (dos prospectos distintos) y ejercer el segundo tras haber ejercido el primero
hace que el guard devuelva al primer usuario, y el aislamiento por token se evapora **solo
en tests**. En produccion no existe: cada peticion abre un ciclo del kernel nuevo. La
solucion es `$this->app['auth']->forgetGuards()` antes de cada peticion autenticada. Se
introdujo el helper `resetGuard()` en `ProspectDataCaptureTest` con el comentario que
explica el porque; cualquier prueba futura que ejerza dos tokens distintos en la misma
peticion debe reproducirlo o el falso verde volvera.

### CLAUDE.md — seccion 7.1 nueva

Se anadio la regla de **cierre defensivo cuando la sesion se esta agotando**: nunca se
cierra sesion con la suite en rojo, con `pint` sucio o con un control de seguridad en
regresion respecto al commit anterior. Antes de cerrar, commit parcial de lo que
compile; si no compila, revertir hasta un estado verde. La regla salio del incidente
del 2026-08-24 —cuarta interrupcion por limite—, primera en dejar el proyecto peor de
lo que estaba, y esta escrita en la seccion 7.1 justo despues de la regla de continuidad
porque es de la misma familia.

**Siguiente paso pendiente:** T9a (parcial 5/6) — endpoint P4 (validacion de identidad).

1. `Http/Controllers/Api/IdentityValidationController::store` que invoca el use case
   `ValidateIdentity` (bloque 3) con el prospecto del token y opcionalmente el
   `identity_document_id` de un documento previamente cargado.
2. `Http/Requests/Identity/ValidateIdentityRequest`: `document_public_id` opcional,
   UUID formato.
3. Extender la lista cerrada de `ApiAccessControlTest` si hace falta.
4. Ruta `POST /api/v1/identity-validations` bajo `scopes:prospect-session`.
5. Pruebas: 401 sin token, 403 con documento ajeno, 200 con validacion verificada y
   con validacion rechazada. Ejercicio de la asimetria de eventos (`_requested` primero,
   despues `_succeeded` o `_rejected`).

---

## [2026-08-23 11:30] T9a (parcial 3.5/6) — Persistencia de la simulacion antes de arrancar P2
**Estado:** EN PROGRESO — pieza puente entre el bloque 3 y el 4 de T9a, encargada
por el usuario para no dejar un eslabon abierto entre P5 y P6.
**Commit:** ver `git log --oneline` (commit `T9a (parcial): persistencia de la simulacion...`)
**Evidencia:** `php artisan test` -> 355 pruebas / 965 aserciones en verde (5 nuevas);
`./vendor/bin/pint --test` limpio.

### Por que existe este bloque

Al cerrar el bloque 3, `SimulateCredit` seguia devolviendo el `CreditOfferOutput`
pero no persistia nada. `AcceptCreditOffer` cargaba la simulacion por su UUID
desde el repositorio, es decir, contra una fila que en el flujo real no existia.
Los tests unitarios lo tapaban con dobles en memoria: cada uno guardaba su
simulacion en `setUp`. El hueco solo iba a aparecer al armar el endpoint de P5.

### Que se hizo

- `SimulateCredit` recibe ahora `IdentityValidationRepository` y
  `CreditApplicationRepository`. Comprueba que hay identidad verificada (Fase 2:
  P4 antes de P5, rechaza con `IdentityNotVerifiedException`), abre la solicitud
  si no existe, la avanza a `pre_approved` con el `identity_validation_id` de
  la validacion, la guarda, crea la simulacion con vigencia `now + TTL` y la
  persiste. El evento `credit_simulation.generated` viaja ahora con
  `simulation_public_id`, `simulation_folio` y `expires_at`.
- `config/credit.php` (nuevo): `simulation_ttl_seconds` con default 1800 s
  (30 min). Lo lee `AppServiceProvider` al enlazar el caso de uso: el TTL viaja
  como `int` en el constructor y el use case no toca `config()` (regla del
  test de arquitectura, sigue verde).
- `Domain/Exception/CreditSimulationExpiredException` (nueva): codigo estable
  `CREDIT_SIMULATION_EXPIRED`, mensaje al usuario que pide recalcular. Se
  separa de `InvalidStateTransitionException` a proposito —caducar no es un
  error de flujo, es una regla de negocio con mensaje propio; mezclarlas
  obligaria al endpoint a inspeccionar el texto para decidir el mensaje—.
  `CreditSimulation::accept()` la lanza cuando `isExpired`.
- `CreditOfferOutput` incorpora `simulationPublicId`, `simulationFolio` y
  `expiresAt`. Sin ellos, la SPA no puede referenciar en P6 lo que se le
  mostro en P5.
- Prueba de integracion nueva `SimulateThenAcceptTest` (contra MySQL, sin
  dobles): recorre P1 -> P4 -> P5 -> P6 y comprueba que la simulacion
  persistida se recupera por su UUID y se acepta. Cuatro casos: happy path
  (cliente, linea y tarjeta persistidas), simulacion caducada por TTL,
  TTL configurable (a 60 s), y el mismo CWE-639 del bloque 3 en integracion.
- `ProspectJourneyTest` actualizado: ahora incluye `ValidateIdentity` antes de
  `SimulateCredit`, y la secuencia de eventos esperados pasa a
  `... -> identity.validation_requested -> identity.validation_succeeded ->
  credit_simulation.generated`. Se anade tambien
  `test_simulating_without_a_verified_identity_is_refused`.
- Ajuste minimo en `AcceptCreditOfferTest`: el test de caducidad ahora espera
  `CreditSimulationExpiredException` en vez de `InvalidStateTransitionException`.

### Decisiones que conviene tener a la vista

**La transicion Draft -> UnderReview -> PreApproved no se salta.** El use case
avanza la solicitud paso a paso (`submitForReview` y luego `preApprove`),
respetando las TRANSITIONS del dominio. La alternativa —permitir Draft ->
PreApproved directo— habria simplificado el codigo, pero rompe la propiedad de
que una solicitud sin `identity_validation_id` no puede estar pre-aprobada, y
esa propiedad no es cosmetica: es la que impide que el motor de reglas se
aplique sobre datos que RENAPO no confirmo.

**Simulaciones repetidas son legitimas.** Si la solicitud ya esta en
`pre_approved`, el use case no vuelve a llamar a `preApprove` y solo persiste
la simulacion nueva. Recalcular el plazo o el monto era un requisito de P5;
prohibirlo obligaria al prospecto a abandonar y recomenzar.

**El TTL evita aceptar ofertas con parametros de riesgo caducados.** Es la
precision de negocio que pedia el usuario. La caducidad se compara contra el
reloj del servidor (`now` que llega al use case), no del navegador. Un `now`
adelantado desde el cliente no evita la caducidad.

**Siguiente paso pendiente:** T9a (parcial 4/6) — endpoint P2. Sin cambios
frente a la nota del bloque 3.

---

## [2026-08-23 11:00] T9a (parcial 3/6) — Tres casos de uso del recorrido: ValidateIdentity, AcceptCreditOffer, LookupCustomer
**Estado:** EN PROGRESO — bloque 3 de T9a. Faltan endpoints P2, P4, P5, P6 y P7
(bloques 4 a 6, uno por endpoint) y las siete vistas de Vue (T9b, tarea aparte).
**Commit:** ver `git log --oneline` (commit `T9a (parcial): tres casos de uso...`)
**Evidencia:** `php artisan test` -> 350 pruebas / 947 aserciones en verde (24 nuevas);
`./vendor/bin/pint --test` limpio; `bash scripts/verificar_avance.sh` ->
**85 OK / 6 FALTA / 2 REVISAR** (identico al baseline anterior; T9 sigue en FALTA
porque su criterio es las 7 vistas de Vue).

### Que se hizo

- `Application/UseCase/Identity/ValidateIdentity` — P4. Orquesta `IdentityValidator`
  (proveedor) y `IdentityValidationRepository::save`. Emite dos eventos: primero
  `identity.validation_requested`, y luego `_succeeded` o `_rejected` segun el
  desenlace. La CURP NO viaja al log en ninguno de los dos.
- `Application/UseCase/Credit/AcceptCreditOffer` — P6. Orquesta la aceptacion de
  la simulacion, la aprobacion de la solicitud, el alta del cliente y su linea
  (transaccion en el registry) y la emision de la tarjeta (fuera de esa
  transaccion). Emite hasta cinco eventos en orden y, si el emisor de tarjetas
  falla, cierra con `card.issuance_failed` y devuelve el cliente sin tarjeta:
  el credito ya autorizado no se pierde por un fallo del tercero.
- `Application/UseCase/Customer/LookupCustomer` — P7. Emite `customer.looked_up`
  con actor e IP cuando encuentra; `auth.authorization_denied` con
  `reason=customer_not_found` cuando no —lo que un barrido por numero delata—.
- Puerto `CustomerRegistry::findByCustomerNumber` (lectura por identidad publica),
  implementado en `EloquentCustomerRegistry` y en `InMemoryCustomerRegistry`.
- Tres tipos de evento nuevos en `AuditEventType`:
  `credit_application.approved`, `card.issuance_failed`, `customer.looked_up`.
  La columna es `string(60)`, sin migracion.
- Tres dobles en memoria: `InMemoryIdentityValidationRepository`,
  `InMemoryCreditApplicationRepository`, `InMemoryCustomerRegistry`. Los tres
  reproducen el comportamiento observable del adaptador Eloquent: identificadores
  crecientes en orden, `attempts` por prospecto, idempotencia sobre `public_id`.
- `PortBindingTest`: los tres puertos entran en `portProvider()` y en la tabla
  de dobles. 25 pruebas en verde.

### Las tres invariantes que fijan las pruebas de AcceptCreditOffer

1. **CWE-639 cerrado.** `a_token_from_another_prospect_cannot_accept_the_simulation`
   crea dos prospectos, uno duenio y otro extranio, y prueba que el extranio no
   puede aceptar la simulacion ajena aunque conozca su UUID. Cierre: la
   solicitud del prospecto autenticado no coincide con el `credit_application_id`
   de la simulacion, y el use case rechaza.
2. **Un evento por escritura, en orden (RF-13).** `the_happy_path_emits_the_five_events_in_order`
   contrasta la secuencia contra
   `simulation.accepted -> application.approved -> customer.created -> line.opened -> card.issued`.
   Si algun paso deja de escribir, la comparacion cambia.
3. **Asimetria transaccional (Fase 2).** `card_issuance_failure_leaves_customer_and_line_and_records_the_event`
   provoca el fallo del emisor y comprueba que cliente y linea siguen ahi, que la
   tarjeta NO, y que el ultimo evento es `card.issuance_failed`. Es la
   propiedad por la que el credito autorizado no se pierde por un fallo del
   tercero.

Ademas, `the_card_issued_event_never_carries_the_token` fija que el token no
viaja al log ni por descuido: solo `last_four` y `brand`.

### Decisiones que conviene tener a la vista

**El "verificado" completo exige documento.** `FakeIdentityValidator::willVerify`
devuelve `documentValidity=Pending` sin documento, y el conjunto queda Pending:
la prueba del "verificado" en `ValidateIdentityTest` pasa por
`storedDocument()` para que los cuatro criterios queden en Verified. Es fiel al
disenio —la vigencia del documento no se puede evaluar si no hay documento— y
el use case no fuerza un veredicto favorable sin evidencia.

**El estado inicial de la tarjeta es `issued`.** El default de la migracion. Un
paso posterior la activa; el adaptador de persistencia no impone la transicion.

**`SimulateCredit` todavia no persiste.** El caso de uso actual solo devuelve
el `CreditOfferOutput`; no llama a `saveSimulation`. Para que
`AcceptCreditOffer` funcione contra la API, el endpoint de P5 (bloque 5) debera
completar `SimulateCredit` con `save(application)` + `saveSimulation(simulation)`.
Las pruebas del bloque 3 no lo necesitan porque usan dobles en memoria y
persisten la simulacion directamente en `setUp`.

**El acceso al numero de cliente por P7 no se filtra por autorizacion en el use
case.** El endpoint es el que impone rol y politica (`Permission::ReadCustomer`,
que aparecera en el bloque 6). El use case solo hace la lookup y deja rastro.
Meter la autorizacion aqui obligaria a duplicar la logica de scopes y rompeia
la simetria con `LookupProspect`.

**Siguiente paso pendiente:** T9a (parcial 4/6) — endpoint P2.

1. `Http/Controllers/Api/ProspectController::update` (o metodo aparte) que
   invoca `CaptureProspectData` y `ConfirmProspectData` desde dos rutas:
   `PATCH /prospects/me` (captura) y `POST /prospects/me/confirm` (confirmacion).
2. `Http/Requests/Prospect/CaptureProspectDataRequest`,
   `Http/Requests/Prospect/ConfirmProspectDataRequest`. Reglas: CURP con digito,
   RFC opcional, sexo, edad, ingreso mensual.
3. `Policies/ProspectPolicy` con `updateOwn` — el expediente sale del token, no
   de la URL, asi que la politica compara `$user->prospect_id === $prospect->id`.
4. Registro en `bootstrap/app.php` del middleware si hace falta, ampliacion de
   `routes/api.php` bajo el grupo con scope `prospect-session`.
5. Pruebas: `ProspectDataCaptureTest` con casos 401 sin token, 403 sobre
   expediente ajeno (fabricar dos prospectos, token del primero, PATCH del
   segundo), 422 con CURP mal formada, 200 con captura completa. Metadata en
   `auth.authorization_denied` cuando 403 (RS-06.a). Actualizar la lista cerrada
   de `ApiAccessControlTest::the_endpoints_are_authenticated_by_default`.

---

## [2026-08-23 10:15] T9a (parcial 2/6) — Persistencia de solicitud, cliente y validacion de identidad
**Estado:** EN PROGRESO — bloque 2 de T9a. Faltan casos de uso (bloque 3) y los
endpoints P2, P4, P5, P6, P7 (bloques 4 a 6, uno por endpoint).
**Commit:** ver `git log --oneline` (commit `T9a (parcial): persistencia...`)
**Evidencia:** `php artisan test` -> 326 pruebas / 882 aserciones en verde (19 nuevas);
`./vendor/bin/pint --test` limpio.

### Que se hizo

Tres adaptadores Eloquent, sus registros de fila, y el wire-up de los tres puertos:

- `EloquentIdentityValidationRepository` (`save`, `findLatestFor`). `attempts`
  cuenta por prospecto y no por fila —dos intentos sobre el mismo expediente
  son 1 y 2, un intento sobre otro prospecto arranca en 1 igual—.
- `EloquentCreditApplicationRepository` (`save`, `findByProspectId`,
  `saveSimulation`, `findSimulationByPublicId`, `findLatestSimulationFor`).
  `save` es idempotente sobre `public_id`: reescribir la solicitud actualiza la
  fila, no la duplica. La simulacion no guarda el tipo de credito ni el
  ingreso validado; los lee de la solicitud al reconstruirse.
- `EloquentCustomerRegistry` (`register`, `attachCard`), con **atomicidad
  asimetrica** —vease abajo—.

### La decision que hay que tener a la vista: el split atomico

El puerto expone dos metodos porque son dos cosas distintas, pero la propiedad
que hay que fijar es la asimetria de sus transacciones:

- `register()` escribe cliente y linea **en una sola transaccion**. Un cliente
  sin linea es un estado que el negocio no contempla, y es el argumento con el
  que la Fase 2 descarto microservicios. La prueba
  `register_is_atomic_when_the_line_insert_fails` fuerza un fallo por FK sobre
  `credit_lines.credit_simulation_id` y exige que la fila del cliente NO quede.
- `attachCard()` corre **fuera** de esa transaccion. El emisor es un tercero:
  sostener bloqueos de base de datos durante la latencia de la red seria peor
  que quedarse sin tarjeta un rato. La prueba
  `customer_and_line_survive_when_attach_card_fails` provoca el fallo con el
  UNIQUE de `tokenized_card_number` y exige que cliente y linea sigan intactos.
  Perder el alta por un fallo del emisor seria peor que quedarse sin tarjeta.

El estado inicial de la tarjeta es `issued` —el default de la migracion— hasta
que un paso posterior la active. Mantenerlo aqui evita que el adaptador imponga
una transicion que no le corresponde.

### Bitacora — lo que queda para el bloque 3

Los repos NO llaman al `AuditLogger`. El patron actual (visto en
`StartProspectCapture`) es que el caso de uso orquesta: save al repo, append al
logger. En el bloque 3 los tres casos de uso nuevos —`ValidateIdentity`,
`AcceptCreditOffer`, `LookupCustomer`— dejan cada uno su evento (RF-13). Sin
esa parte, las escrituras que introduce el bloque 2 no quedan trazadas.

### Detalles menores

- MySQL normaliza el orden de las claves de un JSON al persistirlo, asi que
  las aserciones sobre `provider_response` van por `assertEqualsCanonicalizing`.
- Las pruebas viven en `tests/Feature/Persistence/` para separarlas del resto
  de las Feature; el namespace es `Tests\Feature\Persistence`.
- `PortBindingTest` NO se ha ampliado a los tres puertos nuevos porque el
  provider exige tambien un doble en `Tests\Support\Doubles`, y esos dobles no
  se necesitan hasta el bloque 3. Se anaden alli.

**Siguiente paso pendiente:** T9a (parcial 3/6) — tres casos de uso nuevos.

1. `Application/UseCase/Identity/ValidateIdentity` — orquesta `IdentityValidator`
   (proveedor) y `IdentityValidationRepository::save`. Al terminar, escribe
   `identity_validation.performed` en la bitacora con `overall_status` y `folio`
   (no `curp`, no `rfc`). Si el resultado marca fraude, escribe ademas
   `identity_validation.fraud_flagged`. Recibe `AuditContext` y `DateTimeImmutable`.
2. `Application/UseCase/Credit/AcceptCreditOffer` — recibe el `Uuid` publico de
   la simulacion. Comprueba `isExpired()` (rechaza con
   `InvalidStateTransitionException`). Llama a `CreditRulesEngine`? No: solo
   marca la simulacion como aceptada, aprueba la solicitud (`approve`), pide al
   `CustomerRegistry::register` que abra cliente y linea, y llama al
   `CardIssuer` + `attachCard` (separado, sin transaccion). Escribe
   `credit_offer.accepted`, `credit_application.approved`, `customer.registered`,
   `card.attached` o `card.pending` segun el resultado.
3. `Application/UseCase/Customer/LookupCustomer` — recibe `customer_number` y
   devuelve la vista de P7. Escribe `customer.looked_up` con actor e IP.

Los dobles en memoria para los tres puertos van en `tests/Support/Doubles/`
—`InMemoryIdentityValidationRepository`, `InMemoryCreditApplicationRepository`,
`InMemoryCustomerRegistry`— y se anaden ademas a `portProvider()` de
`PortBindingTest`. Pruebas unitarias del caso de uso contra los dobles.

---

## [2026-08-22 16:45] T9 (parcial 1/3) — Sesion del prospecto: la quinta excepcion a la regla 9
**Estado:** EN PROGRESO — primer bloque de T9. **T9 no esta terminada:** faltan el resto de
endpoints del recorrido (P2, P4, P5, P6, P7) y las siete vistas de Vue.
**Commit:** ver `git log --oneline` (commit `T9 (parcial): sesion del prospecto...`)
**Evidencia:** `php artisan test` -> 307 pruebas / 791 aserciones en verde (15 nuevas);
`pint` limpio.

### Por que este bloque existe

Al contrastar las siete vistas contra la API aparecieron **dos huecos que el plan T1-T12
nunca asigno a ninguna tarea**:

1. De los siete pasos, **solo P3 tiene endpoints**. No existen los de iniciar solicitud,
   capturar datos, validar identidad, simular, aceptar la oferta ni consultar clientes.
2. `IdentityDocumentController` ya deriva el prospecto de `$request->user()->prospect_id`,
   o sea que la API **asume un prospecto autenticado**, pero no habia forma de que un
   visitante nuevo obtuviera esa sesion, y la P1 del prototipo no tiene pantalla de acceso.

El usuario eligio la opcion 1 de las tres planteadas: **token de prospecto emitido en P1**.

### Lo que se hizo

- `Infrastructure/Security/ProspectSessionIssuer` + `IssuedSession`.
- `Infrastructure/Security/CaptchaVerifier` con `SimulatedCaptchaVerifier` y
  `TurnstileCaptchaVerifier`, tabla de drivers en `AuthorizationServiceProvider`.
- `Api/ProspectController` (`store`, `show`, `renewSession`) y
  `Http/Requests/Prospect/StartProspectCaptureRequest`.
- Scopes `prospect-session` y `customer-session` en el catalogo de Passport; alias de
  middleware `scopes` en `bootstrap/app.php`.
- `OAuthPersonalAccessClientSeeder`: Passport necesita cliente de tokens personales para
  emitir en P1, que no pasa por el flujo de codigo de autorizacion.
- `config/security.php`: secciones `prospect_session`, `privacy_notice` y `captcha`.

### Las seis precisiones del usuario

**1. Vigencia corta.** 30 minutos por token (`PROSPECT_SESSION_TTL`), no sesion larga: la
Fase 1 estima el tramite en menos de 5 minutos (RNF-02). La renovacion emite uno nuevo y
**no revoca el anterior** a proposito: P3 consulta el estado del OCR en bucle y revocar en
caliente dejaria sin credencial a la peticion que ya iba por el cable. Cada token caduca
por su cuenta, asi que el limite de 30 minutos se cumple igual.

**2. Alcance acotado.** El token nace con scope `prospect-session` y el rol es `prospect`.
Son dos barreras independientes: la prueba
`a_token_without_the_scope_is_refused_even_with_the_right_role` usa el mismo usuario con
el mismo rol y un token sin alcance, y recibe 403. El expediente sale **siempre** del
token y no de la URL, asi que no hay identificador ajeno que nombrar (CWE-639), y
`CreditApplicationPolicy` sigue decidiendo sobre el registro.

**3. CAPTCHA ademas del throttle.** No son redundantes y por eso van los dos: `throttle:5,1`
cuenta por direccion IP, y repartir el trabajo entre muchas direcciones pasa por debajo del
umbral sin esfuerzo. El driver `simulated` **no acepta cualquier cosa** —solo el token de
prueba configurado—, para que el control sea comprobable sin depender de un tercero. La
verificacion esta en el controlador y no en el FormRequest **para poder registrar el
rechazo en la bitacora**: un pico de rechazos es justo la senal del riesgo R-05.

**4. Consentimiento con evidencia.** `privacy_notice_accepted` deja de ser un `true` sin
rastro: `StartProspectCapture` recibe ahora la version del aviso y la bitacora guarda
`privacy_notice_accepted_at`, `privacy_notice_version` e `ip_address` —esta ultima en
columna propia y **dentro del material del hash**, de modo que no se puede retocar sin
romper la cadena—. La version vive en `config('security.privacy_notice.version')`: si el
aviso cambia, los consentimientos anteriores siguen diciendo a que texto se referian.

**5. Reemision al convertirse en cliente.** `promoteToCustomer()` revoca **todos** los
tokens de prospecto antes de emitir el de `customer-session`. Dejar vivo el anterior seria
una escalada silenciosa: el mismo token pasaria a valer para endpoints que no existian
cuando se emitio.

**6. Quinta excepcion declarada.** En la cabecera de `routes/api.php` con su justificacion
completa, en la lista cerrada de `ApiAccessControlTest` y en la fila RNF-07.a de
`SECURITY_CHECKLIST.md`. Se anadio ademas la fila **RS-10.a** para el control
anti-automatizacion, que no tenia entrada.

### Una decision que conviene tener a la vista

**El usuario del prospecto no es una cuenta.** Toda la API se apoya en `users.prospect_id`,
asi que hace falta un usuario; pero ese usuario **no puede iniciar sesion con contrasena**:
su correo esta en el dominio reservado `.invalid` (RFC 2606), que no existe ni puede
recibir correo, y su contrasena es una cadena aleatoria de 64 caracteres que no conoce
nadie y que no se devuelve en ninguna respuesta. La unica credencial es el token, y el
token caduca. Lo fija `the_generated_user_cannot_log_in_with_a_password`.

**Nota sobre la prueba de caducidad.** No se comprueba viajando en el tiempo: la caducidad
del JWT la valida league/oauth2-server contra el reloj real del sistema, que
`Carbon::setTestNow` no toca. Se comprueba sobre `expires_at` del token emitido.

**Siguiente paso pendiente:** T9 (parcial 2/3) — endpoints de P2 (captura y confirmacion),
P4 (validacion de identidad), P5 (simulacion), P6 (aceptacion y alta de cliente) y P7
(consulta). Hacen falta tres casos de uso nuevos: `ValidateIdentity`, `AcceptCreditOffer` y
`LookupCustomer`. Los de P2 y P5 ya existen (`CaptureProspectData`, `ConfirmProspectData`,
`SimulateCredit`) y solo necesitan controlador y FormRequest.

---

## [2026-08-22 16:20] chore — Limite de la cadena documentado y verificacion 8 del script arreglada
**Estado:** COMPLETADO — dos encargos del usuario, ninguno de ellos una tarea T#.
**Commit:** ver `git log --oneline` (commit `chore: el limite de la cadena...`)

### 1. El riesgo residual de la bitacora, aceptado y por escrito

El usuario acepta `--expect-tip` como mitigacion y **no cierra el hueco**: HMAC con llave
romperia que un auditor externo pueda recalcular la cadena —propiedad que si se quiere
conservar, y que hoy sostiene `AuditLogController` publicando `previous_hash` y
`current_hash`—, y WORM o publicacion en un tercero se apartan de la Fase 2.

- **CLAUDE.md §6.5** (nueva): que detecta la cadena, que no, y por que se acepta el
  riesgo. La distincion de fondo es que la cadena acredita la **consistencia interna** de
  la bitacora, no su **completitud**.
- **SECURITY_CHECKLIST.md**, seccion nueva «Riesgos residuales aceptados». No recibe
  identificador propio: se nombra por el control del que es residuo (RS-06.b). No va en
  `BUGS.md` porque no es un defecto reproducible, sino una consecuencia del diseno
  (CLAUDE.md §6.2), pero callarlo si seria un defecto del expediente.

### 2. Verificacion 8 del script: cadenas de varias lineas (autorizado por el usuario)

**Que estaba mal.** El limpiador AWK descartaba cadenas y comentarios **linea a linea**
(`gsub(/'[^']*'/, " ", line)`). Una cadena abierta en una linea y cerrada tres mas abajo
—la firma multilinea habitual de un comando de Laravel— dejaba a las lineas de en medio
pareciendo codigo, y sus palabras en espanol salian como identificadores.

**Que se cambio, y solo esto.** El bloque de limpieza pasa de cuatro `gsub` por linea a
una funcion `strip()` que recorre caracter a caracter y **mantiene el estado entre lineas**
en `SST` (`""` codigo, `'` `"` o `` ` `` dentro de cadena, `*` comentario de bloque). Es el
mismo tratamiento que los comentarios de bloque ya tenian. **No se toco `LANG_WORDS`, ni
las excepciones (RFC, CURP, INE, RENAPO), ni los directorios revisados, ni ningun otro
criterio.**

De regalo queda mas correcto en un punto que antes fallaba al reves: el orden anterior
descartaba cadenas **antes** que comentarios, asi que un apostrofe dentro de un `//`
abria una cadena falsa. Ahora el comentario corta primero.

**Como se comprobo:**
1. Salida completa del script antes y despues sobre este repositorio: **identica** salvo
   marcas de tiempo y duraciones.
2. Bateria de ficheros de prueba con identificadores en espanol reales en `.php`, `.js` y
   `.vue` (`prospecto`, `usuario`, `monto`, `tarjeta`): **los cuatro se siguen detectando**.
3. Los mismos ficheros con espanol dentro de cadenas multilinea (comilla simple, comilla
   doble, plantilla de JS) y en comentarios: el limpiador antiguo daba **5 falsos
   positivos**, el nuevo da **0**.
4. Caso de estado pegado: un apostrofe suelto en un comentario seguido de un identificador
   en espanol mas abajo. El identificador se sigue detectando: el estado no se queda
   abierto tragandose el resto del fichero.

**Consecuencia en el codigo de T8.** Se revierte el apano de
`VerifyAuditChainCommand::$signature`, que estaba escrito como cadena concatenada solo
para esquivar el falso positivo. Vuelve a ser la firma multilinea idiomatica de Laravel y
el script ya no la marca.

**Siguiente paso pendiente:** T9 — las 7 vistas de Vue y el router. Sin cambios respecto a
la entrada de T8.

---

## [2026-08-22 15:40] T8 — Bitacora verificable: `audit:verify-chain` y las tres formas de manipulacion
**Estado:** COMPLETADO
**Commit:** ver `git log --oneline` (commit `T8: ...`)
**Evidencia:** `php artisan test` -> 292 pruebas / 735 aserciones en verde (24 nuevas);
`npm test` en `worker/` -> 10 en verde; `bash scripts/verificar_avance.sh` -> **T8: OK
(8 ok / 0 falta / 0 revisar)**, higiene transversal 9/9, totales 85 OK / 6 FALTA /
2 REVISAR; `./vendor/bin/pint --test` limpio.

**Que habia y que faltaba.** El encadenamiento existia desde T2/T3: `AuditChain`,
`AuditEvent`, `SensitiveDataMasker`, `EloquentAuditLogger` con `lockForUpdate` y
`AuditLogRecord` bloqueando UPDATE y DELETE. Lo que no habia era **como comprobarlo**: sin
verificador, una cadena rota se descubre leyendo hashes a mano.

**Lo nuevo:**
- `Domain/Audit/AuditChainVerifier` + `AuditChainLink`, `ChainBreak`, `ChainBreakKind`,
  `ChainVerificationResult` — recorrido puro, sin Laravel.
- `Infrastructure/Persistence/Eloquent/AuditChainInspector` — lectura perezosa por
  paginas (`lazyById`), porque la bitacora crece sin limite por definicion.
- `Console/Commands/VerifyAuditChainCommand` — `php artisan audit:verify-chain`.
- `AuditChain::hashOfFields()` — una sola canonicalizacion para escribir y para verificar.
- 24 pruebas: `Tests\Unit\Domain\AuditChainVerifierTest` (11) y
  `Tests\Feature\Audit\VerifyAuditChainCommandTest` (13).

### Los cinco puntos que fijo el usuario

**1. Codigo de salida distinto de cero, para integracion continua.** `0` cadena integra,
`1` cadena rota, `2` la punta no coincide con `--expect-tip`. La tuberia se detiene sola
sin que nadie lea la salida.

**2. Las tres formas de manipulacion, y por que hacen falta dos comprobaciones.** Por cada
registro se comprueba (a) que su `previous_hash` sea el `current_hash` del que lo precede
y (b) que su hash recalculado coincida con el almacenado. **Solo (b) detectaria la
alteracion y se le escaparian el borrado y la insercion**, porque en esos dos casos cada
fila superviviente sigue siendo coherente consigo misma; lo que cambia es la costura entre
registros. Comprobado en vivo sobre MySQL:

| Manipulacion | Resultado |
|---|---|
| Alterar `ip_address` del #2 | `contenido alterado` en #2, salida 1 |
| Borrar el #4 (intermedio) | `eslabon roto` en #5, «no enlaza con el #3 que lo precede», salida 1 |
| Insertar un #15 entre #10 y #20 **con sus hashes bien calculados** | `eslabon roto` en #20, «no enlaza con el #15 que lo precede», salida 1 |

La insercion se prueba en su version dificil: el atacante enlaza bien con el #10 y calcula
bien el hash del registro que mete, asi que **ese registro pasa las dos comprobaciones**.
Lo que no puede es arreglar al #20 sin rehacer todo el tramo final.

El contenido se recalcula con el `previous_hash` **almacenado** del propio registro y no
con el esperado. Si se usara el esperado, un borrado intermedio saldria ademas como
contenido alterado que no lo esta, y el diagnostico dejaria de servir en un incidente.

**3. Que campos entran en el hash — documentado en `Domain/Audit/AuditChain`.** El
material es la concatenacion con `|` de nueve elementos en orden fijo: `previous_hash`,
`prospect_id`, `affected_entity`, `affected_entity_id`, `event_type`, `actor`,
`ip_address`, `event_at` (UTC, ATOM, precision de segundo) y `metadata` (JSON con claves
ordenadas recursivamente). Es decir **todas las columnas de `audit_logs` salvo dos**:

- `id`, porque lo asigna el AUTO_INCREMENT durante el INSERT, o sea despues de calcular el
  hash; incluirlo obligaria a insertar y luego actualizar, y una bitacora append-only no
  admite ese UPDATE. No hace falta: renumerar o reordenar cambia quien precede a quien y
  eso rompe la comprobacion de eslabon.
- `current_hash`, que es el resultado y no puede ser tambien la entrada.

`ip_address` y `metadata` estan **dentro** a proposito: son justo lo que un atacante
querria retocar. Hay dos pruebas que alteran solo uno de ellos y exigen que se detecte.
Y `::the_hashed_columns_cover_the_whole_table` compara `HASHED_COLUMNS` +
`UNHASHED_COLUMNS` contra `Schema::getColumnListing('audit_logs')`: **anadir una columna
sin decidir si entra en el hash pone la prueba en rojo**, que es la unica forma de que la
lista documentada no se quede atras del esquema en silencio.

**4. VUL-04: se enmascara ANTES de firmar.** `AuditEvent` pasa los metadatos por
`SensitiveDataMasker` en su constructor y `AuditChain::hash()` firma `$event->metadata`,
ya enmascarado: se firma exactamente lo que se almacena. Si el orden fuera el inverso, la
verificacion fallaria sobre registros legitimos y la bitacora dejaria de ser evidencia.
Fijado por dos pruebas: una guarda un evento con CURP, comprueba que se almaceno redactado
y exige que `audit:verify-chain` salga en 0; la otra recalcula el hash sobre el metadato
en claro y exige que **no** coincida con el almacenado.

**5. Se reporta el registro, no «cadena invalida».** Cada ruptura sale con id, tipo de
evento, actor, `event_at`, el valor esperado y el almacenado, y el comando nombra
explicitamente la primera. `--json` da lo mismo en estructura, para consumo automatico.

### Limitacion documentada: el truncado del final

**Lo que la cadena NO puede detectar por si sola es el borrado de los ULTIMOS registros:**
lo que queda sigue siendo una cadena perfectamente coherente. Tampoco detecta la
reescritura completa del tramo final, porque el hash no lleva llave y el material es
publico: quien tenga escritura sobre la tabla puede recalcular una cadena entera.

Lo que la cadena detecta es la **manipulacion parcial**, que es la realista. Contra el
truncado hace falta un ancla externa, y por eso el comando imprime el hash de la punta y
acepta `--expect-tip`: se guarda ese hash fuera de esta base de datos y se le pasa al
comando. Comprobado en vivo: tras borrar el ultimo registro, sin ancla sale `0`; con el
ancla del hash anterior sale `2` y dice «LA PUNTA NO COINCIDE».

**Esto queda como decision del usuario, no del asistente.** Cerrar el hueco del todo
exigiria una de tres cosas —firmar la cadena con HMAC y llave en el vault (rompe que un
auditor externo pueda recalcularla, que es lo que hoy publica `AuditLogController`),
almacenamiento WORM, o publicar la punta periodicamente en un tercero— y las tres se
apartan del diseno de la Fase 2. No se toma esa decision por cuenta propia.

### Dos cosas que hubo que ajustar

**El comando no escribe en la bitacora.** Registrar cada verificacion la haria crecer con
cada corrida de integracion continua y, peor, anadiria un eslabon en mitad de la
comprobacion. Auditar la bitacora es una lectura.

**No se declaro un puerto nuevo.** Los siete puertos de `Domain/Port` existen para que el
dominio llame hacia afuera; aqui no llama a nadie, `AuditChainVerifier` recibe un iterable
y no sabe de donde sale. El adaptador es `AuditChainInspector` y la regla de dependencia
se respeta igual. La lista de siete puertos de CLAUDE.md §4 no cambia.

**Se reescribio una prueba que prometia mas de lo que probaba.**
`EloquentAuditLoggerTest::test_tampering_with_a_record_is_detectable` verificaba el evento
que quedo **en memoria** contra el hash almacenado: ese objeto no lo toco nadie y seguia
dando verde con la fila ya manipulada. Ahora recalcula sobre lo almacenado con
`AuditChainInspector` y exige `ContentAltered` en el id correcto.

### Aviso sobre `scripts/verificar_avance.sh` (seccion 9: no se toco)

La verificacion 8 (convencion de idioma) descarta las cadenas de texto **linea a linea**.
Una firma multilinea de Laravel —`protected $signature = 'audit:verify-chain` abierta en
una linea y cerrada tres mas abajo— deja a las lineas de en medio pareciendo codigo, y
marco «registro» y «registros» de las descripciones de las opciones como identificadores
en espanol. **No es un fallo del script ni algo que el asistente pueda arreglar por su
cuenta** (§9 solo permite anadir palabras a `LANG_WORDS`, que aqui empeoraria las cosas).
Se resolvio en el codigo: la firma es una sola cadena concatenada, con cada trozo abierto
y cerrado en su propia linea. Queda anotado por si el usuario prefiere ajustar el script.

### Anotado para T10 (instruccion del usuario, 2026-08-22)

1. **Sustituir `getMessage()` por `userMessage()`** en el `catch (DocumentUploadRejected)`
   de `Http/Controllers/Api/IdentityDocumentController::store()`. Hoy el mensaje que llega
   al cliente es seguro por convencion —`DocumentUploadRejected` se lanza solo en
   `UploadedDocumentStore` con textos ya redactados—; con `userMessage()` pasa a ser una
   decision explicita de cada excepcion. **Requiere ademas** mover
   `Infrastructure/Storage/DocumentUploadRejected` a la jerarquia de
   `Domain/Exception/DomainException`, que es quien declara `userMessage()` y
   `errorCode()`; hoy extiende `RuntimeException` a secas.
2. **Anadir una prueba de arquitectura** que falle si algun controlador devuelve
   `getMessage()` al cliente: recorrer `app/Http/Controllers`, y marcar todo
   `getMessage()` que no vaya a `Log::`. Es la version automatica del `[REVISAR]` de T10
   «3 uso(s) de getMessage()/getTraceAsString() en backend/app/Http».

**Siguiente paso pendiente:** T9 — las 7 vistas de Vue navegables de extremo a extremo
(`WelcomeView`, `ProspectDataFormView`, `DocumentUploadView`, `VerificationResultView`,
`CreditSimulationView`, `AuthorizationConfirmedView`, `CustomerLookupView`) y el router en
`frontend/src/router/`. El script las marca en `[FALTA]` dentro de T9. El usuario dijo que
corre la auditoria antes de T9.

**Nota de entorno:** T1 depende de que el servidor de desarrollo este levantado
(`php artisan serve --host=127.0.0.1 --port=6060`); parado, esa verificacion sale en
`[FALTA]` sin que haya regresion.

---

## [2026-08-22 12:05] T7 — Worker Node 24 + BullMQ, endpoint 202 y ciclo asincrono completo
**Estado:** COMPLETADO — tercer y ultimo bloque de T7.
**Commit:** ver `git log --oneline` (commit `T7: endpoint 202...`)
**Bloques anteriores:** el productor de PHP y el worker de Node se commitearon aparte
(`0d7b181`, `9a79085`) para no perderlos tras el corte de conexion.

**Que se cerro en este bloque: la capa HTTP.**
- `Api/IdentityDocumentController@store` — responde **202 Accepted** con
  `tracking_id`, `ocr_status`, `attempt` y `status_url`. No espera al OCR.
- `Api/IdentityDocumentController@show` — seguimiento por el identificador del 202.
- `Internal/OcrResultController@store` — lo que llama el worker al terminar.
- `Application/UseCase/Identity/RecordOcrOutcome` + `DTO/OcrOutcomeInput`.
- `Http/Requests/Identity/{UploadIdentityDocumentRequest,RecordOcrOutcomeRequest}`.
- Alias de middleware `client` (`EnsureClientIsResourceOwner`) y catalogo de scopes con
  `ocr-result`.

**Por que 202 y no 200.** El OCR lo hace otro proceso y puede reintentarse tres veces. Si
este endpoint esperara al resultado, cada carga ocuparia un proceso de PHP-FPM durante
segundos y una racha de subidas agotaria el pool (Fase 2, riesgo R-03). 202 es la
respuesta honesta —«lo recibi, todavia no esta hecho»— y el identificador es como el
cliente pregunta despues. La prueba `the_upload_answers_202_accepted_with_a_tracking_id`
exige ademas que el estado devuelto sea `processing` y no `completed`: si alguien
convirtiera esto en sincrono para «simplificar», se pone roja.

**Tres decisiones de seguridad que no son adorno:**
1. **El prospecto sale del usuario autenticado, nunca de un campo de la peticion.**
   Aceptar un `prospect_id` del cliente es la forma clasica de IDOR (CWE-639). Asi no hay
   objeto ajeno que nombrar. En el seguimiento, un documento de otro prospecto responde
   **404 y no 403**: un 403 le confirmaria al que prueba identificadores que ese documento
   existe.
2. **Correlacion por `job_ref`.** El resultado tiene que corresponder al intento vigente
   (`ocr_job_id`). Un resultado rezagado de un intento anterior se rechaza con 409 en vez
   de pisar el estado actual. Y el callback es **idempotente**: repetir el aviso no
   duplica eventos en la bitacora, comprobado contando filas.
3. **La ruta interna la autentica un token de cliente, no de persona.** Un token de
   usuario —aunque sea admin— recibe 401 ahi. Si bastara una sesion de persona, cualquiera
   con cuenta podria inyectar el resultado de un OCR que nunca se ejecuto. El `reason` es
   un codigo cerrado (`unreadable` | `exhausted`) y no texto libre: lo que manda el worker
   acaba en la bitacora, y ahi no se vuelca la respuesta cruda de un tercero.

**Verificacion (2026-08-22, en este servidor):**
- **Ciclo completo en vivo, con el worker en modo HTTP.** Se levanto la API en
  127.0.0.1:6060, se encolo un documento real con `OCR_DRIVER=bullmq` y se arranco el
  worker con `BACKEND_ADAPTER=http` y un cliente de client_credentials. El worker pidio su
  token, consumio el trabajo, llamo a `/api/v1/internal/ocr-results` y el documento paso a
  `ocr_status=completed`, con `processed_at`, con el `ocr_result` guardado y con
  `document.ocr_completed` en la bitacora. **Es el unico eslabon que ninguna prueba
  automatizada cubre —la llamada HTTP real del worker— y por eso se comprobo a mano.**
  Los datos de prueba se borraron despues, y **el cliente OAuth creado para la prueba se
  revoco** porque su secreto quedo impreso en la sesion (regla 3).
- `PAO_DISABLE=1 php artisan test` -> **268 pruebas aprobadas, 658 aserciones** (22
  nuevas: 10 de carga, 10 del callback, 2 de contrato).
- `npm test` en `worker/` -> 10 pruebas aprobadas.
- `bash scripts/verificar_avance.sh` -> **T7: OK (10 ok / 0 falta)**.
- `./vendor/bin/pint` -> limpio.

**`BullMqContractTest`, que es la que sostiene el acoplamiento.** Encola desde PHP y hace
que un `Worker` de BullMQ **de verdad** lo consuma, con `worker/tests/support/consumeOnce.mjs`.
Comprobar que las claves quedan escritas solo probaria que PHP hace lo que PHP cree; lo que
hace falta es que la libreria las entienda. Si una actualizacion de BullMQ cambia el
formato interno, esta prueba se pone roja aqui y no en produccion con trabajos
perdiendose en silencio. Se omite —no falla— si no hay Redis o `worker/node_modules`: una
prueba que falla por el entorno acaba ignorandose.

**Se amplio una prueba de T5, y conviene saber por que.**
`ApiAccessControlTest::the_endpoints_are_authenticated_by_default` rechazaba toda ruta sin
middleware `auth`/`auth:`. La ruta interna esta autenticada, pero con un token de
client_credentials: el filtro ahora acepta tambien `client`/`client:`. **No se abrio la
lista de excepciones**, que sigue siendo las mismas cuatro rutas; se corrigio la
definicion de «autenticada», que era mas estrecha que la regla 9.

**Marcado en `SECURITY_CHECKLIST.md`:** **VUL-01 pasa a Implementado**, con la prueba que
el propio control pedia (`an_executable_renamed_as_an_image_is_rejected`).

**Un [REVISAR] nuevo del script, que se deja como esta y se explica.** La verificacion de
T10 marca «3 uso(s) de getMessage()/getTraceAsString() en backend/app/Http». Los tres son
de este bloque y ya estan revisados: **dos van al registro del servidor** y no salen de
ahi; **el tercero si viaja al cliente**, en el `catch (DocumentUploadRejected)` del
controlador de carga. Es deliberado y es seguro hoy porque esa excepcion solo se lanza en
`UploadedDocumentStore` con mensajes redactados a mano para el usuario final («El tipo de
archivo no esta permitido.»), sin tipo detectado ni limite concreto. **La fragilidad esta
en que depende de una convencion**: si alguien lanzara esa excepcion con el mensaje de otra
excepcion dentro, el detalle tecnico saldria al cliente. El script hace bien en pedir que
se mire. No se toca el script (seccion 9) y queda anotado aqui para T10, que es donde se
normalizan los errores genericos.

**Siguiente paso pendiente:** T8 — bitacora de auditoria append-only con encadenamiento
SHA-256 y el comando `audit:verify-chain`. Segun el script faltan dos verificaciones: que
el comando exista y una prueba que compruebe la deteccion de manipulacion. El usuario dijo
que corre la auditoria antes de T8.

**Nota de entorno:** la verificacion de T1 (`curl -I http://127.0.0.1:6060`) sale en
`[FALTA]` porque el servidor de desarrollo se detuvo al terminar la prueba en vivo. No es
una regresion: se levanta con `php artisan serve --host=127.0.0.1 --port=6060`.

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
