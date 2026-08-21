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

**Análisis estático pendiente:** el backend no tiene todavía una herramienta SAST en
`require-dev` (`laravel/pint` es formateador, no analizador). Instalarla y ejecutarla es
parte de T12; hasta entonces no puede afirmarse que el código haya pasado un análisis
estático.
