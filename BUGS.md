# BUGS.md — Hallazgos de seguridad verificados sobre este repositorio

**Este archivo es la evidencia de auditoría del proyecto.** Solo entra aquí lo que puede
demostrarse con un comando o un procedimiento reproducible sobre este código. Si un
hallazgo no puede señalar su evidencia, no va en `BUGS.md`: perdería su valor todo lo
demás que sí la tiene.

## Criterio de admisión

Una entrada solo se abre si procede de una de estas tres fuentes:

1. La salida de `composer audit`, `npm audit` o de un análisis estático **ejecutado sobre
   este repositorio**.
2. Un defecto de seguridad detectado en el código real y **reproducible**, incluidos los
   que introduzca y corrija el propio asistente.
3. Un reporte del usuario sobre algo observado.

Cada entrada indica la **fecha** y **cómo se detectó** (el comando o el procedimiento).

**Lo que no entra aquí:** los controles preventivos que exige el diseño de las Fases 1 a 3
—incluidos los identificadores VUL-01 a VUL-08— viven en `SECURITY_CHECKLIST.md`. No
salieron de analizar este código; presentarlos como hallazgos sería inexacto.

Severidad: Crítica / Alta / Media / Baja.
Estado: Abierto / En progreso / Resuelto / Aceptado con justificación.
**Ninguna entrada se borra**; las resueltas se marcan como resueltas.

## Registro

