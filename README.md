# erp-api

API del **ERP Confecciones Erika**. Expone el dominio operativo: autenticación, usuarios, roles, clientes, proveedores, productos, inventario, compras, pedidos, pagos, envíos y la orquestación de comprobantes.

Es el único backend que conoce el frontend. Cuando hay que emitir una boleta o factura electrónica, este servicio llama a **BillingService** por HTTP. Las notas de venta internas se quedan aquí, sin SUNAT.

```text
erp-front
    │  JWT + REST
    ▼
erp-api  (Laravel)  ── PostgreSQL `erp`
    │                 ── Redis (cache / colas en Docker)
    │                 ── storage (comprobantes de pago, documentos de compra, avatares)
    ▼
BillingService
```

## Stack

- PHP 8.3+, Laravel 13
- PostgreSQL (no MySQL)
- JWT (`php-open-source-saver/jwt-auth`)
- Zona horaria por defecto: `America/Lima`

## Desarrollo local

PostgreSQL debe estar en marcha con el mismo usuario/host que BillingService, y una base **propia** llamada `erp`.

```bash
cp .env.example .env
php artisan key:generate
# completar DB_* y JWT_SECRET en .env
php artisan migrate --force
php artisan db:seed --force
php artisan serve
```

Usuarios de prueba del seeder:

- `admin@example.com` / `password`
- `user@example.com` / `password`

| Variable | Uso |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` | Misma instancia PostgreSQL que BillingService |
| `DB_DATABASE` | `erp` (distinta de `billing`) |
| `BILLING_SERVICE_URL` | Local: `http://localhost:5147`. Docker: `http://billing:8080` |
| `BILLING_SERVICE_API_KEY` | Debe coincidir con `SERVICE_API_KEY` de BillingService |
| `REDIS_HOST` | Local: `127.0.0.1`. Docker: `redis` |
| `CORS_ALLOWED_ORIGINS` | Orígenes del SPA si no hay proxy nginx |

Los tests de PHPUnit siguen usando SQLite en memoria (`phpunit.xml`).

```bash
php artisan test
```

## Producción (Docker)

La imagen incluye **nginx + PHP-FPM**. El compose (carpeta padre, ver `BillingService/stack/`) levanta postgres, redis, billing y el front.

- Interno: puerto 80
- Publicado en el host: `http://localhost:8000` (también vía nginx del front en `/api`)

Al arrancar el contenedor corre `migrate` y `db:seed`.

El compose carga **este** `.env` (`env_file`) para `APP_KEY`, `JWT_SECRET`, etc. Solo sobrescribe host de Postgres/Redis y la URL de Billing (`http://billing:8080`).

```bash
cp .env.example .env
php artisan key:generate
# definir JWT_SECRET (php artisan jwt:secret) y DB_PASSWORD alineado al .env padre
```
