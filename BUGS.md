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

**Pendiente de T12, declarado:** instalar `psalm/plugin-laravel` retiraría de golpe las 499
de la primera categoría y dejaría el análisis en un estado en el que un aviso nuevo se
note. Mientras no esté, la cifra bruta de Psalm no sirve como métrica de tendencia y no
debe citarse como tal.