| ID | Fecha | Severidad | Componente | Hallazgo | Cómo se detectó | Estado | Remediación | CWE / OWASP |
|---|---|---|---|---|---|---|---|---|
| VUL-09 | 2026-08-21 | Media | MS-05 Credit Engine (`backend/app/Domain/Credit/CreditPolicy.php`) | **Introducido por el asistente en T3.** La tabla de tasas de la política vigente era 60 / 36 / 42 %: el crédito de negocio —el perfil de menor riesgo, con mayor ingreso y actividad económica declarada— salía más caro que el personal. No provoca ninguna excepción: el motor calcula y ofrece con normalidad, de modo que el defecto solo se ve leyendo las tres cifras juntas. Emite condiciones fuera de política y alimenta el riesgo R-08 (autorizar créditos indebidos). | Lectura del código de `CreditPolicy::default()` durante T6, contrastando las tres tasas entre sí. Reproducible: `cd backend && php artisan test --filter=test_a_policy_whose_rates_are_not_monotonic_is_rejected`, que construye la tabla 60 / 36 / 42 y exige que la política la rechace; contra el código anterior al commit `29e7400` la construcción no lanzaba nada y la prueba falla. | Resuelto | Commit `29e7400` (T6): la progresión pasa a ser descendente (60 / 28.50 / 24 %) y el constructor de `CreditPolicy` impone el invariante microcrédito > personal > negocio, que rechaza con `InvalidArgumentException` cualquier tabla no monótona. La tasa personal queda anclada al prototipo P5. Probado en `CreditRulesEngineTest`: progresión descendente, anclaje a P5 y regresión exacta de la tabla anterior. | CWE-840 |
| VUL-16 | 2026-08-25 | Media | MS-01 / transversal (`backend/bootstrap/app.php`, normalización de errores de T10) | **Introducido por el asistente en T10, encontrado en la auditoría de la cabecera `Accept` que pidió el usuario.** La normalización de errores exentaba a **toda** la familia `HttpExceptionInterface` con el argumento de que su código de estado ya era el correcto. El estado sí; el **cuerpo no**: con `APP_DEBUG=true`, Laravel les añade la clase de la excepción, la **ruta absoluta del servidor** y la **traza completa**. Afectaba a 403, 404, 405 y a todo `abort()` — es decir, **incluidos los 403 de autorización por objeto** (RS-05.b, CWE-639) y los 404 que existen precisamente para no revelar si un recurso existe: las respuestas que recibe quien está sondeando autorización. Contradecía además lo que el propio bloque de T10 afirmaba de sí mismo («sin traza, clase, archivo ni línea, **ni siquiera con `APP_DEBUG` activo**: el modo de depuración no puede ser lo que separa una respuesta segura de una que no lo es»). Con `APP_DEBUG=false` no se manifiesta, que es por lo que la severidad es Media y no Alta. | Procedimiento, con el servidor levantado y `APP_DEBUG=true`: `curl -s -H "Accept: application/json" http://127.0.0.1:6060/api/v1/no-existe \| grep -c '"trace"'` → `1` contra el código anterior. Igual con `-X DELETE .../api/v1/prospects` (405) y con un 403 de `can:customer.view.any`. **La cabecera `Accept` no influye**: el escaneo comparó los diez tipos de respuesta con y sin ella y los códigos y tipos de contenido coincidieron en los diez; la fuga estaba en el cuerpo, en ambos casos. | Resuelto | Commit `T9b/T10: VUL-15 y VUL-16`. `HttpExceptionInterface` sale de la lista de exentas y pasa a normalizarse: **conserva el código de estado** —convertirlo en 500 sería mentir sobre lo que pasó— y **pierde el cuerpo**, que se sustituye por `message` genérico de una tabla por estado, `error_code` (`HTTP_403`, `HTTP_404`, …) y `correlation_id`. El mensaje sale de esa tabla y no de la excepción, para que el texto no dependa de la higiene del mensaje de una librería que no controlamos. Regresión fijada por `CorrelatedErrorResponseTest::a_framework_http_error_keeps_its_status_and_loses_its_body`, que **comprueba antes que `APP_DEBUG` está activo** —si no, pasaría por el motivo equivocado— y exige estado conservado, cuerpo de exactamente tres campos y ausencia de `trace`, `Exception`, `/opt/` y `vendor/laravel`. Verificado en vivo: 403, 404, 405 y 401 salen limpios y con su estado intacto. | CWE-209 · A05:2021 |
| VUL-15 | 2026-08-25 | Media | MS-02 Prospect / MS-03 Identity (`backend/app/Domain/Prospect/Prospect.php:365`, `backend/app/Application/UseCase/Identity/UploadIdentityDocument.php`) | **Introducido por el asistente en el commit `922a3de` (T9b), reportado por el usuario en el recorrido visual.** En P3, subir la identificación devolvía **500** cuando el prospecto venía de la rama manual. `Prospect::TRANSITIONS` declara `'data_confirmed' => [Abandoned]`, así que `markDocumentUploaded()` lanzaba `InvalidStateTransitionException: Transicion no permitida de data_confirmed a document_uploaded`. **Causa raíz completa:** `922a3de` retiró el guard del router que bloqueaba P3 en la rama manual y añadió en P4 el botón «subir mi identificación», habilitando el recorrido P2 → confirmar → P4 → **P3**; ese orden el dominio nunca lo permitió, y el commit **no lo ejercitó** —el recorrido de extremo a extremo que se hizo entonces fue P1 → P3 → P2 → P4, el orden de la rama OCR, que es el único que el dominio aceptaba—. **Lo grave no era el 500.** `UploadedDocumentStore` escribe el archivo en el controlador y el caso de uso persistía la fila **antes** de la transición que reventaba: quedaban un archivo en disco y una fila en `identity_documents` con `ocr_status=pending` y `ocr_job_id=NULL` que **nunca se encolaba**, y sobre todo **sin evento `document.uploaded` en la bitácora** — se almacenaba un documento de identidad y la bitácora no lo registraba (RF-13). Ninguna de las tres hipótesis iniciales (worker de BullMQ, Redis, permisos de `storage/app/private`) intervenía: `OCR_DRIVER` vale `simulated` por defecto, así que la cola no estaba en el camino, y el archivo se escribía correctamente. | `correlation_id` **`01M0X6A5QXGM0T3G6JQCC5DEYJ`** y `01M0X6ASB73EF1B3NNNSACN68H` en `storage/logs/laravel.log`. Reproducible con el servidor levantado: abrir expediente (`POST /api/v1/prospects` con `capture_method: manual`), `PATCH /api/v1/prospects/me` con datos completos, `POST /api/v1/prospects/me/confirm` (deja el expediente en `data_confirmed`) y después `POST /api/v1/identity-documents` con un archivo válido → **HTTP 500**. Comando exacto del último paso, con el token de la sesión ya obtenida: `curl -s -w "%{http_code}" -H "Accept: application/json" -H "Authorization: Bearer $TOK" -F "document=@ine.png;type=image/png" -F "document_type=INE" http://127.0.0.1:6060/api/v1/identity-documents` → **500**, y contra el código corregido → **202**. Efectos colaterales medidos en la reproducción: filas en `identity_documents` 4 → 5 y archivos en `storage/app` 2 → 3, con `SELECT event_type FROM audit_logs` mostrando `prospect.started → prospect.data_captured → prospect.data_confirmed` y **ningún** `document.uploaded`. La regresión queda además en la suite: `cd backend && php artisan test --filter=the_manual_route_runs_end_to_end_over_http`, que contra el código anterior falla con `Expected response status code [202] but received 500`. | Resuelto | Commit `T9b/T10: VUL-15 y VUL-16`. **(1)** `markDocumentUploaded()` es un **no-op explícito y documentado** cuando el estado ya es `data_confirmed`: `capture_status` mide el progreso de la *captura*, y en la rama manual el documento no captura nada —es la **evidencia** que P4 necesita—, así que moverlo a `document_uploaded` sería un retroceso. Permitir la transición en vez del no-op habría cambiado el 500 por un bloqueo silencioso: `hasConfirmedData()` compara con `DataConfirmed` exactamente, y P4 habría pasado a responder 422 `PROSPECT_DATA_NOT_CONFIRMED`. **(2) Atomicidad:** el caso de uso se reordena en tres tramos —lo que puede fallar sin escribir nada; documento, prospecto y evento de bitácora dentro de **una sola transacción** (puerto `TransactionManager` nuevo, con adaptador `EloquentTransactionManager`); y el encolado **fuera**, porque sostener una transacción durante la latencia de una red es peor—. Un fallo ya no puede dejar fila sin evento. **(3)** Artefactos de la reproducción limpiados: 3 filas huérfanas y 3 archivos borrados, identificados por las tres condiciones del defecto (`ocr_status=pending` + `ocr_job_id IS NULL` + sin evento en bitácora); `audit_logs` no se tocó y `php artisan audit:verify-chain` termina en 0. | CWE-754 · A04:2021 |
| VUL-14 | 2026-08-25 | Media | MS-01 Identity & Access (`backend/bootstrap/app.php`, middleware `Authenticate`) | **Detectado al levantar la aplicación para el recorrido de extremo a extremo de T9b.** Una petición sin token a cualquier endpoint autenticado respondía **500 con traza completa** en vez de 401, siempre que la petición no llevara `Accept: application/json`. El middleware `Authenticate` construye el destino de la redirección del invitado **antes** de lanzar la excepción y **siempre**, no solo cuando la petición espera HTML; en esta aplicación no existe ninguna ruta llamada `login` —la única ruta web es `/`, pública, y el acceso es por OAuth—, así que ese cálculo lanzaba `RouteNotFoundException`. Dos reglas incumplidas a la vez: la 9 (el endpoint no contesta 401 a quien no se ha autenticado) y la 8 (con `APP_DEBUG=true` el cuerpo lleva la traza, el mensaje de la excepción y rutas absolutas del servidor). **Por qué no se había visto:** todas las pruebas de 401 usan `getJson`/`postJson`, que ponen `Accept: application/json`, y con esa cabecera la excepción se salta el cálculo del destino. El defecto solo aparecía sin declarar JSON —un navegador abriendo la URL a mano—. | Manual y reproducible con el servidor levantado: `php artisan serve --host=127.0.0.1 --port=6060` y después `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:6060/api/v1/me` → `500`, frente a `curl -H "Accept: application/json" ...` → `401`. El cuerpo del 500 trae `"exception": "Symfony\\Component\\Routing\\Exception\\RouteNotFoundException"` y la traza. Confirmado además con `php artisan route:list \| grep -i login`: no hay ninguna ruta llamada `login`. | Resuelto | Commit `T9b: 401 para el invitado sin cabecera Accept`. `bootstrap/app.php` registra `$middleware->redirectGuestsTo(fn (): ?string => null)`: al no haber destino, el cálculo no se hace y la excepción llega intacta al manejador, que ya tenía `shouldRenderJsonWhen` para `api/*` y responde 401 en JSON. Regresión fijada por `ApiAccessControlTest::a_request_without_the_json_accept_header_still_answers_401`, que ejerce el endpoint **sin** `Accept: application/json` —deliberadamente con `get()` y no con `getJson()`— y exige 401, ausencia de redirección, cuerpo con `message` y sin `trace`. Verificado en vivo: `curl http://127.0.0.1:6060/api/v1/me` → `{"message":"Unauthenticated."}` con `HTTP 401`. | CWE-209 / OWASP A05 |
| VUL-13 | 2026-08-25 | Baja (`recommendation` en CodeQL) | Dependencia de desarrollo de terceros (`backend/vendor/filp/whoops/src/Whoops/Resources/js/whoops.base.js`) | Inserción automática de punto y coma en un recurso JavaScript de `filp/whoops`: el 92 % de las sentencias de la función que lo contiene sí lleva punto y coma explícito, y las dos líneas señaladas se apoyan en el ASI del intérprete. Es una advertencia de legibilidad y mantenimiento, no una vulnerabilidad: no hay entrada del usuario implicada ni ruta de ejecución que un atacante pueda alcanzar. Se registra porque salió del análisis y porque **la respuesta correcta era acotar el alcance, no editar el código**. | CodeQL (CLI 2.26.3) sobre `golfintech-vue-db`, base creada con `codeql database create golfintech-vue-db --language=javascript --source-root=.` sobre el commit `c1bbc9d`; resultados exportados en `resultados-vue.cs` (2 filas, ambas del mismo archivo). Reproducible: `grep filp/whoops resultados-vue.cs`. | Aceptado con justificación | **No se modifica el código de terceros:** el siguiente `composer install` deshace cualquier cambio, así que editarlo no es una remediación sino una regresión aplazada. Se corrige el **alcance del análisis**: `codeql-config.yml` nuevo en la raíz con `paths-ignore` sobre `backend/vendor`, `vendor`, `node_modules`, artefactos de compilación y la propia base de datos; se pasa con `--codescanning-config=codeql-config.yml`. **Verificado que whoops no se carga fuera de desarrollo:** aparece únicamente en `packages-dev` del `composer.lock` del backend —comprobado leyendo el lock, no la sección `packages`—, de modo que `composer install --no-dev` no lo instala; ningún archivo de `app/`, `config/` ni `bootstrap/` lo referencia (`grep -rn "Whoops" app/ config/ bootstrap/` sin resultados); y `config/app.php:42` resuelve `'debug' => (bool) env('APP_DEBUG', false)`, es decir, **por omisión falso**: en producción la página de error detallada no se muestra aunque el paquete estuviera presente. | CWE-1078 |
| VUL-12 | 2026-08-25 | Baja | MS-05 Credit Engine (`backend/app/Domain/Credit/CreditPolicy.php:71-79`) | Los tres accesos por índice del recorrido que verifica la monotonía de tasas —`$ordered[$i]` y `$ordered[$i - 1]`— se hacían sobre un literal que el analizador infiere como tupla de exactamente tres posiciones (`int<0, 2>`), mientras que el índice del bucle es `int<1, max>`. El analizador no puede probar que el índice permanece en rango. **Sin impacto de seguridad:** el recorrido es sobre un literal de tres elementos escrito dos líneas más arriba y no depende de ninguna entrada, así que el desbordamiento no puede ocurrir. Se registra porque está en el invariante que sostiene VUL-09 —la monotonía de las tasas— y un aviso silenciado ahí es un aviso que nadie mira el día que el literal cambie. | `./vendor/bin/psalm --no-cache` (Psalm 6.16, `psalm.xml` en la raíz, `errorLevel="5"`), salida en `psalm.txt`: tres `InvalidArrayOffset` (psalm.dev/115) en `CreditPolicy.php:71:44`, `:72:43` y `:79:21`. Reproducible: `./vendor/bin/psalm --no-cache 2>&1 \| grep InvalidArrayOffset`. | Resuelto | Commit `T12 (parcial): correcciones del analisis estatico`. Se acota el tipo del índice declarando `@var list<CreditType> $ordered` en vez de dejar que se infiera la tupla, y se extrae `count()` a una variable; los dos elementos comparados pasan a locales (`$betterProfile`, `$worseProfile`), lo que además elimina dos de los cinco accesos por índice. El comportamiento no cambia y `CreditRulesEngineTest` sigue verde. Comprobado: `./vendor/bin/psalm --no-cache 2>&1 \| grep -c InvalidArrayOffset` devuelve `0`. | CWE-129 |
| VUL-11 | 2026-08-25 | Baja | MS-01 Identity & Access (`backend/app/Infrastructure/Security/TotpAuthenticator.php:172`) | `encodeBase32()` indexaba `BASE32_ALPHABET` con el resultado directo de `bindec()`, cuyo tipo declarado es `float\|int` —devuelve flotante cuando el número no cabe en un entero—, y un índice flotante no es un índice válido de cadena. **No es explotable:** el fragmento que se convierte es de 5 bits, así que el valor máximo es 31 y nunca desborda. Se registra igualmente porque está en el camino de **generación de secretos TOTP** (segundo factor, T5): en código criptográfico, un tipo que solo es correcto por el rango del dato es una garantía que no está escrita en ninguna parte. | `./vendor/bin/psalm --no-cache` (Psalm 6.16, `psalm.xml` en la raíz, `errorLevel="5"`), salida en `psalm.txt`: `InvalidArrayOffset` (psalm.dev/115) en `TotpAuthenticator.php:172:25` — «Cannot access value on variable ...BASE32_ALPHABET using a float\|int offset, expecting int». Reproducible: `./vendor/bin/psalm --no-cache 2>&1 \| grep TotpAuthenticator.php:172`. | Resuelto | Commit `T12 (parcial): correcciones del analisis estatico`. Conversión explícita a entero antes del acceso (`$index = (int) bindec(...)`), con el comentario que explica por qué el rango no basta como garantía. El comportamiento no cambia —los vectores de RFC 6238 de `TotpAuthenticatorTest` siguen en verde— y la propiedad queda fijada en el código en vez de en el rango del dato. Comprobado: `./vendor/bin/psalm --no-cache 2>&1 \| grep -c InvalidArrayOffset` devuelve `0`. | CWE-704 |
| VUL-10 | 2026-08-21 | Baja | MS-01 Identity & Access (`backend/app/Http/Controllers/Auth/LoginController.php`) | **Introducido por el asistente en T5, corregido antes del commit.** La clave del contador de intentos de acceso se derivaba con `sha1(correo + IP)`. La regla de seguridad no negociable 6 prohibe SHA-1 **para cualquier proposito de seguridad**, sin excepcion por uso "poco importante", y limitar los intentos de autenticacion es un control de seguridad (RS-10). El impacto directo es bajo —una colision de SHA-1 aqui solo haria que dos cuentas compartieran contador—, pero la infraccion es real: una excepcion tolerada es exactamente lo que mantiene vivo un algoritmo prohibido. **Nota del 2026-08-22:** la regla 6 se afino ese dia para distinguir SHA-1 como funcion de hash (prohibido) de HMAC-SHA-1 (aceptable, CLAUDE.md 6.3). **El hallazgo se mantiene tal cual bajo la redaccion nueva**: `sha1()` es hash pelado, no HMAC, y derivar un identificador depende justo de la resistencia a colisiones que SHA-1 ya no tiene. Lo que cambia es la cita: donde decia "para cualquier proposito de seguridad", ahora aplica "como funcion de hash ... derivacion de claves o identificadores". | `bash scripts/verificar_avance.sh`, higiene transversal verificacion #2: `[FALTA] 2 uso(s) de MD5/SHA-1 (prohibidos por CLAUDE.md seccion 6.6)`, señalando `LoginController.php:50`. Reproducible con el grep del propio script: `grep -rnEi "\bsha1\(" backend/app`. | Resuelto | Commit `T5`: la derivacion pasa a `hash('sha256', ...)`. En la misma revision se retiro el otro uso de SHA-1 del arbol —`TotpAuthenticatorTest` probaba que el algoritmo TOTP es configurable con los vectores SHA-1 de RFC 6238—, sustituido por los vectores SHA-512 de la misma norma, que acreditan lo mismo sin escribir SHA-1 en el repositorio. La verificacion #2 del script vuelve a `[OK]`. | CWE-327 |

