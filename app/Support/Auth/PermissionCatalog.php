<?php

namespace App\Support\Auth;

/**
 * Catálogo canónico de permisos del ERP.
 * Fuente de verdad para seeder, migraciones y documentación.
 */
final class PermissionCatalog
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            // Usuarios (ids legacy 1-4 en instalaciones existentes)
            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            // Pedidos (ids legacy 5-12)
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.delete',
            'orders.payments',
            'orders.ship',
            'orders.shipment.update',
            'orders.close',

            // Administración
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'permissions.view',
            'audit.view',

            // Operación
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'suppliers.delete',

            // Inventario / catálogo
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
            'attributes.view',
            'attributes.create',
            'attributes.update',
            'attributes.delete',
            'purchases.view',
            'purchases.create',
            'purchases.update',
            'purchases.payments',
            'movements.view',
            'inventory.view',

            // Producto / cuenta
            'dashboard.view',
            'account.view',
            'account.update',

            // Facturación electrónica (BillingService) y documentos internos
            'billing.view',
            'billing.detail',
            'billing.issue',
            'billing.retry',
            'billing.download_pdf',
            'billing.download_xml',
            'billing.download_cdr',
            'billing.regenerate_pdf',
            'billing.manage_templates',
            'purchases.documents',
            'billing.cancel',
            'billing.consult',
        ];
    }

    /**
     * Permisos operativos del rol USER (sin administración sensible).
     *
     * @return array<int, string>
     */
    public static function forUserRole(): array
    {
        return [
            'dashboard.view',
            'account.view',
            'account.update',
            'customers.view',
            'customers.create',
            'customers.update',
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'products.view',
            'products.create',
            'products.update',
            'categories.view',
            'attributes.view',
            'purchases.view',
            'purchases.create',
            'purchases.update',
            'purchases.payments',
            'movements.view',
            'inventory.view',
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.payments',
            'orders.ship',
            'orders.shipment.update',
            'orders.close',
            'billing.view',
            'billing.detail',
            'billing.issue',
            'billing.retry',
            'billing.cancel',
            'billing.consult',
            'billing.download_pdf',
            'billing.download_xml',
            'billing.download_cdr',
            'billing.regenerate_pdf',
            'purchases.documents',
        ];
    }

    /**
     * Renombres de permisos legacy → nomenclatura .view.
     *
     * @return array<string, string>
     */
    public static function renames(): array
    {
        return [
            'users.read' => 'users.view',
            'orders.read' => 'orders.view',
        ];
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'users' => 'Usuarios',
            'orders' => 'Pedidos',
            'roles' => 'Roles',
            'permissions' => 'Permisos',
            'audit' => 'Auditoría',
            'customers' => 'Clientes',
            'suppliers' => 'Proveedores',
            'products' => 'Productos',
            'categories' => 'Categorías',
            'attributes' => 'Atributos',
            'purchases' => 'Compras',
            'movements' => 'Movimientos',
            'inventory' => 'Inventario',
            'dashboard' => 'Panel',
            'account' => 'Cuenta',
            'billing' => 'Facturación',
        ];
    }

    /** @return array<string, string> */
    public static function actionLabels(): array
    {
        return [
            'users.view' => 'Ver usuarios',
            'users.create' => 'Crear usuarios',
            'users.update' => 'Editar usuarios',
            'users.delete' => 'Eliminar usuarios',
            'orders.view' => 'Ver pedidos',
            'orders.create' => 'Crear pedidos',
            'orders.update' => 'Editar pedidos',
            'orders.delete' => 'Cancelar pedidos',
            'orders.payments' => 'Registrar pagos de pedidos',
            'orders.ship' => 'Enviar pedidos',
            'orders.shipment.update' => 'Actualizar envío de pedidos',
            'orders.close' => 'Cerrar pedidos',
            'roles.view' => 'Ver roles',
            'roles.create' => 'Crear roles',
            'roles.update' => 'Editar roles',
            'roles.delete' => 'Eliminar roles',
            'permissions.view' => 'Ver permisos',
            'audit.view' => 'Ver auditoría',
            'customers.view' => 'Ver clientes',
            'customers.create' => 'Crear clientes',
            'customers.update' => 'Editar clientes',
            'customers.delete' => 'Eliminar clientes',
            'suppliers.view' => 'Ver proveedores',
            'suppliers.create' => 'Crear proveedores',
            'suppliers.update' => 'Editar proveedores',
            'suppliers.delete' => 'Eliminar proveedores',
            'products.view' => 'Ver productos',
            'products.create' => 'Crear productos',
            'products.update' => 'Editar productos',
            'products.delete' => 'Eliminar productos',
            'categories.view' => 'Ver categorías',
            'categories.create' => 'Crear categorías',
            'categories.update' => 'Editar categorías',
            'categories.delete' => 'Eliminar categorías',
            'attributes.view' => 'Ver atributos',
            'attributes.create' => 'Crear atributos',
            'attributes.update' => 'Editar atributos',
            'attributes.delete' => 'Eliminar atributos',
            'purchases.view' => 'Ver compras',
            'purchases.create' => 'Crear compras',
            'purchases.update' => 'Editar compras',
            'purchases.payments' => 'Registrar pagos de compras',
            'purchases.documents' => 'Documentos de compra',
            'movements.view' => 'Ver movimientos',
            'inventory.view' => 'Ver inventario',
            'dashboard.view' => 'Ver panel',
            'account.view' => 'Ver cuenta',
            'account.update' => 'Editar cuenta',
            'billing.view' => 'Ver facturación',
            'billing.detail' => 'Ver detalle de comprobante',
            'billing.issue' => 'Emitir comprobante',
            'billing.retry' => 'Reintentar envío',
            'billing.cancel' => 'Dar de baja comprobante',
            'billing.consult' => 'Consultar estado SUNAT',
            'billing.download_pdf' => 'Descargar PDF',
            'billing.download_xml' => 'Descargar XML',
            'billing.download_cdr' => 'Descargar CDR',
            'billing.regenerate_pdf' => 'Regenerar PDF',
            'billing.manage_templates' => 'Elegir plantilla PDF de comprobantes',
        ];
    }

    public static function groupKey(string $name): string
    {
        $separator = strpos($name, '.');

        return $separator === false ? 'general' : substr($name, 0, $separator);
    }

    public static function groupLabel(string $name): string
    {
        $key = self::groupKey($name);

        return self::groupLabels()[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function actionLabel(string $name): string
    {
        return self::actionLabels()[$name] ?? ucfirst(str_replace(['.', '_'], ' ', $name));
    }
}
