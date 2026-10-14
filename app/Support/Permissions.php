<?php

namespace App\Support;

/**
 * Catálogo de permisos del sistema, agrupados por módulo.
 */
class Permissions
{
    public const GROUPS = [
        'General' => [
            'dashboard.view' => 'Ver tablero',
            'reports.view' => 'Ver reportes',
            'audit.view' => 'Ver auditoría',
        ],
        'Productos y precios' => [
            'products.view' => 'Ver productos',
            'products.manage' => 'Crear / editar productos',
            'products.import' => 'Importar productos desde Excel',
            'prices.manage' => 'Actualizar precios (listas de proveedores, aumentos masivos)',
            'prices.revert' => 'Revertir actualizaciones de precios',
            'products.view_cost' => 'Ver costos y márgenes',
        ],
        'Stock' => [
            'stock.view' => 'Ver stock',
            'stock.adjust' => 'Ajustar stock',
            'stock.transfer' => 'Transferencias entre sucursales',
            'inventory.manage' => 'Inventario físico',
            'production.manage' => 'Órdenes de producción',
        ],
        'Ventas' => [
            'sales.create' => 'Vender (punto de venta)',
            'sales.view' => 'Ver ventas',
            'sales.cancel' => 'Anular ventas',
            'sales.return' => 'Registrar devoluciones',
            'sales.price_override' => 'Modificar precios y descuentos sin límite',
            'customers.view' => 'Ver clientes',
            'customers.manage' => 'Gestionar clientes y cobranzas',
        ],
        'Compras' => [
            'suppliers.view' => 'Ver proveedores',
            'suppliers.manage' => 'Gestionar proveedores y pagos',
            'purchases.view' => 'Ver compras',
            'purchases.manage' => 'Registrar compras y pedidos',
        ],
        'Caja' => [
            'cash.operate' => 'Abrir / cerrar caja propia',
            'cash.manage' => 'Supervisar todas las cajas',
            'expenses.manage' => 'Registrar gastos',
        ],
        'Configuración' => [
            'settings.manage' => 'Configuración de la empresa',
            'users.manage' => 'Usuarios y roles',
            'branches.manage' => 'Sucursales',
        ],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::GROUPS)));
    }

    public const DEFAULT_ROLES = [
        'Administrador' => ['*'],
        'Encargado' => [
            'dashboard.view', 'reports.view', 'products.view', 'products.manage', 'products.import', 'prices.manage',
            'products.view_cost', 'stock.view', 'stock.adjust', 'stock.transfer', 'inventory.manage', 'production.manage', 'sales.create',
            'sales.view', 'sales.cancel', 'sales.return', 'customers.view', 'customers.manage', 'suppliers.view',
            'suppliers.manage', 'purchases.view', 'purchases.manage', 'cash.operate', 'cash.manage', 'expenses.manage',
        ],
        'Vendedor' => [
            'products.view', 'stock.view', 'sales.create', 'sales.view', 'customers.view', 'customers.manage', 'cash.operate',
        ],
        'Depósito' => [
            'products.view', 'stock.view', 'stock.adjust', 'stock.transfer', 'inventory.manage', 'production.manage', 'purchases.view',
            'purchases.manage', 'suppliers.view',
        ],
    ];
}