## Análisis ejecutados

Registro de las ejecuciones de análisis sobre el repositorio, con o sin hallazgos. Una
ejecución sin hallazgos también es evidencia, y es lo que permite afirmar que la tabla de
arriba está corta porque no hay más, no porque no se haya buscado.

| Fecha | Comando | Resultado |
|---|---|---|
| 2026-08-21 | `cd backend && composer audit` | `No security vulnerability advisories found.` Sin hallazgos. |
| 2026-08-21 | `cd frontend && npm audit` | `found 0 vulnerabilities`. Sin hallazgos. |
| 2026-08-21 | `cd worker && npm audit` | `found 0 vulnerabilities`. Sin hallazgos. |
| 2026-08-21 | `bash scripts/verificar_avance.sh` — higiene transversal (9 verificaciones en negativo: MD5/SHA-1, CURP/RFC/PAN en logs, `.env` versionado, llaves privadas, permisos de `backend/.env`, SQL concatenado, secretos en el código, dependencia de `Illuminate` en `Domain`, convención de idioma) | Las 9 en `[OK]`. Sin hallazgos. |
| 2026-08-21 | `bash scripts/verificar_avance.sh` — higiene transversal, tras escribir el código de T5 | **Con hallazgo:** verificación #2 en `[FALTA]` por dos usos de SHA-1 (→ VUL-10) y verificación #9 en `[FALTA]` por dos descripciones en español en la firma de `user:create`. Ambas corregidas dentro de T5; las 9 vuelven a `[OK]`. Es la primera vez que la higiene transversal detecta algo, y lo detectó sobre código recién escrito por el asistente. |
| 2026-08-21 | `cd backend && composer audit` (tras instalar `laravel/passport` ^13.7 y sus 12 dependencias) | `No security vulnerability advisories found.` Sin hallazgos. |
| 2026-08-24 | `./vendor/bin/psalm --no-cache` (Psalm 6.16 sobre PHP, `psalm.xml` en la raíz, `errorLevel="5"`), salida completa en `psalm.txt` | **Con hallazgos: 2 de seguridad, ninguno explotable** (→ VUL-11, VUL-12). Véase el desglose de abajo. |
| 2026-08-24 | `codeql database create golfintech-vue-db --language=javascript --source-root=.` + consultas de seguridad de JavaScript (CodeQL CLI 2.26.3, commit `c1bbc9d`), resultados en `resultados-vue.cs` | **Un hallazgo, de severidad `recommendation` y en código de terceros** (→ VUL-13). Ninguno en el código propio de `frontend/` ni de `worker/`. |
| 2026-08-25 | `./vendor/bin/psalm --no-cache`, tras corregir VUL-11 y VUL-12 y ampliar `psalm.xml` | `InvalidArrayOffset`: **0**. Las dos correcciones quedan acreditadas por la ausencia del error que las detectó. |
| 2026-08-25 | Recorrido visual de las siete pantallas en el navegador, hecho por el usuario | **Con hallazgo:** 500 en P3 al subir la identificación desde la rama manual (→ VUL-15). Es el primer defecto que encuentra el uso real de la aplicación y no una herramienta: ninguna de las 443 pruebas, ni Psalm, ni CodeQL, ni el script de auditoría lo habían visto. |
| 2026-08-25 | Escaneo de los diez tipos de respuesta de la API con y sin `Accept: application/json` (raíz de VUL-14) | **Sin hallazgo sobre la hipótesis** —ningún control depende de la cabecera— pero **con un hallazgo distinto**: los cuerpos de 403, 404, 405 y `abort()` filtraban traza y rutas absolutas con `APP_DEBUG=true` (→ VUL-16). Desglose en la sección de abajo. |

