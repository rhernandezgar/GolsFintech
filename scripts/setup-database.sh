#!/usr/bin/env bash
#
# Crea las bases de datos de GolsFintech y su usuario de minimo privilegio.
#
#   sudo bash /opt/golsfintech/scripts/setup-database.sh
#
# Genera una contrasena aleatoria, la escribe en backend/.env (archivo ignorado
# por Git) y no la deja en el historial del shell ni en el repositorio.

set -euo pipefail

echo "==> setup-database.sh: iniciando..."

PROJECT_DIR="/opt/golsfintech"
ENV_FILE="${PROJECT_DIR}/backend/.env"
DB_USER="golsfintech"

# Este MySQL resuelve 127.0.0.1 a "localhost", de modo que una cuenta creada solo
# para '127.0.0.1' es rechazada con error 1045. Se crea para ambos hosts.
DB_HOSTS=("localhost" "127.0.0.1")

if [[ "${EUID}" -ne 0 ]]; then
    echo "Este script necesita root para conectarse a MySQL por socket." >&2
    echo "Ejecuta: sudo bash ${PROJECT_DIR}/scripts/setup-database.sh" >&2
    exit 1
fi

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "No existe ${ENV_FILE}. Ejecuta antes: cp backend/.env.example backend/.env" >&2
    exit 1
fi

# Contrasena aleatoria de 32 caracteres alfanumericos: sin simbolos que
# compliquen ni el archivo .env ni las sentencias SQL.
DB_PASSWORD="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
echo "==> Contrasena generada. Creando bases y usuario en MySQL..."

GRANT_SQL=""
for host in "${DB_HOSTS[@]}"; do
    GRANT_SQL+="
CREATE USER IF NOT EXISTS '${DB_USER}'@'${host}' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'${host}' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
    ON golsfintech.* TO '${DB_USER}'@'${host}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
    ON golsfintech_test.* TO '${DB_USER}'@'${host}';
"
done

mysql <<SQL
CREATE DATABASE IF NOT EXISTS golsfintech
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS golsfintech_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
${GRANT_SQL}
FLUSH PRIVILEGES;
SQL

echo "==> MySQL configurado. Escribiendo backend/.env..."

# Escribe la contrasena en el .env conservando el propietario original.
OWNER="$(stat -c '%U:%G' "${ENV_FILE}")"
python3 - "${ENV_FILE}" "${DB_PASSWORD}" <<'PY'
import pathlib, re, sys

path, password = pathlib.Path(sys.argv[1]), sys.argv[2]
lines, found = path.read_text().splitlines(), False
for i, line in enumerate(lines):
    if re.match(r'^#?\s*DB_PASSWORD=', line):
        lines[i], found = f'DB_PASSWORD={password}', True
if not found:
    lines.append(f'DB_PASSWORD={password}')
path.write_text('\n'.join(lines) + '\n')
PY
chown "${OWNER}" "${ENV_FILE}"
chmod 600 "${ENV_FILE}"

# Comprueba que el usuario recien creado conecta de verdad por TCP, que es como
# lo hara Laravel (DB_HOST=127.0.0.1).
if mysql -h 127.0.0.1 -u "${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" >/dev/null 2>&1; then
    echo "OK  Bases 'golsfintech' y 'golsfintech_test' listas."
    echo "OK  Usuario '${DB_USER}' creado para localhost y 127.0.0.1 con privilegios acotados."
    echo "OK  Contrasena escrita en backend/.env (no se muestra ni se versiona)."
    echo
    echo "Ya puedes decirle a Claude que continue con T2."
else
    echo "ERROR: el usuario se creo pero no logra conectarse. Revisa el log de MySQL." >&2
    exit 1
fi
