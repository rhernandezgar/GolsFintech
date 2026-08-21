#!/usr/bin/env bash
# scripts/verificar_avance.sh — Auditoria objetiva del avance real de GolsFintech.
#
# El criterio de cada seccion sale del PLAN T1-T12 acordado con el usuario, NO del
# codigo que exista hoy. Que una tarea todavia no empezada salga [FALTA] es el
# resultado correcto y esperado: este script mide contra el plan, no contra el
# trabajo hecho.
#
# REGLA PERMANENTE (ver CLAUDE.md, seccion 9): una vez commiteado, este script no se
# modifica sin permiso explicito del usuario. Si marca [FALTA] en algo que si existe,
# se reporta que comando falla y por que; el usuario decide si se ajusta.
#
# Uso:  bash scripts/verificar_avance.sh              (desde cualquier directorio)
#       bash scripts/verificar_avance.sh /ruta/proyecto
#
# A proposito NO se usa `set -e` ni `set -o pipefail`: un fallo individual no debe
# abortar la auditoria. Ademas `cmd | head` muere por SIGPIPE (141) bajo pipefail,
# que fue justo el defecto que abortaba en silencio a scripts/setup-database.sh.

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"
ROOT="${1:-$(cd "$SELF_DIR/.." 2>/dev/null && pwd)}"
cd "$ROOT" 2>/dev/null || { echo "No existe el directorio $ROOT"; exit 1; }

# ---------------------------------------------------------------- presentacion --
if [ -t 1 ]; then
  C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
else
  C_G=''; C_R=''; C_Y=''; C_B=''; C_0=''
fi

TOT_OK=0; TOT_NO=0; TOT_WARN=0
SEC_OK=0; SEC_NO=0; SEC_WARN=0
TASK_ID=''
SUMMARY=''

ok()   { printf "  ${C_G}[OK]${C_0}      %s\n" "$1"; SEC_OK=$((SEC_OK+1));     TOT_OK=$((TOT_OK+1)); }
no()   { printf "  ${C_R}[FALTA]${C_0}   %s\n" "$1"; SEC_NO=$((SEC_NO+1));     TOT_NO=$((TOT_NO+1)); }
warn() { printf "  ${C_Y}[REVISAR]${C_0} %s\n" "$1"; SEC_WARN=$((SEC_WARN+1)); TOT_WARN=$((TOT_WARN+1)); }
info() { printf "            %s\n" "$1"; }
hdr()  { printf "\n${C_B}== %s ==${C_0}\n" "$1"; }

task() {
  TASK_ID="$1"
  SEC_OK=0; SEC_NO=0; SEC_WARN=0
  printf "\n${C_B}== %s — %s ==${C_0}\n" "$1" "$2"
  printf "   ${C_B}criterio:${C_0} %s\n" "$3"
}

endtask() {
  local v
  if   [ "$SEC_NO" -gt 0 ];   then v="${C_R}FALTA${C_0}"
  elif [ "$SEC_WARN" -gt 0 ]; then v="${C_Y}REVISAR${C_0}"
  else                             v="${C_G}OK${C_0}"
  fi
  printf "  ${C_B}» %s: %b${C_0}  (%d ok / %d falta / %d revisar)\n" \
         "$TASK_ID" "$v" "$SEC_OK" "$SEC_NO" "$SEC_WARN"
  SUMMARY="${SUMMARY}${TASK_ID}|${SEC_OK}|${SEC_NO}|${SEC_WARN}
"
}

# ---------------------------------------------------------------- utilidades ----
# Codigo fuente propio: excluye dependencias y artefactos.
SRC_DIRS=""
for d in backend/app backend/routes backend/config backend/database backend/tests \
         frontend/src worker/src scripts; do
  [ -d "$d" ] && SRC_DIRS="$SRC_DIRS $d"
done

# Cuenta archivos .php reales (ignora .gitkeep) bajo un directorio.
php_files() { find "$1" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' '; }

# Ejecuta artisan en backend/ y devuelve salida + codigo.
artisan() { ( cd "$ROOT/backend" 2>/dev/null && php artisan "$@" ) 2>&1; }

HAS_PHP=0;  command -v php  >/dev/null 2>&1 && HAS_PHP=1
HAS_NODE=0; command -v node >/dev/null 2>&1 && HAS_NODE=1
HAS_NPM=0;  command -v npm  >/dev/null 2>&1 && HAS_NPM=1
HAS_COMPOSER=0; command -v composer >/dev/null 2>&1 && HAS_COMPOSER=1
HAS_CURL=0; command -v curl >/dev/null 2>&1 && HAS_CURL=1

printf "${C_B}Auditoria de GolsFintech${C_0} — %s\n" "$(date '+%Y-%m-%d %H:%M:%S')"
printf "Proyecto: %s\n" "$ROOT"
printf "Criterio: plan T1-T12. Las tareas no iniciadas deben salir [FALTA].\n"

# ================================================================ 0. contexto ===
hdr "0. Contexto del repositorio"
if [ -d .git ]; then
  info "Rama            : $(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
  info "Ultimo commit   : $(git log -1 --format='%h %ad %s' --date=short 2>/dev/null)"
  info "Commits totales : $(git rev-list --count HEAD 2>/dev/null)"
  DIRTY=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
  if [ "$DIRTY" -gt 0 ]; then
    warn "$DIRTY archivo(s) sin commitear: hay trabajo que no cuenta como terminado"
  else
    ok "Arbol de trabajo limpio"
  fi
  info "Commits por tarea:"
  for t in T1 T2 T3 T4 T5 T6 T7 T8 T9 T10 T11 T12; do
    c=$(git log --oneline --all 2>/dev/null | grep -cE "(^|[^A-Za-z0-9])$t[^0-9]" )
    printf "              %-4s %s commit(s)\n" "$t" "${c:-0}"
  done
else
  no "Este directorio no es un repositorio Git"
fi
for f in CLAUDE.md PROGRESS.md BUGS.md README.md; do
  [ -f "$f" ] && info "$f: $(wc -l < "$f" | tr -d ' ') lineas" || no "$f no existe"
done

# ====================================================================== T1 ======
task "T1" "Scaffold" \
     "curl -I http://127.0.0.1:6060 responde; Laravel 13 en backend/, Vue 3 en frontend/, worker en worker/"