### Desglose de las 947 incidencias de la primera pasada

Es la cifra que hay que poder explicar, porque un número grande sin desglose sugiere un
código lleno de problemas y aquí no es el caso. **Ninguna de las 947 es una vulnerabilidad
de seguridad**; las tres que se abrieron arriba lo son de tipado y de terceros, no de
explotabilidad. El reparto:

| Origen | Cuántas | Qué son y por qué no son hallazgos |
|---|---|---|
| Ruido de configuración | 499 | Psalm corrió **sin el plugin de Laravel** (`psalm/plugin-laravel`), así que no resuelve las fachadas, los helpers globales ni los métodos mágicos de Eloquent. Son avisos sobre código que el framework sí define. |
| Código muerto aparente | 332 | `PossiblyUnusedMethod` / `UnusedClass` sobre constructores de casos de uso, adaptadores y clases de prueba. Se instancian por **inyección de dependencias del contenedor** y por PHPUnit, ni una ni otra visible para un analizador estático que solo mira llamadas explícitas. |
| Estilo | 87 | `UnusedParam`, orden de declaraciones y similares. No tocan comportamiento. |
| **Hallazgos reales** | **3** | VUL-11 y VUL-12 (Psalm, corregidos) y VUL-13 (CodeQL, de terceros, alcance acotado). |

