# SECURITY_CHECKLIST.md — Controles preventivos de seguridad

Este archivo **no** registra vulnerabilidades encontradas en este repositorio. Recoge los
controles de seguridad que el diseño de las Fases 1 a 3 exige implementar, cada uno con la
tarea que lo implementa y lo que comprueba que quedó implementado.

**Origen de VUL-01 a VUL-08.** Los ocho identificadores provienen del reporte SAST
redactado en la Fase 3 (§3.4), donde se presentan como escenario ilustrativo del tipo de
defectos que este stack suele producir. **No salieron de analizar este código: nadie los
encontró aquí.** Son controles preventivos válidos y por eso se conservan; llamarlos
"vulnerabilidades encontradas" sería inexacto, y por eso no están en `BUGS.md`.

**Los identificadores se conservan tal cual** porque están citados en los documentos
entregados de las tres fases y la trazabilidad con ellos debe mantenerse.

Estado: Pendiente / En progreso / Implementado.

## Reglas

1. **Ninguna entrada se borra.** Un control implementado se marca como Implementado en su
   sitio, con la tarea y el commit que lo implementó.
2. **Un control no se mueve a `BUGS.md` al implementarse.** Solo pasa a `BUGS.md` si más
   adelante se descubre que quedó mal implementado y eso constituye un hallazgo real y
   reproducible; en ese caso se abre una entrada nueva allí que cita este ID, y el control
   se queda aquí igualmente.
3. Un control se declara Implementado únicamente cuando existe el commit que lo implementa
   **y** la verificación de la última columna lo acredita.

## Controles

| ID | Control | Origen (fase de diseño) | Tarea que lo implementa (T#) | Estado | CWE / OWASP | Verificación |
|---|---|---|---|---|---|---|
| VUL-01 | Validar el tipo real del archivo cargado por inspección de contenido (finfo / magic bytes), renombrarlo con identificador generado y almacenarlo fuera de la raíz web, en lugar de confiar en la extensión declarada por el cliente. | Fase 3 §3.4 | T4 (adaptador de `DocumentRepository`) y T7 (worker de OCR) | Pendiente | CWE-434 · A04:2021 | `verificar_avance.sh` §T4 «Puerto DocumentRepository: interfaz + real + falso + enlace». **El script no cubre hoy el tipo de archivo**: se acredita además con una prueba Feature que suba un ejecutable renombrado a `.jpg` y exija su rechazo. |
| VUL-02 | El catálogo de plazos autorizados vive en el dominio y se valida en el servidor, venga el parámetro de donde venga. | Fase 3 §3.4 | T3 (dominio) y T10 (endpoint) | En progreso — `Domain/Credit/Term::AUTHORIZED_MONTHS` rechaza todo valor fuera del catálogo desde T3 (commit `9e9a8d7`); falta el endpoint que lo consuma | CWE-20 · A03:2021 | `verificar_avance.sh` §T6 «php artisan test --testsuite=Unit en verde», que cubre `TermTest` (7 valores no autorizados). El cierre exige §T10 «N FormRequest(s): validación replicada en el servidor». |
| VUL-03 | Token de acceso en cookie con `HttpOnly`, `Secure` y `SameSite`; vigencia corta y rotación del refresh token. Nunca en `localStorage`, donde queda accesible ante un XSS. | Fase 3 §3.4 | T5 | Pendiente | CWE-522 · A07:2021 | `verificar_avance.sh` §T5 «Hay prueba que exige 401 sin token» y «Hay implementacion de PKCE (code_challenge)». **El script no comprueba hoy el almacenamiento del token**: se acredita además con `grep -rn "localStorage" frontend/src` sin resultados sobre el token. |
| VUL-04 | La bitácora de auditoría nunca conserva CURP, RFC ni PAN en claro: el enmascarado se aplica en el constructor del evento, de modo que no exista forma de construir uno que conserve el dato. | Fase 3 §3.4 | T3 (enmascarado) y T8 (bitácora completa) | En progreso — `Domain/Audit/SensitiveDataMasker` aplicado en el constructor de `AuditEvent` desde T3 (commit `9e9a8d7`); falta la bitácora completa | CWE-532 · A09:2021 | `verificar_avance.sh` higiene transversal #3 «Ningun registro de log incluye CURP, RFC ni numero de tarjeta» y §T8 «Hay prueba que altera la bitacora y exige que se detecte». |
| VUL-05 | Respuestas de error genéricas con identificador de correlación; el detalle técnico solo en los registros del servidor, nunca en la respuesta al cliente. | Fase 3 §3.4 | T10 | Pendiente | CWE-209 · A05:2021 | `verificar_avance.sh` §T10 «Errores normalizados a mensaje generico con identificador de correlacion» y «Los controladores no devuelven getMessage()/traza al cliente». |
| VUL-06 | Auditoría de dependencias incorporada a la rutina de entrega: `composer audit` y `npm audit` se ejecutan y sus hallazgos se registran y se corrigen actualizando versiones. | Fase 3 §3.4 | T12 | Pendiente — los comandos ya se ejecutan desde la auditoría y hoy no reportan nada (ver `BUGS.md`, análisis ejecutados); falta fijarlos como rutina | CWE-1395 | `verificar_avance.sh` §T12 «composer audit: sin vulnerabilidades declaradas» y «npm audit (frontend/ y worker/): sin vulnerabilidades». |
| VUL-07 | Límite de intentos por identificador y por dirección IP en la consulta de estatus de solicitud. | Fase 3 §3.4 | T11 | Pendiente | CWE-307 · A04:2021 | `verificar_avance.sh` §T11 «Rate limiting definido (RateLimiter::for / throttle:)». |
| VUL-08 | Cabeceras `Strict-Transport-Security`, `Content-Security-Policy`, `X-Content-Type-Options` y `Referrer-Policy` en todas las respuestas, por middleware de la aplicación. | Fase 3 §3.4 | T11 | Pendiente | CWE-1021 · A05:2021 | `verificar_avance.sh` §T11 «El middleware define las 4 cabeceras», «Middleware registrado en backend/bootstrap/app.php» y «curl -I devuelve las 4 cabeceras». |

## Numeración

La serie `VUL-xx` es **continua entre este archivo y `BUGS.md`** y ningún número se
reutiliza: VUL-01 a VUL-08 son controles preventivos y viven aquí; VUL-09 es un hallazgo
verificado sobre este código y vive en `BUGS.md`. El archivo en el que está una entrada
indica su naturaleza; el número indica su identidad.