if [ -f backend/artisan ]; then
  if [ "$HAS_PHP" = "1" ]; then
    LARAVEL_V="$(artisan --version)"
    case "$LARAVEL_V" in
      *"Laravel Framework 13."*) ok "backend/ es Laravel 13 ($LARAVEL_V)" ;;
      *"Laravel Framework"*)     no "backend/ no es Laravel 13: $LARAVEL_V" ;;
      *)                         no "php artisan --version no responde: $LARAVEL_V" ;;
    esac
  else
    warn "php no esta en el PATH: no se puede comprobar la version de Laravel"
  fi
else
  no "No existe backend/artisan (Laravel no instalado)"
fi

if grep -qE '"vue"[[:space:]]*:[[:space:]]*"[\^~]?3' frontend/package.json 2>/dev/null; then
  ok "frontend/ declara Vue 3"
else
  no "frontend/package.json no declara Vue 3"
fi
if grep -q '"bullmq"' worker/package.json 2>/dev/null; then
  ok "worker/ declara BullMQ"
else
  no "worker/package.json no declara BullMQ"
fi
[ -f worker/src/index.js ] && ok "worker/src/index.js existe" \
                           || no "Falta el punto de entrada worker/src/index.js"

if [ -f .gitignore ]; then
  MISSING=""
  for pat in '.env' 'node_modules' 'vendor'; do
    grep -q -- "$pat" .gitignore || MISSING="$MISSING $pat"
  done
  [ -z "$MISSING" ] && ok ".gitignore cubre .env, node_modules y vendor" \
                    || no ".gitignore no cubre:$MISSING"
else
  no "No existe .gitignore en la raiz"
fi

if grep -qE 'APP_URL=.*:6060|:6060' backend/.env 2>/dev/null; then
  ok "backend/.env apunta al puerto 6060"
else
  warn "backend/.env no menciona el puerto 6060 (o no existe el archivo)"
fi

if [ "$HAS_CURL" = "1" ]; then
  HTTP_HEAD="$(curl -sS -I -m 4 http://127.0.0.1:6060 2>&1)"
  HTTP_CODE="$(printf '%s' "$HTTP_HEAD" | head -1 | awk '{print $2}')"
  case "$HTTP_CODE" in
    2*|3*) ok "curl -I http://127.0.0.1:6060 -> HTTP $HTTP_CODE" ;;
    "")    warn "127.0.0.1:6060 no responde. Levanta la API: (cd backend && php artisan serve --host=127.0.0.1 --port=6060)" ;;
    *)     no  "curl -I http://127.0.0.1:6060 -> HTTP $HTTP_CODE" ;;
  esac
else
  warn "curl no disponible: no se puede comprobar el puerto 6060"
fi
endtask

# ====================================================================== T2 ======
task "T2" "Migraciones de las 9 tablas" \
     "php artisan migrate:status lista las 9 tablas; audit_logs es polimorfica"

TABLES="prospects identity_documents identity_validations credit_applications credit_simulations customers credit_lines cards audit_logs"
MIGDIR=backend/database/migrations
FALTAN=""
for t in $TABLES; do
  grep -rqlE "create\(['\"]$t['\"]" "$MIGDIR" 2>/dev/null || FALTAN="$FALTAN $t"
done
[ -z "$FALTAN" ] && ok "Las 9 migraciones existen con los nombres exactos de la seccion 3" \
                 || no "Sin migracion para:$FALTAN"

if [ "$HAS_PHP" = "1" ]; then
  MSTATUS="$(artisan migrate:status)"
  if printf '%s' "$MSTATUS" | grep -qiE 'SQLSTATE|could not find driver|Access denied'; then
    no "php artisan migrate:status falla: $(printf '%s' "$MSTATUS" | grep -oE 'SQLSTATE\[[^]]*\][^\"]*' | head -1)"
  else
    NORUN=""
    for t in $TABLES; do
      printf '%s' "$MSTATUS" | grep -E "create_${t}_table" | grep -q "Ran" || NORUN="$NORUN $t"
    done
    [ -z "$NORUN" ] && ok "migrate:status: las 9 migraciones en estado Ran" \
                    || no "migrate:status no las reporta ejecutadas:$NORUN"
  fi
else
  warn "php no disponible: no se pudo ejecutar migrate:status"
fi

