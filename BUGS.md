# BUGS.md — Registro de vulnerabilidades y defectos de seguridad

Severidad: Crítica / Alta / Media / Baja.
Estado: Abierto / En progreso / Resuelto / Aceptado con justificación.
**Las entradas resueltas nunca se borran.** Se registra aquí cualquier defecto de
seguridad encontrado, incluidos los introducidos y corregidos por el propio asistente.

## Registro

| ID | Fecha | Severidad | Componente | Descripción | Estado | Remediación | Referencia (CWE / OWASP) |
|---|---|---|---|---|---|---|---|
| VUL-01 | 2026-08-20 | Crítica | MS-03 Document (`backend` carga de documentos) | La validación del archivo cargado se apoya en la extensión declarada por el cliente, lo que permitiría subir un ejecutable renombrado. Heredado del reporte SAST de la Fase 3 §3.4; **abierto porque el componente aún no está implementado**. | Abierto | Verificar el tipo real por inspección de contenido (finfo/magic bytes), renombrar con identificador generado y almacenar fuera de la raíz web. Se atenderá al implementar la carga de documentos (T4/T7). | CWE-434 · A04:2021 |
| VUL-02 | 2026-08-20 | Alta | MS-05 Credit Engine (`Domain/Credit`) | El parámetro de plazo enviado desde el cliente se emplearía sin validar contra el catálogo autorizado, permitiendo condiciones fuera de política. Heredado de Fase 3 §3.4. | En progreso | **T3:** el catálogo vive en el dominio (`Domain/Credit/Term::AUTHORIZED_MONTHS`) y `Term::fromMonths()` rechaza cualquier valor fuera de él; el caso de uso `SimulateCredit` convierte ahí el plazo recibido. Probado en `TermTest` (7 valores no autorizados) y extremo a extremo en `ProspectJourneyTest`. **Queda abierto** hasta que exista el endpoint que lo consuma (T10): sin API todavía no puede demostrarse el control sobre una petición real. | CWE-20 · A03:2021 |
| VUL-03 | 2026-08-20 | Alta | MS-01 Identity & Access (`frontend` + `backend` auth) | El token de acceso almacenado en `localStorage` queda accesible desde JavaScript ante un XSS. Heredado de Fase 3 §3.4; **abierto porque la autenticación aún no está implementada**. | Abierto | Usar cookie con atributos `HttpOnly`, `Secure` y `SameSite`; vigencia corta del token de acceso y rotación del refresh token. Se atenderá en T5. | CWE-522 · A07:2021 |
| VUL-04 | 2026-08-20 | Media | MS-07 Audit Log (`Domain/Audit`) | El registro de auditoría podría incluir la CURP completa en el detalle del evento. Heredado de Fase 3 §3.4. | En progreso | **T3:** `Domain/Audit/SensitiveDataMasker` enmascara por nombre de clave y por forma del valor (CURP, RFC, secuencias con forma de PAN), y `AuditEvent` lo aplica **en el constructor**, de modo que no existe forma de construir un evento que conserve el dato en claro. Probado en `SensitiveDataMaskerTest` y en `EloquentAuditLoggerTest`. **Queda abierto** hasta T8, cuando la bitácora esté completa y se verifique sobre todos los eventos del flujo. | CWE-532 · A09:2021 |
| VUL-05 | 2026-08-20 | Media | API (transversal) | Los mensajes de error de validación podrían devolver el nombre de la columna de base de datos involucrada. Heredado de Fase 3 §3.4; **abierto porque la API aún no está implementada**. | Abierto | Normalizar las respuestas de error a un formato genérico con identificador de correlación y registrar el detalle técnico solo del lado del servidor. Se atenderá en T10. | CWE-209 · A05:2021 |
| VUL-06 | 2026-08-20 | Media | Dependencias (`worker/`) | Posible biblioteca de tratamiento de imágenes con vulnerabilidad publicada en la versión utilizada por el worker. Heredado de Fase 3 §3.4; **abierto: pendiente de verificación real con `npm audit`**. | Abierto | Ejecutar `npm audit` y `composer audit`, actualizar a versiones corregidas y fijar la revisión de dependencias en la compilación programada. Se atenderá en T12. | CWE-1395 |
| VUL-07 | 2026-08-20 | Baja | MS-02 Prospect (`backend` API) | Ausencia de límite de intentos en el endpoint de consulta de estatus de solicitud. Heredado de Fase 3 §3.4; **abierto porque el endpoint aún no existe**. | Abierto | Aplicar limitación de peticiones por identificador y por dirección IP. Se atenderá en T11. | CWE-307 · A04:2021 |
| VUL-08 | 2026-08-20 | Baja | Front end / servidor web | Cabeceras `Content-Security-Policy`, `X-Content-Type-Options` y `Referrer-Policy` ausentes en las respuestas. Heredado de Fase 3 §3.4; **abierto porque el servidor aún no está configurado**. | Abierto | Incorporar HSTS, CSP, `X-Content-Type-Options` y `Referrer-Policy` mediante middleware de la aplicación. Se atenderá en T11. | CWE-1021 · A05:2021 |

## Nota sobre el origen de VUL-01 a VUL-08

Estos ocho identificadores provienen del reporte SAST documentado en la Fase 3 (§3.4),
donde figuran como "Resueltos" o "En progreso". En este repositorio se registran como
**Abiertos** porque el código correspondiente todavía no existe: declararlos resueltos
sería reportar trabajo inexistente. Cada entrada se moverá a "Resuelto" únicamente cuando
haya un commit que implemente el control y una verificación que lo compruebe, citando el
hash del commit en la columna de remediación.
