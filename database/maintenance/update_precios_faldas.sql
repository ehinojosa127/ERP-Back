-- PostgreSQL / psql. Base: confecciones_erika. Usuario: erika.
-- Actualiza el precio de venta unitario de la categoría Falda según N de paños.
-- 3 paños: 170.00 | 4 paños: 230.00 | 5 paños: 290.00
-- Simulación: agregar -v dry_run=true al comando psql.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL search_path = public;
SET LOCAL lock_timeout = '15s';

DO $$
BEGIN
    IF current_database() <> 'confecciones_erika' THEN
        RAISE EXCEPTION 'Conectar exclusivamente a confecciones_erika';
    END IF;
END $$;

CREATE TEMP TABLE precios_faldas_actualizados ON COMMIT DROP AS
WITH actualizados AS (
    UPDATE products AS p
    SET sale_price = CASE d.value
            WHEN '3' THEN 170.00
            WHEN '4' THEN 230.00
            WHEN '5' THEN 290.00
        END,
        updated_at = CURRENT_TIMESTAMP
    FROM product_details AS d
    JOIN attributes AS a ON a.id = d.attribute_id
    WHERE d.product_id = p.id
      AND a.name = 'N de paños'
      AND d.value IN ('3', '4', '5')
      AND EXISTS (
          SELECT 1 FROM categories AS c
          WHERE c.id = p.category_id AND c.name = 'Falda'
      )
    RETURNING p.id, d.value AS panos, p.sale_price
)
SELECT * FROM actualizados;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM precios_faldas_actualizados) THEN
        RAISE EXCEPTION 'No se encontraron faldas con el atributo N de paños y valores 3, 4 o 5; no se aplicaron cambios';
    END IF;
END $$;

SELECT panos AS numero_de_panos,
       sale_price AS precio_unitario,
       count(*) AS productos_actualizados
FROM precios_faldas_actualizados
GROUP BY panos, sale_price
ORDER BY panos;

\if :{?dry_run}
    \if :dry_run
        ROLLBACK;
        \echo 'Simulación completada: precios sin modificar.'
    \else
        COMMIT;
    \endif
\else
    COMMIT;
\endif