Las dos primeras categorías son **artefactos del cómo se ejecutó el análisis**, no
propiedades del código, y por eso no abren entrada: `BUGS.md` perdería su valor si se
llenara de avisos que desaparecen instalando un plugin. Lo que sí queda registrado es el
alcance del análisis, para que la próxima ejecución sea comparable con esta.

**Alcance del análisis, fijado en configuración.** Se excluye `vendor/` en los dos
analizadores —el código de terceros no se corrige editándolo (VUL-13)— y
`backend/storage/framework/views`, que son **vistas Blade compiladas**: artefactos de
ejecución que se regeneran solos, no están versionados (`.gitignore`) y no tienen origen
que corregir. Queda en `psalm.xml` (`<ignoreFiles>`) y en `codeql-config.yml`
(`paths-ignore`), no en la línea de comandos, para que el alcance viaje con el repositorio
y no dependa de cómo lo invoque cada quien.

## Por qué 443 pruebas no vieron VUL-15

Es la parte que importa más que el defecto, porque es la que dice si el resto de la
batería acredita lo que parece acreditar. **La hipótesis inicial —que las pruebas usan un
doble del puerto `OcrService` y nunca ejercitan BullMQ— era razonable y resultó no ser la
causa**: el fallo ocurría antes de tocar la cola, y en este entorno `OCR_DRIVER` vale
`simulated` también fuera de las pruebas, así que el camino real y el probado usaban el
mismo adaptador.

