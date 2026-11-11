-- reset_faldas.sql — PostgreSQL 16 / psql.
-- Destructivo: exclusivamente base confecciones_erika, esquema public.
-- Contenedor: confecciones-postgres. Usuario PostgreSQL: erika.
-- No modifica la base independiente billing.
-- Simulación desde la VPS:
-- docker exec -i confecciones-postgres psql -X -U erika -d confecciones_erika -v ON_ERROR_STOP=1 -v dry_run=true < reset_faldas.sql
-- Ejecución definitiva desde la VPS:
-- docker exec -i confecciones-postgres psql -X -U erika -d confecciones_erika -v ON_ERROR_STOP=1 < reset_faldas.sql
-- Incluye correo y hash bcrypt del nuevo admin; se pueden sustituir con -v.
-- Contraseña entregada aparte. Nunca se guarda texto plano en este SQL.
\set ON_ERROR_STOP on
\if :{?admin_email}
\else
  \set admin_email 'admin@confeccioneserika.com'
\endif
\if :{?admin_hash}
\else
  \set admin_hash '$2y$12$.00xPyCjK7l.8QGqTyugI.CDaWgpwvHVx6L7KwmkNRKzIMtRrwssa'
\endif
BEGIN;
SET LOCAL search_path = public;
SET LOCAL lock_timeout = '15s';
CREATE TEMP TABLE reset_admin (email text, password_hash text) ON COMMIT DROP;
INSERT INTO reset_admin VALUES (:'admin_email', :'admin_hash');
DO $$
BEGIN
 IF current_database() <> 'confecciones_erika' THEN
   RAISE EXCEPTION 'Conectar exclusivamente a confecciones_erika; base actual: %', current_database();
 END IF;
 IF EXISTS (SELECT 1 FROM reset_admin WHERE email !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
   OR lower(email) IN ('admin@example.com','user@example.com')
   OR NOT (password_hash ~ '^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$' OR password_hash LIKE '$argon2%'))
 THEN RAISE EXCEPTION 'Correo o hash inválido'; END IF;
 IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_30_000001_add_unique_customers_phone_number')
 THEN RAISE EXCEPTION 'Ejecutar primero todas las migraciones del repositorio'; END IF;
END $$;
-- Sin CASCADE: una dependencia nueva/no contemplada aborta en lugar de borrarse.
-- CONTINUE IDENTITY evita reutilizar usuarios JWT y claves ERP de BillingService.
TRUNCATE TABLE
 public.refresh_tokens,
 public.order_billing_references,
 public.billing_events,
 public.sales_notes,
 public.shipments,
 public.order_payments,
 public.order_details,
 public.orders,
 public.purchase_documents,
 public.purchase_payments,
 public.purchase_details,
 public.purchases,
 public.movements,
 public.product_details,
 public.products,
 public.categories,
 public.attributes,
 public.customers,
 public.suppliers,
 public.outbound_webhook_deliveries,
 public.jobs,
 public.job_batches,
 public.failed_jobs,
 public.cache,
 public.cache_locks,
 public.users CONTINUE IDENTITY RESTRICT;
-- Se conservan roles, permisos y sus IDs; se reconstruyen las asignaciones iniciales.
LOCK TABLE roles, permissions, role_permissions IN ACCESS EXCLUSIVE MODE;
CREATE TEMP TABLE reset_permissions (name text PRIMARY KEY, for_user boolean) ON COMMIT DROP;
INSERT INTO reset_permissions VALUES
 ('users.view', false),
 ('users.create', false),
 ('users.update', false),
 ('users.delete', false),
 ('orders.view', true),
 ('orders.create', true),
 ('orders.update', true),
 ('orders.delete', false),
 ('orders.payments', true),
 ('orders.ship', true),
 ('orders.shipment.update', true),
 ('orders.close', true),
 ('roles.view', false),
 ('roles.create', false),
 ('roles.update', false),
 ('roles.delete', false),
 ('permissions.view', false),
 ('audit.view', false),
 ('customers.view', true),
 ('customers.create', true),
 ('customers.update', true),
 ('customers.delete', false),
 ('suppliers.view', true),
 ('suppliers.create', true),
 ('suppliers.update', true),
 ('suppliers.delete', false),
 ('products.view', true),
 ('products.create', true),
 ('products.update', true),
 ('products.delete', false),
 ('categories.view', true),
 ('categories.create', false),
 ('categories.update', false),
 ('categories.delete', false),
 ('attributes.view', true),
 ('attributes.create', false),
 ('attributes.update', false),
 ('attributes.delete', false),
 ('purchases.view', true),
 ('purchases.create', true),
 ('purchases.update', true),
 ('purchases.payments', true),
 ('movements.view', true),
 ('inventory.view', true),
 ('dashboard.view', true),
 ('account.view', true),
 ('account.update', true),
 ('billing.view', true),
 ('billing.detail', true),
 ('billing.issue', true),
 ('billing.retry', true),
 ('billing.download_pdf', true),
 ('billing.download_xml', true),
 ('billing.download_cdr', true),
 ('billing.regenerate_pdf', true),
 ('billing.manage_templates', false),
 ('purchases.documents', true),
 ('billing.cancel', true),
 ('billing.consult', true);