AUDITMIG="$(grep -rlE "create\(['\"]audit_logs['\"]" "$MIGDIR" 2>/dev/null | head -1)"
if [ -n "$AUDITMIG" ]; then
  MISSCOL=""
  for c in prospect_id affected_entity affected_entity_id event_type actor ip_address event_at previous_hash current_hash; do
    grep -q "$c" "$AUDITMIG" || MISSCOL="$MISSCOL $c"
  done
  [ -z "$MISSCOL" ] && ok "audit_logs tiene las columnas del patron polimorfico + encadenamiento" \
                    || no "audit_logs sin columnas:$MISSCOL"
  # Negativo: no debe haber una FK por entidad dentro de audit_logs.
  # prospect_id es el ancla legitima del diseno (seccion 5 de CLAUDE.md). Lo que no
  # debe existir es una llave foranea por cada entidad afectada.
  FK=$(grep -nE "foreignId\(|foreign\(|constrained\(" "$AUDITMIG" 2>/dev/null | grep -vc "prospect_id")
  ENT=$(grep -cE "(customer|credit_application|credit_simulation|credit_line|card|identity_document|identity_validation)_id" "$AUDITMIG")
  if [ "$FK" = "0" ] && [ "$ENT" = "0" ]; then
    ok "audit_logs no usa llave foranea por entidad (referencia polimorfica)"
  else
    no "audit_logs: $FK FK ajena(s) al ancla prospect_id y $ENT columna(s) por entidad: rompe el patron polimorfico"
  fi
else
  no "No se encontro la migracion de audit_logs"
fi

MISSCOL=""
for c in full_name monthly_income document_type ocr_result ine_status renapo_status \
         credit_type application_status proposed_amount estimated_monthly_payment \
         customer_number authorized_amount line_status tokenized_card_number card_status; do
  grep -rqs "$c" "$MIGDIR" || MISSCOL="$MISSCOL $c"
done
[ -z "$MISSCOL" ] && ok "Las columnas clave usan los nombres acordados" \
                  || no "Columnas clave ausentes en las migraciones:$MISSCOL"

# Negativo: el PAN completo no debe tener columna.
PAN=$(grep -rnE "card_number|pan\b" "$MIGDIR" 2>/dev/null | grep -v tokenized_card_number | wc -l | tr -d ' ')
[ "$PAN" = "0" ] && ok "Ninguna migracion define columna para el PAN completo" \
                 || no "$PAN posible(s) columna(s) de PAN sin tokenizar en las migraciones"
endtask

# ====================================================================== T3 ======
task "T3" "Capas Domain / Application / Infrastructure" \
     "grep -rl 'use Illuminate' backend/app/Domain no devuelve nada (con dominio ya implementado)"

for d in backend/app/Domain backend/app/Application backend/app/Infrastructure; do
  [ -d "$d" ] && ok "Existe $d" || no "No existe $d"
done
for d in Prospect Credit Identity Audit Port; do
  [ -d "backend/app/Domain/$d" ] && ok "Existe backend/app/Domain/$d" \
                                 || no "Falta backend/app/Domain/$d"
done

NDOM=$(php_files backend/app/Domain)
NAPP=$(php_files backend/app/Application)
NINF=$(php_files backend/app/Infrastructure)
info "Clases: Domain=$NDOM  Application=$NAPP  Infrastructure=$NINF"
[ "$NDOM" -gt 0 ] && ok "Domain tiene $NDOM clase(s)" \
                  || no "Domain vacio: no hay entidades ni objetos de valor implementados"
[ "$NAPP" -gt 0 ] && ok "Application tiene $NAPP caso(s) de uso" \
                  || no "Application vacio: no hay casos de uso implementados"
[ "$NINF" -gt 0 ] && ok "Infrastructure tiene $NINF adaptador(es)" \
                  || no "Infrastructure vacio: no hay adaptadores implementados"

# Negativo. Solo vale como OK si el dominio ya tiene codigo: un directorio vacio
# tambien "no importa Illuminate", y eso no acredita la tarea.
LEAK=$(grep -rlE "use (Illuminate|App\\\\Models|App\\\\Http)" backend/app/Domain --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
if [ "$NDOM" -eq 0 ]; then
  no "Regla de dependencia no acreditable: backend/app/Domain no tiene codigo todavia"
elif [ "$LEAK" = "0" ]; then
  ok "Domain no importa Illuminate, App\\Models ni App\\Http"
else
  no "$LEAK archivo(s) de Domain importan Illuminate/App\\Models: capa contaminada"
  grep -rlE "use (Illuminate|App\\\\Models|App\\\\Http)" backend/app/Domain --include='*.php' 2>/dev/null | sed 's/^/            /'
fi
# Negativo inverso: Domain tampoco debe importar Application ni Infrastructure.
INV=$(grep -rlE "use App\\\\(Application|Infrastructure)" backend/app/Domain --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
[ "$INV" = "0" ] && ok "Domain no importa Application ni Infrastructure" \
                 || no "$INV archivo(s) de Domain importan capas externas"
endtask

# ====================================================================== T4 ======
task "T4" "Los 7 puertos: interfaz + adaptador real + adaptador falso + enlaces" \
     "php artisan tinker resuelve cada interfaz desde el contenedor"

PORTS="ProspectRepository DocumentRepository OcrService IdentityValidator CardIssuer AuditLogger NotificationSender"
TINKER_OK=0
[ "$HAS_PHP" = "1" ] && artisan list 2>/dev/null | grep -q "tinker" && TINKER_OK=1
[ "$TINKER_OK" = "0" ] && warn "php artisan tinker no disponible: la resolucion en el contenedor no se comprueba"

for p in $PORTS; do
  IFACE="$(grep -rlE "interface[[:space:]]+$p\b" backend/app/Domain/Port --include='*.php' 2>/dev/null | head -1)"
  if [ -z "$IFACE" ]; then
    no "Puerto $p: sin interfaz en backend/app/Domain/Port/"
    continue
  fi
  DETAIL="interfaz"
  REAL=$(grep -rlE "implements[^{]*\b$p\b" backend/app/Infrastructure --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
  FAKE=$(grep -rlE "implements[^{]*\b$p\b" backend/app backend/tests --include='*.php' 2>/dev/null \
         | grep -cE "/(Fake|InMemory|Null|Stub)[A-Za-z]*\.php$")
  BIND=$(grep -rl "$p" backend/app/Providers --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
  RESOLVED="-"
  if [ "$TINKER_OK" = "1" ]; then
    NS="$(grep -m1 '^namespace' "$IFACE" | sed 's/namespace[[:space:]]*//; s/;.*//')"
    OUT="$(artisan tinker --execute="app('${NS}\\\\${p}'); echo 'RESOLVED';")"
    printf '%s' "$OUT" | grep -q RESOLVED && RESOLVED="si" || RESOLVED="no"
  fi
  if [ "$REAL" -gt 0 ] && [ "$FAKE" -gt 0 ] && [ "$BIND" -gt 0 ] && [ "$RESOLVED" != "no" ]; then
    ok "Puerto $p: interfaz + real + falso + enlace (tinker: $RESOLVED)"
  else
    no "Puerto $p: $DETAIL, real=$REAL falso=$FAKE enlace=$BIND tinker=$RESOLVED"
  fi
done
endtask

# ====================================================================== T5 ======
task "T5" "Autenticacion OAuth2 + PKCE + 2FA y RBAC de 5 roles" \
     "Prueba de integracion: 401 sin token, 403 con rol insuficiente"

if grep -qE '"(laravel/passport|league/oauth2-server|laravel/sanctum)"' backend/composer.json 2>/dev/null; then
  grep -qE '"(laravel/passport|league/oauth2-server)"' backend/composer.json \
    && ok "Servidor OAuth2 declarado en backend/composer.json" \
    || warn "Solo hay Sanctum: el plan pide OAuth2 + PKCE"
else
  no "backend/composer.json no declara un servidor OAuth2"
fi

grep -rqiE "pkce|code_challenge" backend/app backend/config backend/routes frontend/src 2>/dev/null \
  && ok "Hay implementacion de PKCE (code_challenge)" \
  || no "Sin rastro de PKCE (code_challenge/code_verifier)"

grep -rqiE "two.?factor|totp|otp_secret|2fa" backend/app backend/database/migrations 2>/dev/null \
  && ok "Hay implementacion de 2FA" \
  || no "Sin implementacion de 2FA"

ROLES_FALTAN=""
for r in prospect customer admin auditor risk_analyst; do
  grep -rqsE "['\"]$r['\"]" backend/app backend/database backend/config 2>/dev/null || ROLES_FALTAN="$ROLES_FALTAN $r"
done
[ -z "$ROLES_FALTAN" ] && ok "Los 5 roles RBAC aparecen en el codigo" \
                       || no "Roles RBAC ausentes:$ROLES_FALTAN"

grep -rqE "assertStatus\(401\)|assertUnauthorized" backend/tests 2>/dev/null \
  && ok "Hay prueba que exige 401 sin token" || no "Sin prueba de 401 sin token"
grep -rqE "assertStatus\(403\)|assertForbidden" backend/tests 2>/dev/null \
  && ok "Hay prueba que exige 403 con rol insuficiente" || no "Sin prueba de 403 con rol insuficiente"

if [ "$HAS_PHP" = "1" ] && grep -rqlE "assertStatus\(40[13]\)|assertForbidden|assertUnauthorized" backend/tests 2>/dev/null; then
  OUT="$(artisan test --filter='Auth|Rbac|Role|Permission')"
  printf '%s' "$OUT" | grep -qiE "FAIL|Errors" && no "php artisan test (auth/RBAC) con fallos" \
                                               || ok "php artisan test (auth/RBAC) en verde"
  printf '%s\n' "$OUT" | tail -3 | sed 's/^/            /'
fi
endtask

# ====================================================================== T6 ======
task "T6" "Motor de reglas de credito en Domain/Credit con pruebas unitarias sin BD" \
     "php artisan test --testsuite=Unit en verde"

NCREDIT=$(php_files backend/app/Domain/Credit)
[ "$NCREDIT" -gt 0 ] && ok "backend/app/Domain/Credit tiene $NCREDIT clase(s)" \
                     || no "backend/app/Domain/Credit sin implementar"
grep -rqiE "credit_type|CreditType|PaymentCapacity|capacidad de pago" backend/app/Domain/Credit 2>/dev/null \
  && ok "El motor decide tipo de credito / capacidad de pago" \
  || no "Sin logica de tipo de credito ni capacidad de pago"

NUT=$(grep -rli -E "credit|simulation|capacity" backend/tests/Unit 2>/dev/null | wc -l | tr -d ' ')
[ "$NUT" -gt 0 ] && ok "$NUT archivo(s) de prueba unitaria del motor" \
                 || no "Sin pruebas unitarias del motor de reglas"

# Negativo: la suite Unit no debe tocar la base de datos. Solo acredita la tarea si ya
# hay pruebas del motor: una suite vacia tampoco toca la base y no probaria nada.
DBUNIT=$(grep -rlE "RefreshDatabase|DatabaseMigrations|DatabaseTransactions|DB::" backend/tests/Unit 2>/dev/null | wc -l | tr -d ' ')
if [ "$NUT" -eq 0 ]; then
  no "Sin pruebas del motor en backend/tests/Unit: la suite no acredita T6"
elif [ "$DBUNIT" = "0" ]; then
  ok "La suite Unit no depende de la base de datos"
else
  no "$DBUNIT prueba(s) unitaria(s) usan la base de datos"
fi

if [ "$HAS_PHP" = "1" ] && [ "$NUT" -gt 0 ]; then
  OUT="$(artisan test --testsuite=Unit)"
  if printf '%s' "$OUT" | grep -qiE "FAIL|Errors|No tests executed"; then
    no "php artisan test --testsuite=Unit no esta en verde"
  else
    ok "php artisan test --testsuite=Unit en verde"
  fi
  printf '%s\n' "$OUT" | tail -3 | sed 's/^/            /'
elif [ "$HAS_PHP" != "1" ]; then
  warn "php no disponible: no se ejecuto la suite Unit"
fi
endtask

# ====================================================================== T7 ======
task "T7" "Worker Node 24 + BullMQ: OCR asincrono con reintentos; API responde 202" \
     "node -v = v24; el job se encola, se procesa y reintenta"

if [ "$HAS_NODE" = "1" ]; then
  NV="$(node -v)"
  case "$NV" in
    v24*) ok "node -v = $NV" ;;
    *)    no "node -v = $NV (el plan exige Node.js 24 LTS)" ;;
  esac
else
  no "node no esta en el PATH"
fi

for d in queues processors adapters; do
  n=$(find "worker/src/$d" -type f -name '*.js' 2>/dev/null | wc -l | tr -d ' ')
  [ "$n" -gt 0 ] && ok "worker/src/$d con $n archivo(s)" || no "worker/src/$d vacio"
done
# Se busca solo en queues/ y processors/: una mencion en un comentario del scaffold
# no es una cola implementada.
grep -rqi "ocr" worker/src/queues worker/src/processors 2>/dev/null \
  && ok "Hay cola/procesador de OCR" || no "Sin cola ni procesador de OCR"
grep -rqE "attempts|backoff" worker/src/queues worker/src/processors 2>/dev/null \
  && ok "Politica de reintentos definida (attempts/backoff)" || no "Sin politica de reintentos"
grep -rqi "notification" worker/src/queues worker/src/processors 2>/dev/null \
  && ok "Hay cola de notificaciones" || no "Sin cola de notificaciones"
grep -rqE "(,|=>)[[:space:]]*202\b|HTTP_ACCEPTED|Response::HTTP_ACCEPTED" backend/app 2>/dev/null \
  && ok "La API responde 202 Accepted al encolar" \
  || no "Ningun endpoint responde 202 Accepted"
grep -rqE "assertStatus\(202\)|assertAccepted" backend/tests 2>/dev/null \
  && ok "Hay prueba que exige 202 al encolar" || no "Sin prueba del 202 Accepted"

# Negativo: el worker no debe abrir endpoints HTTP (solo consume cola).
NWJS=$(find worker/src -type f -name '*.js' 2>/dev/null | wc -l | tr -d ' ')
SRV=$(grep -rlE "express|createServer\(|fastify|\.listen\(" worker/src 2>/dev/null | wc -l | tr -d ' ')
if [ "$NWJS" -le 1 ]; then
  info "El worker sigue siendo el scaffold de T1: la regla \"sin endpoints HTTP\" no se acredita"
elif [ "$SRV" = "0" ]; then
  ok "El worker no expone endpoints HTTP (solo consume cola)"
else
  no "$SRV archivo(s) del worker levantan un servidor HTTP"
fi
endtask

# ====================================================================== T8 ======
task "T8" "AuditLog append-only, encadenamiento SHA-256, audit:verify-chain, Argon2id" \
     "php artisan audit:verify-chain detecta manipulacion"

NAUD=$(php_files backend/app/Domain/Audit)
[ "$NAUD" -gt 0 ] && ok "backend/app/Domain/Audit con $NAUD clase(s)" \
                  || no "backend/app/Domain/Audit sin implementar"
grep -rqiE "hash\(['\"]sha256|sha256" backend/app 2>/dev/null \
  && ok "Encadenamiento con SHA-256 presente en el codigo" \
  || no "Sin SHA-256 en el codigo de la bitacora"
grep -rq "previous_hash" backend/app 2>/dev/null \
  && ok "El codigo usa previous_hash para encadenar" || no "El codigo no usa previous_hash"

CMD_OK=0
if [ "$HAS_PHP" = "1" ]; then
  artisan list 2>/dev/null | grep -q "audit:verify-chain" && CMD_OK=1
fi
if [ "$CMD_OK" = "1" ]; then
  ok "El comando audit:verify-chain esta registrado"
  OUT="$(artisan audit:verify-chain)"
  RC=$?
  [ "$RC" = "0" ] && ok "audit:verify-chain termina en 0 (cadena integra)" \
                  || warn "audit:verify-chain devuelve $RC: $(printf '%s\n' "$OUT" | tail -1)"
else
  no "El comando audit:verify-chain no existe"
fi
grep -rqiE "verify.?chain|VerifyChain" backend/tests 2>/dev/null \
  && ok "Hay prueba que altera la bitacora y exige que se detecte" \
  || no "Sin prueba que compruebe la deteccion de manipulacion"

# Negativo: append-only, ni UPDATE ni DELETE sobre la bitacora.
NAUDCODE=$(grep -rlE "audit_logs|AuditLog" backend/app --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
MUT=$(grep -rnE "audit_logs|AuditLog" backend/app --include='*.php' 2>/dev/null \
      | grep -c -E -e "->update\(" -e "->delete\(" -e "->forceDelete\(" -e "truncate\(")
if [ "$NAUDCODE" = "0" ]; then
  info "Sin codigo de bitacora todavia: la regla append-only no se puede acreditar"
elif [ "$MUT" = "0" ]; then
  ok "Ningun UPDATE/DELETE sobre la bitacora en el codigo"
else
  no "$MUT operacion(es) de modificacion sobre la bitacora: rompe append-only"
fi

if grep -rqE "argon2id" backend/config backend/.env backend/.env.example 2>/dev/null; then
  ok "Argon2id configurado para credenciales"
else
  no "Argon2id no configurado (HASH_DRIVER / config/hashing.php)"
fi
endtask

# ====================================================================== T9 ======
task "T9" "Las 7 vistas de Vue, navegables de extremo a extremo" \
     "Las 7 rutas responden en el navegador"

VIEWS="WelcomeView ProspectDataFormView DocumentUploadView VerificationResultView CreditSimulationView AuthorizationConfirmedView CustomerLookupView"
VFALTAN=""
for v in $VIEWS; do
  [ -f "frontend/src/views/$v.vue" ] || VFALTAN="$VFALTAN $v"
done
[ -z "$VFALTAN" ] && ok "Las 7 vistas existen en frontend/src/views/" \
                  || no "Vistas ausentes en frontend/src/views/:$VFALTAN"

ROUTER="$(find frontend/src/router -type f \( -name '*.js' -o -name '*.ts' \) 2>/dev/null | head -1)"
if [ -n "$ROUTER" ]; then
  ok "Router en $ROUTER"
  RFALTAN=""
  for v in $VIEWS; do
    grep -q "$v" "$ROUTER" || RFALTAN="$RFALTAN $v"
  done
  [ -z "$RFALTAN" ] && ok "Las 7 vistas estan registradas como rutas" \
                    || no "Vistas sin ruta registrada:$RFALTAN"
  NR=$(grep -cE "path:[[:space:]]*['\"]" "$ROUTER")
  [ "$NR" -ge 7 ] && ok "$NR rutas declaradas (>= 7)" || no "Solo $NR ruta(s) declarada(s)"
else
  no "No hay router en frontend/src/router/"
fi

if [ "$HAS_NPM" = "1" ] && [ -d frontend/node_modules ] && [ -z "$VFALTAN" ]; then
  OUT="$( cd frontend && npm run build 2>&1 )"
  printf '%s' "$OUT" | grep -qiE "error|failed" && no "npm run build falla en frontend/" \
                                                || ok "npm run build compila el frontend"
elif [ -n "$VFALTAN" ]; then
  info "No se compila el frontend: faltan vistas"
else
  warn "No se pudo compilar frontend/ (npm o node_modules ausentes)"
fi
info "La navegacion extremo a extremo en el navegador se comprueba a mano: las 7 rutas."
endtask

# ====================================================================== T10 =====
task "T10" "Enmascaramiento, mensajes genericos, validacion en servidor, expiracion de sesion" \
     "La respuesta de la API nunca trae la CURP completa"

grep -rqiE "mask|enmascar" backend/app 2>/dev/null \
  && ok "Hay logica de enmascaramiento en backend/app" \
  || no "Sin enmascaramiento de CURP/RFC/PAN"

NREQ=$(find backend/app/Http/Requests -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
[ "$NREQ" -gt 0 ] && ok "$NREQ FormRequest(s): validacion replicada en el servidor" \
                  || no "Sin FormRequest en backend/app/Http/Requests: la validacion no se replica"

NHTTP=$(php_files backend/app/Http)

# El scaffold de Laravel ya trae shouldRenderJsonWhen: eso no es normalizar errores.
# T10 exige mensaje generico + identificador de correlacion para el detalle tecnico.
if grep -qE "renderable\(|->render\(" backend/bootstrap/app.php 2>/dev/null \
   && grep -rqiE "correlation|error_id|reference_id|request_id" backend/bootstrap/app.php backend/app 2>/dev/null; then
  ok "Errores normalizados a mensaje generico con identificador de correlacion"
else
  no "Sin normalizacion de errores genericos con identificador de correlacion"
fi

if ! grep -rqE "SESSION_LIFETIME|'lifetime'" backend/.env backend/config/session.php 2>/dev/null; then
  no "Sin expiracion de sesion configurada"
elif [ "$NHTTP" -le 1 ]; then
  warn "Solo esta el valor por defecto de Laravel: sin sesion propia no acredita el control"
else
  ok "Expiracion de sesion configurada"
fi

# Negativos de T10. Sobre una capa HTTP vacia pasarian solos y no probarian nada.
if [ "$NHTTP" -le 1 ]; then
  no "Capa HTTP sin implementar ($NHTTP archivo): los controles de T10 no se acreditan"
else
  RAW=$(grep -rnE "['\"](curp|rfc|tokenized_card_number)['\"][[:space:]]*=>" backend/app/Http 2>/dev/null \
        | grep -viE "mask|hash|last_four" | wc -l | tr -d ' ')
  [ "$RAW" = "0" ] && ok "Ningun recurso HTTP expone CURP/RFC/PAN sin enmascarar" \
                   || no "$RAW campo(s) exponen CURP/RFC/PAN en claro en backend/app/Http"

  LEAKMSG=$(grep -rnE "getMessage\(\)|getTraceAsString\(\)" backend/app/Http 2>/dev/null | wc -l | tr -d ' ')
  [ "$LEAKMSG" = "0" ] && ok "Los controladores no devuelven getMessage()/traza al cliente" \
                       || warn "$LEAKMSG uso(s) de getMessage()/getTraceAsString() en backend/app/Http: revisar que no lleguen al cliente"
fi

grep -rqiE "curp" backend/tests 2>/dev/null \
  && ok "Hay prueba sobre la CURP en las respuestas de la API" \
  || no "Sin prueba que exija que la API no devuelva la CURP completa"

endtask

# ====================================================================== T11 =====
task "T11" "Cabeceras de seguridad y rate limiting" \
     "curl -I muestra HSTS, CSP, X-Content-Type-Options y Referrer-Policy"

MW="$(grep -rl "Content-Security-Policy" backend/app/Http/Middleware --include='*.php' 2>/dev/null | head -1)"
if [ -n "$MW" ]; then
  ok "Middleware de cabeceras: $MW"
  HFALTAN=""
  for h in Strict-Transport-Security Content-Security-Policy X-Content-Type-Options Referrer-Policy; do
    grep -q "$h" "$MW" || HFALTAN="$HFALTAN $h"
  done
  [ -z "$HFALTAN" ] && ok "El middleware define las 4 cabeceras" || no "Cabeceras sin definir:$HFALTAN"
  MWCLASS="$(basename "$MW" .php)"
  grep -q "$MWCLASS" backend/bootstrap/app.php 2>/dev/null \
    && ok "$MWCLASS registrado en backend/bootstrap/app.php" \
    || no "$MWCLASS no esta registrado en backend/bootstrap/app.php"
else
  no "Sin middleware de cabeceras de seguridad en backend/app/Http/Middleware"
fi

grep -rqE "RateLimiter::for|throttle:" backend/app backend/routes 2>/dev/null \
  && ok "Rate limiting definido" || no "Sin rate limiting (RateLimiter::for / throttle:)"

if [ "$HAS_CURL" = "1" ]; then
  H="$(curl -sS -I -m 4 http://127.0.0.1:6060 2>/dev/null)"
  if [ -z "$H" ]; then
    warn "127.0.0.1:6060 no responde: las cabeceras no se comprueban en vivo"
  else
    HFALTAN=""
    for h in strict-transport-security content-security-policy x-content-type-options referrer-policy; do
      printf '%s' "$H" | tr 'A-Z' 'a-z' | grep -q "^$h:" || HFALTAN="$HFALTAN $h"
    done
    [ -z "$HFALTAN" ] && ok "curl -I devuelve las 4 cabeceras" \
                      || no "curl -I no devuelve:$HFALTAN"
  fi
fi
endtask

# ====================================================================== T12 =====
task "T12" "Analisis de seguridad: composer audit, npm audit, detector de secretos" \
     "Los tres comandos corren y sus hallazgos estan en BUGS.md"

HOOK=""
[ -f .githooks/pre-commit ] && HOOK=.githooks/pre-commit
[ -z "$HOOK" ] && [ -f .git/hooks/pre-commit ] && HOOK=.git/hooks/pre-commit
if [ -n "$HOOK" ]; then
  [ -x "$HOOK" ] && ok "Hook pre-commit ejecutable: $HOOK" || no "Hook $HOOK sin permiso de ejecucion"
  grep -qiE "gitleaks|trufflehog|detect-secrets|PRIVATE KEY|AKIA|secret|\.env" "$HOOK" 2>/dev/null \
    && ok "El hook pre-commit detecta secretos" || no "El hook pre-commit no detecta secretos"
  if [ "$HOOK" = ".githooks/pre-commit" ]; then
    [ "$(git config --get core.hooksPath)" = ".githooks" ] \
      && ok "core.hooksPath apunta a .githooks" || no "core.hooksPath no apunta a .githooks: el hook no corre"
  fi
else
  no "No existe hook pre-commit con detector de secretos"
fi

if [ "$HAS_COMPOSER" = "1" ] && [ -f backend/composer.json ]; then
  info "--- composer audit (backend/) ---"
  OUT="$( cd backend && composer audit --no-interaction 2>&1 )"
  RC=$?
  printf '%s\n' "$OUT" | tail -15 | sed 's/^/            /'
  if [ "$RC" = "0" ]; then ok "composer audit: sin vulnerabilidades declaradas"
  else no "composer audit reporta hallazgos (codigo $RC): registralos en BUGS.md"; fi
else
  warn "composer no disponible o falta backend/composer.json"
fi

for d in frontend worker; do
  if [ "$HAS_NPM" = "1" ] && [ -f "$d/package.json" ]; then
    info "--- npm audit ($d/) ---"
    if [ ! -f "$d/package-lock.json" ]; then
      warn "$d/package-lock.json no existe: npm audit no es concluyente"
      continue
    fi
    OUT="$( cd "$d" && npm audit 2>&1 )"
    RC=$?
    printf '%s\n' "$OUT" | tail -12 | sed 's/^/            /'
    if [ "$RC" = "0" ]; then ok "npm audit ($d): sin vulnerabilidades"
    else no "npm audit ($d) reporta hallazgos: registralos en BUGS.md"; fi
  else
    warn "npm no disponible o falta $d/package.json"
  fi
done

if [ -f BUGS.md ]; then
  NBUG=$(grep -cE "^\| VUL-" BUGS.md)
  NOPEN=$(grep -E "^\| VUL-" BUGS.md | grep -c "Abierto")
  ok "BUGS.md con $NBUG entrada(s); $NOPEN abierta(s)"
  [ "$NOPEN" -gt 0 ] && warn "$NOPEN vulnerabilidad(es) siguen Abiertas en BUGS.md"
else
  no "No existe BUGS.md"
fi
endtask

# =========================================================== higiene (negativo) =
hdr "Higiene transversal (verificaciones en negativo)"

# 1. Domain sin Illuminate (repetido aqui porque es regla permanente, no solo de T3).
if [ -d backend/app/Domain ]; then
  N=$(grep -rl "use Illuminate" backend/app/Domain --include='*.php' 2>/dev/null | wc -l | tr -d ' ')
  if [ "$(php_files backend/app/Domain)" -eq 0 ]; then
    warn "backend/app/Domain aun sin codigo: la regla de dependencia no se puede acreditar"
  elif [ "$N" = "0" ]; then
    ok "grep -rl 'use Illuminate' backend/app/Domain no devuelve nada"
  else
    no "$N archivo(s) de Domain importan Illuminate"
  fi
else
  no "No existe backend/app/Domain"
fi

# 2. Prohibidos MD5 y SHA-1.
BAD=$(grep -rnEi "\bmd5\(|\bsha1\(|['\"]md5['\"]|['\"]sha1['\"]|createHash\(['\"](md5|sha1)['\"]\)" \
      $SRC_DIRS 2>/dev/null | grep -vE "^\s*(//|#|\*)" | wc -l | tr -d ' ')
if [ "$BAD" = "0" ]; then
  ok "Sin uso de MD5 ni SHA-1 en el codigo propio"
else
  no "$BAD uso(s) de MD5/SHA-1 (prohibidos por CLAUDE.md seccion 6.6)"
  grep -rnEi "\bmd5\(|\bsha1\(|createHash\(['\"](md5|sha1)['\"]\)" $SRC_DIRS 2>/dev/null | head -10 | sed 's/^/            /'
fi

# 3. CURP, RFC y PAN nunca en logs ni en la bitacora.
LOGLEAK=$(grep -rnEi "(Log::[a-z]+|logger\(|error_log|console\.(log|info|warn|error)|logger\.[a-z]+)\(.*(curp|rfc|card_number|\bpan\b)" \
          $SRC_DIRS 2>/dev/null | grep -viE "curp_hash|rfc_hash|tokenized_card_number|last_four|mask" | wc -l | tr -d ' ')
if [ "$LOGLEAK" = "0" ]; then
  ok "Ningun registro de log incluye CURP, RFC ni numero de tarjeta"
else
  no "$LOGLEAK posible(s) fuga(s) de CURP/RFC/PAN a logs"
  grep -rnEi "(Log::[a-z]+|logger\(|console\.(log|info|warn|error))\(.*(curp|rfc|card_number|\bpan\b)" \
    $SRC_DIRS 2>/dev/null | head -10 | sed 's/^/            /'
fi

# 4. Ningun .env versionado.
if [ -d .git ]; then
  TRACKED=$(git ls-files | grep -E "(^|/)\.env($|\.)" | grep -v "\.env\.example" | wc -l | tr -d ' ')
  if [ "$TRACKED" = "0" ]; then
    ok "Ningun archivo .env esta versionado"
  else
    no "$TRACKED archivo(s) .env versionados en Git:"
    git ls-files | grep -E "(^|/)\.env($|\.)" | grep -v "\.env\.example" | sed 's/^/            /'
  fi
  SECRETS=$(git ls-files | grep -cE "\.(pem|key|p12|pfx)$|(^|/)auth\.json$")
  [ "$SECRETS" = "0" ] && ok "Ninguna llave privada ni auth.json versionado" \
                       || no "$SECRETS archivo(s) de credenciales versionados"
fi

# 5. Permisos de backend/.env.
if [ -f backend/.env ]; then
  PERM=$(stat -c '%a' backend/.env 2>/dev/null)
  [ "$PERM" = "600" ] && ok "backend/.env con permisos 600" \
                      || no "backend/.env con permisos $PERM (deben ser 600): chmod 600 backend/.env"
else
  no "No existe backend/.env"
fi

# 6. Sin SQL concatenado.
SQLCAT=$(grep -rnE "DB::(raw|statement|select|unprepared)\(.*(\\\$|\.\s*\\\$)" backend/app 2>/dev/null | wc -l | tr -d ' ')
[ "$SQLCAT" = "0" ] && ok "Sin SQL concatenado con variables (DB::raw/statement)" \
                    || no "$SQLCAT consulta(s) con SQL concatenado: usar sentencias preparadas"

# 7. Sin secretos escritos en el codigo.
HARD=$(grep -rnEi "(password|secret|api_key|token)[[:space:]]*=[[:space:]]*['\"][A-Za-z0-9/_+=-]{12,}['\"]" \
       $SRC_DIRS 2>/dev/null | grep -viE "env\(|process\.env|example|placeholder|fake|test" | wc -l | tr -d ' ')
[ "$HARD" = "0" ] && ok "Sin secretos escritos directamente en el codigo" \
                  || warn "$HARD posible(s) secreto(s) en el codigo: revisar"

# 8. Convencion de idioma: TODO el codigo en ingles (CLAUDE.md seccion 4).
#    Se revisan identificadores: nombre de clase, de metodo, de variable, de columna
#    y de archivo. NO se revisan (van en espanol por convencion y no deben marcarse):
#      - comentarios (// , # , /* */)
#      - cadenas de texto, incluidos los mensajes al usuario final
#      - el <template> de los .vue (es texto para el usuario; solo se lee <script>)
#      - los .md y cualquier archivo que no sea codigo
#    Excepciones que nunca se reportan: RFC, CURP, INE, RENAPO.
#    scripts/ queda fuera a proposito: sus nombres los eligio el usuario, no el codigo.
#    El criterio es una lista de palabras del dominio; se comparan tokens completos
#    (tras partir camelCase y snake_case), no subcadenas, para no marcar "linear"
#    por "linea". Los acentos se normalizan antes de comparar.
LANG_DIRS=""
for d in backend/app backend/routes backend/config backend/database backend/tests \
         frontend/src worker/src; do
  [ -d "$d" ] && LANG_DIRS="$LANG_DIRS $d"
done

LANG_WORDS="prospecto solicitud credito cliente tarjeta bitacora auditoria usuario
contrasena clave nombre apellido fecha documento identificacion validacion
verificacion simulacion linea estado estatus tipo numero correo telefono direccion
calle colonia municipio ciudad domicilio entidad codigo edad sexo monto importe
ingreso mensual mensualidad plazo tasa saldo limite moneda abono cobro pago cuota
cuenta capacidad riesgo regla evento firma cadena intento contrato vigencia
vencimiento sucursal empleado salario sueldo deuda archivo imagen respuesta peticion
resultado fallo prueba mensaje inicio guardar buscar crear borrar eliminar actualizar
listar obtener enviar calcular validar verificar generar registrar consultar
autorizar rechazar aprobar procesar mostrar cargar subir descargar iniciar terminar
aprobado rechazado autorizado cancelado pendiente activo inactivo
consulta captura registro busqueda calculo carga listado detalle"
LANG_EXC="rfc curp ine renapo"

LANG_AWK=$(cat <<'AWK'
function norm(s) {
  gsub(/á/,"a",s); gsub(/é/,"e",s); gsub(/í/,"i",s); gsub(/ó/,"o",s)
  gsub(/ú/,"u",s); gsub(/ü/,"u",s); gsub(/ñ/,"n",s)
  gsub(/Á/,"A",s); gsub(/É/,"E",s); gsub(/Í/,"I",s); gsub(/Ó/,"O",s)
  gsub(/Ú/,"U",s); gsub(/Ü/,"U",s); gsub(/Ñ/,"N",s)
  return s
}
# Parte camelCase/PascalCase en palabras sueltas.
function splitcamel(s,   i,c,p,out) {
  out=""; p=""
  for (i=1; i<=length(s); i++) {
    c = substr(s,i,1)
    if (c ~ /[A-Z]/ && p ~ /[a-z0-9]/) out = out " "
    out = out c; p = c
  }
  return out
}
function spanish(t) {
  if (t in EX) return 0
  if (t in SP) return 1
  if (length(t) > 3 && substr(t,length(t)-1) == "es" && substr(t,1,length(t)-2) in SP) return 1
  if (length(t) > 2 && substr(t,length(t))  == "s"  && substr(t,1,length(t)-1) in SP) return 1
  return 0
}
# Reporta como mucho un hallazgo por linea, para no inundar la salida.
function check(text, lineno, kind,   i,n,arr,t) {
  text = norm(text)
  gsub(/[^A-Za-z0-9_]/, " ", text)
  gsub(/_/, " ", text)
  n = split(tolower(splitcamel(text)), arr, " ")
  for (i=1; i<=n; i++) {
    t = arr[i]
    if (spanish(t)) { printf "%s:%d: %s -> %s\n", FILENAME, lineno, kind, t; return }
  }
}
BEGIN {
  n = split(WORDS, w, /[ \n\t]+/); for (i=1; i<=n; i++) if (w[i] != "") SP[w[i]] = 1
  n = split(EXC,   e, /[ \n\t]+/); for (i=1; i<=n; i++) if (e[i] != "") EX[e[i]] = 1
}
FNR == 1 {
  inblock=0; here=""; inscript=0
  isvue = (FILENAME ~ /\.vue$/)
  isphp = (FILENAME ~ /\.php$/)
  base = FILENAME; sub(/.*\//,"",base); sub(/\.[A-Za-z]+$/,"",base)
  check(base, 1, "nombre de archivo")
}
{
  line = $0
  # .vue: solo el bloque <script>; el <template> es texto para el usuario.
  if (isvue) {
    if (line ~ /<script/)    { inscript=1; next }
    if (line ~ /<\/script>/) { inscript=0; next }
    if (!inscript) next
  }
  # heredoc / nowdoc de PHP: es texto, no codigo.
  if (here != "") { if (line ~ ("^[ \t]*" here "[ \t]*;?[ \t]*$")) here=""; next }
  if (isphp && match(line, /<<<[ \t]*['"]?[A-Za-z_][A-Za-z0-9_]*/)) {
    h = substr(line, RSTART, RLENGTH); gsub(/[<>'"\t ]/,"",h); here = h
    sub(/<<<.*$/, "", line)
  }
  # comentarios de bloque
  if (inblock) {
    if (line ~ /\*\//) { sub(/^.*\*\//, "", line); inblock=0 } else next
  }
  while (match(line, /\/\*/)) {
    if (line ~ /\/\*.*\*\//) sub(/\/\*.*\*\//, " ", line)
    else { sub(/\/\*.*$/, "", line); inblock=1; break }
  }
  # cadenas de texto (mensajes al usuario incluidos)
  gsub(/"[^"]*"/, " ", line)
  gsub(/'[^']*'/, " ", line)
  gsub(/`[^`]*`/, " ", line)
  # comentarios de linea
  sub(/\/\/.*$/, "", line)
  if (isphp) sub(/#.*$/, "", line)
  check(line, FNR, "identificador")
}
AWK
)

if [ -z "$LANG_DIRS" ]; then
  warn "Aun no hay codigo de aplicacion: la convencion de idioma no se puede acreditar"
else
  LANG_HITS=$(find $LANG_DIRS -type f \( -name '*.php' -o -name '*.js' -o -name '*.mjs' \
                -o -name '*.cjs' -o -name '*.ts' -o -name '*.vue' \) 2>/dev/null | sort |
              xargs -r awk -v WORDS="$LANG_WORDS" -v EXC="$LANG_EXC" "$LANG_AWK" 2>/dev/null)
  LANG_N=$(printf "%s" "$LANG_HITS" | grep -c . )
  if [ "$LANG_N" = "0" ]; then
    ok "Identificadores en ingles: sin nombres en espanol en el codigo"
  else
    no "$LANG_N identificador(es) en espanol (CLAUDE.md seccion 4: todo el codigo en ingles)"
    printf "%s\n" "$LANG_HITS" | head -20 | sed 's/^/            /'
    [ "$LANG_N" -gt 20 ] && info "... y $((LANG_N - 20)) mas"
    info "No se marcan comentarios, mensajes al usuario ni .md; RFC, CURP, INE y RENAPO estan exentos."
  fi
fi

# ==================================================================== resumen ===
hdr "Resumen por tarea"
printf "%s" "$SUMMARY" | while IFS='|' read -r id o n w; do
  [ -z "$id" ] && continue
  if   [ "$n" -gt 0 ]; then printf "  %-4s ${C_R}%-8s${C_0} %s ok / %s falta / %s revisar\n" "$id" "FALTA"   "$o" "$n" "$w"
  elif [ "$w" -gt 0 ]; then printf "  %-4s ${C_Y}%-8s${C_0} %s ok / %s falta / %s revisar\n" "$id" "REVISAR" "$o" "$n" "$w"
  else                      printf "  %-4s ${C_G}%-8s${C_0} %s ok / %s falta / %s revisar\n" "$id" "OK"      "$o" "$n" "$w"
  fi
done

printf "\n${C_B}Totales:${C_0} ${C_G}%d OK${C_0} / ${C_R}%d FALTA${C_0} / ${C_Y}%d REVISAR${C_0}\n" \
       "$TOT_OK" "$TOT_NO" "$TOT_WARN"
printf "Si esto no coincide con PROGRESS.md, manda el codigo.\n"
printf "Este script no se modifica sin autorizacion del usuario (CLAUDE.md seccion 9).\n"
exit 0