Las razones reales son dos, y conviene tenerlas separadas porque se corrigen distinto:

| # | Hueco | Evidencia |
|---|---|---|
| 1 | **`DocumentUploadTest` solo fabrica prospectos en `capture_status => 'started'`.** Ese es el orden de la rama OCR —documento primero, datos después—. El estado `data_confirmed`, por el que pasa la rama manual, no lo ejercitaba ninguna prueba del archivo. | `grep -n "capture_status" tests/Feature/Identity/DocumentUploadTest.php` → una sola línea, `'started'`. |
| 2 | **`ProspectJourneyTest` no toca HTTP:** invoca los casos de uso directamente. Cubre bien el encadenamiento del dominio y **por construcción no puede ver** un defecto que depende del orden en que las pantallas llaman a la API. | `grep -c "postJson\|getJson\|withHeader" tests/Feature/ProspectJourneyTest.php` → `0`. |

Entre las dos quedaba un hueco con la forma exacta del defecto: **el recorrido completo, en
el orden real, por la superficie real**. No existía ninguna prueba de extremo a extremo
sobre HTTP.

**Lo que se añadió:** `tests/Feature/Journey/FullFlowOverHttpTest.php`, con **las dos
ramas** del diseño recorridas enteras sobre HTTP (P1 → … → P6, incluida la transición de
token de P6). Se escriben las dos y no solo la que fallaba, porque el defecto consistió
precisamente en arreglar una rama y romper la otra sin ejercerla.