INSERT INTO permissions (name, created_at, updated_at)
SELECT r.name, now(), now() FROM reset_permissions r
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.name=r.name);
INSERT INTO roles (name, description, created_at, updated_at)
SELECT n, d, now(), now() FROM (VALUES
 ('Admin', 'Administrador del sistema'), ('User', 'Usuario operativo del ERP')) AS v(n,d)
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name=n);
DO $$ BEGIN
 IF EXISTS (SELECT name FROM roles WHERE name IN ('Admin','User') GROUP BY name HAVING count(*)<>1)
 OR EXISTS (SELECT p.name FROM permissions p JOIN reset_permissions r ON r.name=p.name GROUP BY p.name HAVING count(*)<>1)
 THEN RAISE EXCEPTION 'Roles o permisos iniciales duplicados: revisar catálogo'; END IF;
END $$;
DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE name IN ('Admin','User'));
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, now(), now() FROM roles r CROSS JOIN reset_permissions c
JOIN permissions p ON p.name=c.name
WHERE r.name='Admin' OR (r.name='User' AND c.for_user);
INSERT INTO users (username,email,password,role_id,created_at,updated_at)
SELECT 'administracion', a.email, a.password_hash, r.id, now(), now()
FROM reset_admin a CROSS JOIN roles r WHERE r.name='Admin';
INSERT INTO categories (name,created_by,updated_by,created_at,updated_at)
SELECT 'Falda',id,id,now(),now() FROM users;
INSERT INTO attributes (name,created_by,updated_by,created_at,updated_at)
SELECT n,id,id,now(),now() FROM users CROSS JOIN
 (VALUES ('Color'),('Talla'),('N de paños')) AS v(n);
CREATE TEMP TABLE reset_variants ON COMMIT DROP AS
SELECT row_number() OVER (ORDER BY c.ord,p.panos,t.ord) AS seq,
 c.color, p.panos::text AS panos, t.talla,
 'Falda ' || c.color || ' ' || p.panos || 'p ' || t.talla AS name
FROM (VALUES
 (1,'Negra'),(2,'Azul'),(3,'Blanca'),(4,'Roja'),(5,'Verde'),
 (6,'Amarilla'),(7,'Rosada'),(8,'Morada'),(9,'Gris'),(10,'Beige'),
 (11,'Marrón'),(12,'Celeste'),(13,'Turquesa'),(14,'Fucsia'),(15,'Naranja')) AS c(ord,color)
CROSS JOIN (VALUES (3),(4),(5)) AS p(panos)
CROSS JOIN (VALUES (1,'XS'),(2,'S'),(3,'M'),(4,'L'),(5,'XL'),(6,'XXL'),
 (7,'8'),(8,'9'),(9,'10'),(10,'11'),(11,'12'),(12,'13'),(13,'14'),(14,'15'),(15,'16')) AS t(ord,talla);
INSERT INTO products (name,sale_price,sku,category_id,created_by,updated_by,created_at,updated_at)
SELECT v.name,0,'SKU-' || lpad(v.seq::text,5,'0'),c.id,u.id,u.id,now(),now()
FROM reset_variants v CROSS JOIN categories c CROSS JOIN users u ORDER BY v.seq;
INSERT INTO product_details (product_id,attribute_id,value,created_by,updated_by,created_at,updated_at)
SELECT p.id,a.id,CASE a.name WHEN 'Color' THEN v.color WHEN 'Talla' THEN v.talla ELSE v.panos END,
 u.id,u.id,now(),now()
FROM products p JOIN reset_variants v ON v.name=p.name CROSS JOIN attributes a CROSS JOIN users u;
DO $$ BEGIN
 IF (SELECT count(*) FROM products)<>675 OR (SELECT count(*) FROM product_details)<>2025
 OR (SELECT count(*) FROM users)<>1 OR EXISTS (SELECT 1 FROM movements)
 OR EXISTS (SELECT 1 FROM products p WHERE
   (SELECT count(*) FROM product_details d WHERE d.product_id=p.id)<>3)
 THEN RAISE EXCEPTION 'Validación final fallida'; END IF;
END $$;
SELECT (SELECT count(*) FROM users) AS usuarios,
 (SELECT count(*) FROM products) AS productos,
 (SELECT count(*) FROM product_details) AS atributos_asociados,
 (SELECT count(*) FROM movements) AS movimientos;
\if :{?dry_run}
  \if :dry_run
    ROLLBACK;
    \echo 'Simulación completada: cambios revertidos.'
  \else
    COMMIT;
  \endif
\else
  COMMIT;
\endif
