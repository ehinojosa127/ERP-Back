# Reinicio del ERP y catálogo de faldas

Preparado para las migraciones del repositorio hasta 2026-09-30 y PostgreSQL 16.
No se ha ejecutado en la VPS. El SQL se ejecuta con psql, por sus directivas `\set`.

## Resultado

- Se eliminan todos los usuarios (incluidos admin y user), refresh tokens, clientes, proveedores, compras, pagos, documentos de compra, pedidos, detalles, envíos, movimientos, notas de venta, referencias/eventos de facturación ERP, entregas webhook, trabajos y caché SQL.
- Se conservan `migrations`, `app_settings`, roles y permisos existentes con sus IDs. Se agregan los permisos iniciales faltantes y se reconstruyen exactamente las asignaciones de Admin (59) y User (38) según `PermissionCatalog.php`. Los roles adicionales permanecen sin usuarios; sus asignaciones permanecen.
- Se crea únicamente `administracion`, correo `admin@confeccioneserika.com`, con rol Admin y hash bcrypt de coste 12. La contraseña aleatoria de 44 caracteres está en `admin-credentials.txt`, excluido de Git y con permisos 600. Guardarla en un gestor de contraseñas. El SQL puede sobreescribir correo/hash con `-v admin_email=... -v admin_hash=...`.
- Una categoría `Falda`; tres atributos `Color`, `Talla`, `N de paños`.
- Colores: Negra, Azul, Blanca, Roja, Verde, Amarilla, Rosada, Morada, Gris, Beige, Marrón, Celeste, Turquesa, Fucsia y Naranja.
- Tallas: XS, S, M, L, XL, XXL, 8, 9, 10, 11, 12, 13, 14, 15 y 16. Se interpreta 8–16 como rango inclusivo, también impares.
- Paños: 3, 4 y 5; el valor del atributo es `4`, por ejemplo; en el nombre se muestra `4p`.
- Producto independiente por combinación: 675 productos y 2025 detalles. Ejemplos: `Falda Negra 4p M`, `Falda Azul 5p 16`.
- SKU `SKU-00001` a `SKU-00675`, compatible con `ProductService::nextSku()`. Precio 0 porque la columna es obligatoria; revisar precios antes de vender. Descripción nula. No se crean compras ni movimientos: stock físico y reservado cero.

## Hallazgos y límites

El stock se calcula en `Product.php` a partir de movimientos menos reservas de pedidos; no existe una columna stock que reiniciar. `product_details` guarda valores de atributos por producto: no existe un catálogo separado de valores ni una entidad de variantes. El nombre se almacena completo además de los atributos. La app permite editarlo libremente: este SQL establece la nomenclatura inicial, no impone una regla para futuras ediciones.

`docker/entrypoint.sh` ejecuta `db:seed` en cada arranque. Se corrigió `DatabaseSeeder.php` para crear cuentas demo solo en local/testing. **Desplegar ese cambio antes del reinicio**, de lo contrario reaparecerán las cuentas débiles. No usar `migrate:fresh` ni el seeder DemoDataSeeder después.

Se mantienen las secuencias (`CONTINUE IDENTITY`): el admin nuevo obtiene un ID posterior a los antiguos y los JWT antiguos no pueden corresponder al nuevo admin. También evita reutilizar IDs de pagos/pedidos empleados como claves de idempotencia por BillingService. No restablecer secuencias manualmente. Las sesiones Redis antiguas expirarán y no corresponden a usuarios existentes; se limpia la caché y la cola del ERP en el procedimiento.

La base `billing`, sus comprobantes electrónicos, series, correlativos, emisor y plantillas permanecen intactos. El listado de facturación puede seguir mostrando esos documentos históricos; se eliminan sus vínculos locales con pedidos. Las notas de venta internas sí se eliminan y su numeración basada en filas vuelve a comenzar. Este procedimiento corresponde a reiniciar el ERP; no es un reinicio completo de la facturación. Los archivos en volúmenes (avatares, comprobantes adjuntos, recibos) permanecen; no son filas de BD.

## Ejecutar en la VPS

Entorno de la VPS:

- Contenedor PostgreSQL: `confecciones-postgres`.
- Usuario PostgreSQL: `erika` (distinto del usuario administrador de la app).
- Base ERP: `confecciones_erika`.
- Archivo en la VPS: `reset_faldas.sql`, en la carpeta desde la cual se ejecutan los comandos.

Ejecutar desde la carpeta que contiene `docker-compose.yaml`. Crear el archivo con `nano reset_faldas.sql`, pegar el contenido completo del SQL actualizado, guardar con Ctrl+O, Enter y salir con Ctrl+X. También se puede transferir el archivo tal cual. El SQL verifica el nombre de la base antes de borrar datos. No requiere que el usuario de conexión sea superusuario, pero sí permisos sobre todas las tablas y secuencias afectadas.