**Comprobado que la prueba nueva detecta el defecto**, revirtiendo la corrección: el
recorrido manual falla con `Expected response status code [202] but received 500` y el de
OCR sigue pasando. Esa asimetría es la demostración de que el hueco era exactamente el
descrito, y no una prueba escrita para pasar.

Se añadieron además, en `DocumentUploadTest`, los tres casos que faltaban en el nivel HTTP:
carga con el prospecto en `data_confirmed`, la garantía de que un documento almacenado
**siempre** deja su evento en la bitácora, y la atomicidad —haciendo fallar la escritura en
la bitácora y exigiendo que no quede fila huérfana—. Esta última corre contra MySQL a
propósito: con dobles en memoria no hay rollback que ejercer, así que una prueba unitaria
puede comprobar el orden de las escrituras pero **nunca** su atomicidad.

## Auditoría de la cabecera `Accept` (raíz de VUL-14)

Encargada tras VUL-14, donde once pruebas de 401 pasaban porque `getJson()` fija
`Accept: application/json` y esa cabecera evitaba la rama que fallaba.

**Método:** empírico y no por lectura. Se ejercitaron los diez tipos de respuesta de la API
contra el servidor real, cada uno **con y sin** la cabecera, comparando código de estado,
tipo de contenido y cuerpo.

