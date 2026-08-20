#!/usr/bin/env bash
# verificar_avance.sh — Audita el avance real de GolsFintech contra el código,
# sin depender de PROGRESS.md ni de lo que reporte Claude Code.
#
# Uso:  bash verificar_avance.sh            (desde la raíz del proyecto)
#       bash verificar_avance.sh /ruta/proy

set -uo pipefail
ROOT="${1:-$(pwd)}"
cd "$ROOT" || { echo "No existe $ROOT"; exit 1; }

ok(){   printf "  \033[32m[OK]\033[0m    %s\n" "$1"; }
no(){   printf "  \033[31m[FALTA]\033[0m %s\n" "$1"; }
warn(){ printf "  \033[33m[REVISAR]\033[0m %s\n" "$1"; }
hdr(){  printf "\n\033[1m== %s ==\033[0m\n" "$1"; }

# Busca un directorio por nombre en cualquier nivel (ignora vendor/node_modules)
finddir(){ find . -type d -name "$1" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/.git/*" 2>/dev/null | head -1; }
# Cuenta archivos que contengan un patrón
grepcount(){ grep -rl --include="$2" -- "$1" . 2>/dev/null | grep -v -E 'vendor/|node_modules/|\.git/' | wc -l | tr -d ' '; }

hdr "0. Contexto del repositorio"
if [ -d .git ]; then
  echo "  Rama actual : $(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
  echo "  Último commit: $(git log -1 --format='%h %ad %s' --date=short 2>/dev/null)"
  echo "  Commits totales: $(git rev-list --count HEAD 2>/dev/null)"
  DIRTY=$(git status --porcelain | wc -l | tr -d ' ')
  [ "$DIRTY" -gt 0 ] && warn "$DIRTY archivo(s) sin commitear — hay trabajo no registrado en Git" \
                     || ok "Árbol de trabajo limpio"
else
  no "Este directorio no es un repositorio Git"
fi

hdr "0b. Commits por tarea (T1..T9)"
for t in T1 T2 T3 T4 T5 T6 T7 T8 T9; do
  c=$(git log --oneline --all 2>/dev/null | grep -c -E "(^|[^A-Za-z])$t[:. ]" || true)
  if [ "${c:-0}" -gt 0 ]; then
    ok "$t — $c commit(s): $(git log --oneline --all 2>/dev/null | grep -E "(^|[^A-Za-z])$t[:. ]" | head -1)"
  else
    no "$t — sin commits que lo referencien"
  fi
done

hdr "0c. Archivos de control"
for f in CLAUDE.md PROGRESS.md BUGS.md; do
  if [ -f "$f" ]; then
    lines=$(wc -l < "$f" | tr -d ' ')
    last=$(git log -1 --format='%ad' --date=short -- "$f" 2>/dev/null)
    ok "$f — $lines líneas — última modificación en Git: ${last:-sin registro}"
  else
    no "$f no existe"
  fi
done
if [ -f PROGRESS.md ]; then
  echo "  --- Primeras 12 líneas de PROGRESS.md (entrada más reciente) ---"
  head -12 PROGRESS.md | sed 's/^/    /'
fi

hdr "T1. Reestructuración hexagonal"
DOM=$(finddir Domain); [ -z "$DOM" ] && DOM=$(finddir domain)
APP=$(finddir Application); [ -z "$APP" ] && APP=$(finddir application)
INF=$(finddir Infrastructure); [ -z "$INF" ] && INF=$(finddir infrastructure)
[ -n "$DOM" ] && ok "Capa de dominio: $DOM" || no "No se encontró capa de dominio"
[ -n "$APP" ] && ok "Capa de aplicación: $APP" || no "No se encontró capa de aplicación"
[ -n "$INF" ] && ok "Capa de infraestructura: $INF" || no "No se encontró capa de infraestructura"
if [ -n "$DOM" ]; then
  LEAK=$(grep -rl -E "use (Illuminate|App\\\\Models)" "$DOM" --include="*.php" 2>/dev/null | wc -l | tr -d ' ')
  [ "$LEAK" = "0" ] && ok "El dominio no importa Illuminate (regla de dependencia respetada)" \
                    || no "$LEAK archivo(s) del dominio importan Illuminate — la capa está contaminada"
  echo "  Clases en el dominio: $(find "$DOM" -name '*.php' | wc -l | tr -d ' ')"
fi

hdr "T2. Puertos e interfaces"
for port in OcrService IdentityValidator CardIssuer AuditLogger; do
  n=$(grepcount "interface $port" "*.php")
  [ "$n" -gt 0 ] && ok "Puerto $port definido" || no "Puerto $port no definido"
done
BIND=$(grep -rl -E "->bind\(|->singleton\(" --include="*ServiceProvider.php" . 2>/dev/null | grep -v vendor | wc -l | tr -d ' ')
[ "$BIND" -gt 0 ] && ok "Hay enlaces registrados en ServiceProvider" || no "No se detectaron enlaces puerto→adaptador"
FAKE=$(grep -rl -iE "class (Fake|InMemory|Null)[A-Za-z]+" --include="*.php" . 2>/dev/null | grep -v vendor | wc -l | tr -d ' ')
[ "$FAKE" -gt 0 ] && ok "$FAKE adaptador(es) falso(s) para pruebas" || warn "Sin adaptadores falsos — las pruebas del dominio podrían depender de infraestructura"

hdr "T3. Motor de reglas de crédito"
ENG=$(grepcount -iE "CreditEngine|CreditRules|PaymentCapacity" "*.php")
[ "$ENG" -gt 0 ] && ok "Motor de reglas presente ($ENG archivo(s))" || no "No se encontró el motor de reglas"
TST=$(find . -path ./vendor -prune -o -type d -name tests -print 2>/dev/null | head -1)
if [ -n "$TST" ]; then
  n=$(grep -rl -iE "credit|simulation|capacity" "$TST" --include="*.php" 2>/dev/null | wc -l | tr -d ' ')
  [ "$n" -gt 0 ] && ok "$n archivo(s) de prueba del motor de reglas" || no "Sin pruebas del motor de reglas"
fi

hdr "T4. Worker asíncrono (Node.js + BullMQ)"
WPKG=$(find . -name package.json -not -path "*/node_modules/*" 2>/dev/null | head -3)
if [ -n "$WPKG" ]; then
  echo "$WPKG" | while read -r f; do
    grep -q '"bullmq"' "$f" 2>/dev/null && ok "BullMQ declarado en $f" || true
  done
  NODEV=$(node -v 2>/dev/null)
  case "$NODEV" in
    v24*) ok "Node.js $NODEV (LTS, correcto)";;
    "")   warn "Node.js no disponible en este shell";;
    *)    warn "Node.js $NODEV — se definió Node.js 24 LTS en el diseño";;
  esac
else
  no "No se encontró package.json del worker"
fi
Q=$(grepcount -iE "Queue|Worker|bullmq" "*.js")
[ "$Q" -gt 0 ] && ok "$Q archivo(s) JS relacionados con colas" || no "Sin código de cola"

hdr "T5. Adaptadores de verificación de identidad"
for a in Ine Renapo Ekyc; do
  n=$(grepcount -i "$a" "*.php")
  [ "$n" -gt 0 ] && ok "Referencias a $a: $n archivo(s)" || no "Sin adaptador $a"
done

hdr "T6. Bitácora con hash encadenado"
MIG=$(grep -rl -iE "previous_hash|chain_hash|hash" --include="*.php" ./database 2>/dev/null | wc -l | tr -d ' ')
[ "${MIG:-0}" -gt 0 ] && ok "Migración con columna de hash encadenado" || no "Sin columna de hash en migraciones"
VER=$(grep -rl -iE "verify.?chain|VerifyAuditChain" --include="*.php" . 2>/dev/null | grep -v vendor | wc -l | tr -d ' ')
[ "$VER" -gt 0 ] && ok "Comando de verificación de la cadena" || no "Sin comando que verifique la integridad de la cadena"
ARG=$(grepcount -i "argon2" "*.php")
[ "$ARG" -gt 0 ] && ok "Argon2 configurado para credenciales" || warn "No se detectó Argon2id"
BAD=$(grep -rn -iE "md5\(|sha1\(" --include="*.php" --include="*.js" . 2>/dev/null | grep -v -E 'vendor/|node_modules/' | wc -l | tr -d ' ')
[ "$BAD" = "0" ] && ok "Sin uso de MD5 ni SHA-1" || no "$BAD uso(s) de MD5/SHA-1 — prohibidos en el diseño"

hdr "T7. Pantallas del front end (7 esperadas)"
VUE=$(find . -name "*.vue" -not -path "*/node_modules/*" 2>/dev/null | wc -l | tr -d ' ')
echo "  Componentes .vue totales: $VUE"
for v in WelcomeView ProspectDataFormView DocumentUploadView VerificationResultView \
         CreditSimulationView AuthorizationConfirmedView CustomerLookupView; do
  if find . -name "$v.vue" -not -path "*/node_modules/*" 2>/dev/null | grep -q .; then
    ok "$v.vue"
  else
    no "$v.vue"
  fi
done

hdr "T8. Controles de seguridad en la interfaz"
MASK=$(grepcount -iE "mask|enmascar" "*.php")
[ "$MASK" -gt 0 ] && ok "Lógica de enmascaramiento presente" || no "Sin enmascaramiento de datos sensibles"
LOGLEAK=$(grep -rn -iE "Log::(info|debug).*(curp|rfc|card_number)" --include="*.php" . 2>/dev/null | grep -v vendor | wc -l | tr -d ' ')
[ "$LOGLEAK" = "0" ] && ok "No se detectó CURP/RFC/tarjeta en logs" || no "$LOGLEAK posible(s) fuga(s) de datos sensibles a logs"

hdr "T9. Perímetro, cabeceras y puerto"
HDRS=$(grep -rl -iE "Strict-Transport-Security|Content-Security-Policy" --include="*.php" --include="*.conf" . 2>/dev/null | grep -v vendor | wc -l | tr -d ' ')
[ "$HDRS" -gt 0 ] && ok "Cabeceras de seguridad configuradas" || no "Sin HSTS ni CSP"
if command -v ss >/dev/null; then
  ss -tulpn 2>/dev/null | grep -q ":6060" && ok "Puerto 6060 en escucha" || warn "Puerto 6060 no está escuchando ahora mismo"
fi

hdr "Higiene de seguridad del repositorio"
if [ -f .gitignore ]; then
  grep -q "^\.env" .gitignore && ok ".env excluido en .gitignore" || no ".env NO está en .gitignore"
else
  no "No existe .gitignore"
fi
if [ -d .git ]; then
  TRACKED=$(git ls-files | grep -E "(^|/)\.env$" | wc -l | tr -d ' ')
  [ "$TRACKED" = "0" ] && ok "Ningún .env versionado" || no "¡$TRACKED archivo .env está versionado en Git!"
fi

hdr "Análisis de dependencias (hallazgos reales)"
if [ -f backend/composer.json ]; then
  echo "  --- composer audit (backend) ---"
  ( cd backend && composer audit 2>&1 | tail -12 | sed 's/^/    /' )
else
  warn "backend/composer.json no existe todavía"
fi
for d in frontend worker; do
  if [ -f "$d/package.json" ]; then
    echo "  --- npm audit ($d) ---"
    ( cd "$d" && { [ -f package-lock.json ] || npm i --package-lock-only >/dev/null 2>&1; }
      npm audit 2>&1 | tail -12 | sed 's/^/    /' )
  else
    warn "$d/package.json no existe todavía"
  fi
done

printf "\n\033[1mFin de la auditoría.\033[0m Compara este resultado con PROGRESS.md: si difieren, el código manda.\n"