Los comandos Compose del API/frontend usan los servicios `erp-api` y `erp-front` del repositorio; si la VPS los renombró, sustituir esos dos nombres por los que aparezcan en `docker compose config --services`. Mantener la configuración real de la VPS para que el API conecte a `confecciones_erika` con usuario `erika`: el Compose local original utiliza otros valores y no debe reemplazar la configuración de producción sin adaptarlos.

Primero desplegar el `DatabaseSeeder.php` corregido y reconstruir el API:

```bash
docker compose build erp-api
docker compose up -d --no-deps erp-api
```

Si se personalizó `HASH_DRIVER`, verificar que sea bcrypt (es el valor predeterminado del proyecto) o proporcionar un hash compatible con el driver usando `-v admin_hash=...`.

Detener las escrituras y respaldar. Pausar también integraciones externas que escriban directamente, si existen:

```bash
docker compose stop erp-front erp-api
mkdir -p backups
chmod 700 backups
umask 077
BACKUP_TAG=$(date +%Y%m%d_%H%M%S)
docker exec confecciones-postgres pg_dump -U erika -d confecciones_erika -Fc > "backups/confecciones_erika_${BACKUP_TAG}.dump"
docker exec confecciones-postgres pg_dump -U erika -d billing -Fc > "backups/billing_${BACKUP_TAG}.dump"
docker exec -i confecciones-postgres pg_restore --list < "backups/confecciones_erika_${BACKUP_TAG}.dump"
docker exec -i confecciones-postgres pg_restore --list < "backups/billing_${BACKUP_TAG}.dump"
```

Comprobar que cada respaldo terminó con código de salida 0 y que `pg_restore --list` muestra su contenido antes de continuar. El respaldo de `billing` corresponde a la base independiente del stack original; si la VPS la renombró o está en otro contenedor, adaptar exclusivamente ese respaldo al entorno real. El SQL no modifica esa base. Mantener API y workers detenidos durante la simulación y la ejecución. La simulación ejecuta y valida el contenido, luego hace ROLLBACK:

```bash
docker exec -i confecciones-postgres \
  psql -X -U erika -d confecciones_erika \
  -v ON_ERROR_STOP=1 -v dry_run=true < reset_faldas.sql
```

Ejecución definitiva:

```bash
docker exec -i confecciones-postgres \
  psql -X -U erika -d confecciones_erika \
  -v ON_ERROR_STOP=1 < reset_faldas.sql
```

Resultado esperado: 1 usuario, 675 productos, 2025 atributos asociados y 0 movimientos. Todo ocurre en una transacción; un error revierte las filas y el TRUNCATE. Los incrementos de secuencias de inserciones pueden dejar huecos incluso tras un rollback. No hay CASCADE: una dependencia nueva abortará el script.

Comprobar los registros creados después de la ejecución definitiva:

```bash
docker exec confecciones-postgres psql -X -U erika -d confecciones_erika \
  -c "SELECT (SELECT count(*) FROM public.users) AS usuarios, (SELECT count(*) FROM public.products) AS productos, (SELECT count(*) FROM public.product_details) AS atributos_asociados, (SELECT count(*) FROM public.movements) AS movimientos;"
```

Limpiar los trabajos Redis de la cola ERP y la caché mediante contenedores puntuales, sin iniciar supervisor. La cola predeterminada del proyecto es `default`; si hay workers externos o colas personalizadas, pausarlos y vaciar también sus colas ERP antes de levantar servicios:

```bash
docker compose run --rm --no-deps --entrypoint php erp-api artisan queue:clear redis --queue=default
docker compose run --rm --no-deps --entrypoint php erp-api artisan cache:clear
docker compose up -d erp-api erp-front
```

Para entrar interactivamente al contenedor o a psql:

```bash
docker exec -it confecciones-postgres sh
docker exec -it confecciones-postgres psql -X -U erika -d confecciones_erika
```

No ejecutar el SQL nuevamente sin intención de volver a borrar los datos. Guardar los respaldos y las credenciales en un lugar privado.

## Verificación realizada

Revisión de modelos, migraciones, seeders, autenticación JWT/Redis, servicios de inventario/facturación y Docker. Sintaxis PHP del seeder validada. Catálogos comparados contra PermissionCatalog; combinaciones y relaciones verificadas estáticamente. No hubo prueba real PostgreSQL local: no hay servidor PostgreSQL ni daemon Docker activo. La simulación anterior permite validar contra el esquema real de la VPS antes del COMMIT.