| Resultado | Detalle |
|---|---|
| **Ningún control depende hoy de la cabecera** | Los diez tipos —401 sin token, 401 con token inválido, 403, 404, 405, 422 de FormRequest, 422 de dominio, 429, 200 autenticado y 200 de la raíz web— devuelven **el mismo código y el mismo tipo de contenido** con y sin `Accept: application/json`. Lo sostiene `shouldRenderJsonWhen`, cuya condición `$request->is('api/*')` no mira la cabecera, más la corrección de VUL-14 (`redirectGuestsTo(null)`). |
| **Pero el escaneo encontró otra cosa** | Los cuerpos de 403, 404, 405 y `abort()` filtraban traza y rutas absolutas del servidor, **con y sin la cabecera**. Es VUL-16, registrado arriba. |

La lección que queda anotada: **la auditoría encontró un defecto real, pero no el que
buscaba.** Comparar dos variantes de la misma petición obliga a mirar la respuesta entera,
y mirarla entera es lo que destapó una fuga que no tenía nada que ver con la hipótesis de
partida.

**Pendiente de T12, declarado:** instalar `psalm/plugin-laravel` retiraría de golpe las 499
de la primera categoría y dejaría el análisis en un estado en el que un aviso nuevo se
note. Mientras no esté, la cifra bruta de Psalm no sirve como métrica de tendencia y no
debe citarse como tal.
