# GolsFintech

Aplicación Fintech para la **gestión de solicitudes de crédito simple**: captura de datos
del prospecto (manual o por OCR), validación de identidad contra INE y RENAPO, motor de
reglas de crédito, simulación, alta del cliente con línea de crédito y tarjeta tokenizada,
y bitácora de auditoría append-only con encadenamiento SHA-256.

El diseño completo (Fases 1, 2 y 3) está en `docs/`, fuera del control de versiones.
La memoria del proyecto y las reglas de trabajo están en [`CLAUDE.md`](CLAUDE.md);
el avance en [`PROGRESS.md`](PROGRESS.md) y los hallazgos de seguridad en
[`BUGS.md`](BUGS.md).

## Arquitectura

Monolito modular con **hexagonal (puertos y adaptadores)** en el backend, más un worker
asíncrono independiente que solo consume cola y no expone endpoints.

```
backend/    Laravel 13 · PHP 8.4  → API REST (Domain / Application / Infrastructure / Http)
frontend/   Vue.js 3 (Composition API) · Vite → SPA del prospecto y panel administrativo
worker/     Node.js 24 LTS · BullMQ sobre Redis → OCR asíncrono y notificaciones
```

## Requisitos del entorno

| Componente | Versión verificada |
|---|---|
| Ubuntu Server | 24.04 LTS |
| PHP | 8.4.24 |
| Composer | 2.10.2 |
| Node.js | v24.19.0 LTS |
| npm | 11.17.0 |
| MySQL | 8.0.46 (127.0.0.1:3306) |
| Redis | 7.0.15 (127.0.0.1:6379) |

## Puesta en marcha (entorno de desarrollo)

```bash
# Backend — la API se publica en el puerto 6060
cd backend
cp .env.example .env && php artisan key:generate
composer install
php artisan serve --host=127.0.0.1 --port=6060

# Frontend
cd frontend && npm install && npm run dev

# Worker
cd worker && cp .env.example .env && npm install && npm start
```

Verificación de que la API responde:

```bash
curl -I http://127.0.0.1:6060      # → HTTP/1.1 200 OK
```

> El puerto **6060** es el único que expone esta aplicación. El servidor aloja otros
> servicios: no se modifica configuración global de Nginx, PHP-FPM ni MySQL.

## Secretos

Ningún secreto vive en el repositorio. `.env` está ignorado por Git; `.env.example`
documenta las claves requeridas sin valores reales. Ver las reglas de seguridad no
negociables en [`CLAUDE.md`](CLAUDE.md) §6.
