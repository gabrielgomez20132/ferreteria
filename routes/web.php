<?php

use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\TenantController as AdminTenantController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\PaymentOrderController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PriceUpdateController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPriceImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ---------- Autenticación y registro de nuevas empresas ----------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/registro', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/registro', [AuthController::class, 'register'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'home'])->name('home');
    Route::get('/suscripcion-vencida', [DashboardController::class, 'expired'])->name('subscription.expired');
    Route::post('/sucursal', [BranchController::class, 'switch'])->name('branch.switch');

    Route::get('/tablero', [DashboardController::class, 'index'])->name('dashboard')->middleware('permission:dashboard.view');

    // ---------- Productos ----------
    Route::prefix('productos')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index')->middleware('permission:products.view');
        Route::get('/buscar', [ProductController::class, 'search'])->name('search')->middleware('permission:products.view|sales.create|purchases.manage|stock.transfer');
        Route::get('/exportar', [ProductController::class, 'export'])->name('export')->middleware('permission:products.view');
        Route::get('/etiquetas', [ProductController::class, 'labels'])->name('labels')->middleware('permission:products.view');
        Route::middleware('permission:products.import')->group(function () {
            Route::get('/importar', [ProductController::class, 'importForm'])->name('import');
            Route::post('/importar', [ProductController::class, 'import'])->name('import.store');
            Route::get('/importar/plantilla', [ProductController::class, 'template'])->name('import.template');
        });
        Route::middleware('permission:products.manage')->group(function () {
            Route::get('/nuevo', [ProductController::class, 'create'])->name('create');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('/{product}/editar', [ProductController::class, 'edit'])->name('edit');
            Route::put('/{product}', [ProductController::class, 'update'])->name('update');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
            Route::post('/{product}/variantes', [ProductController::class, 'variants'])->name('variants');
        });
        Route::get('/{product}', [ProductController::class, 'show'])->name('show')->middleware('permission:products.view');
    });

    // Tablas auxiliares del catálogo (categorías, marcas, unidades, listas de precio)
    Route::middleware('permission:products.manage')->prefix('catalogo')->name('catalog.')->group(function () {
        Route::get('/{type}', [CatalogController::class, 'index'])->name('index')->whereIn('type', CatalogController::TYPES);
        Route::post('/{type}', [CatalogController::class, 'store'])->name('store')->whereIn('type', CatalogController::TYPES);
        Route::put('/{type}/{id}', [CatalogController::class, 'update'])->name('update')->whereIn('type', CatalogController::TYPES);
        Route::delete('/{type}/{id}', [CatalogController::class, 'destroy'])->name('destroy')->whereIn('type', CatalogController::TYPES);
    });

    // ---------- Precios (foco del sistema) ----------
    Route::prefix('precios')->middleware('permission:prices.manage')->group(function () {
        // Listas de proveedores
        Route::prefix('listas-proveedores')->name('price-imports.')->group(function () {
            Route::get('/', [SupplierPriceImportController::class, 'index'])->name('index');
            Route::post('/', [SupplierPriceImportController::class, 'store'])->name('store');
            Route::get('/{import}', [SupplierPriceImportController::class, 'show'])->name('show');
            Route::post('/{import}/procesar', [SupplierPriceImportController::class, 'process'])->name('process');
            Route::get('/{import}/revision', [SupplierPriceImportController::class, 'review'])->name('review');
            Route::post('/{import}/seleccion', [SupplierPriceImportController::class, 'select'])->name('select');
            Route::post('/{import}/filas/{row}/vincular', [SupplierPriceImportController::class, 'link'])->name('link');
            Route::post('/{import}/filas/{row}/desvincular', [SupplierPriceImportController::class, 'unlink'])->name('unlink');
            Route::post('/{import}/crear-productos', [SupplierPriceImportController::class, 'createProducts'])->name('create-products');
            Route::post('/{import}/aplicar', [SupplierPriceImportController::class, 'apply'])->name('apply');
            Route::post('/{import}/cancelar', [SupplierPriceImportController::class, 'cancel'])->name('cancel');
            Route::get('/{import}/archivo', [SupplierPriceImportController::class, 'download'])->name('download');
            Route::get('/{import}/exportar', [SupplierPriceImportController::class, 'export'])->name('export');
        });

        Route::name('prices.')->group(function () {
            Route::get('/actualizaciones', [PriceUpdateController::class, 'index'])->name('updates');
            Route::get('/actualizaciones/{priceUpdate}', [PriceUpdateController::class, 'show'])->name('updates.show');
            Route::post('/actualizaciones/{priceUpdate}/revertir', [PriceUpdateController::class, 'revert'])->name('updates.revert')->middleware('permission:prices.revert');
            Route::get('/masiva', [PriceUpdateController::class, 'mass'])->name('mass');
            Route::post('/masiva/vista-previa', [PriceUpdateController::class, 'massPreview'])->name('mass.preview');
            Route::post('/masiva/aplicar', [PriceUpdateController::class, 'massApply'])->name('mass.apply');
            Route::get('/revision', [PriceUpdateController::class, 'review'])->name('review');
            Route::post('/revision/aplicar', [PriceUpdateController::class, 'reviewApply'])->name('review.apply');
            Route::get('/comparativa-proveedores', [PriceUpdateController::class, 'comparison'])->name('comparison');
        });
    });

    // ---------- Stock ----------
    Route::prefix('stock')->name('stock.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index')->middleware('permission:stock.view');
        Route::get('/movimientos', [StockController::class, 'movements'])->name('movements')->middleware('permission:stock.view');
        Route::get('/faltantes', [StockController::class, 'lowStock'])->name('low')->middleware('permission:stock.view');
        Route::get('/ajuste', [StockController::class, 'adjustForm'])->name('adjust')->middleware('permission:stock.adjust');
        Route::post('/ajuste', [StockController::class, 'adjust'])->name('adjust.store')->middleware('permission:stock.adjust');
        Route::put('/minimos/{product}', [StockController::class, 'updateLevels'])->name('levels')->middleware('permission:stock.adjust');

        Route::middleware('permission:stock.transfer')->prefix('transferencias')->name('transfers.')->group(function () {
            Route::get('/', [StockTransferController::class, 'index'])->name('index');
            Route::get('/nueva', [StockTransferController::class, 'create'])->name('create');
            Route::post('/', [StockTransferController::class, 'store'])->name('store');
            Route::get('/{transfer}', [StockTransferController::class, 'show'])->name('show');
            Route::post('/{transfer}/recibir', [StockTransferController::class, 'receive'])->name('receive');
            Route::post('/{transfer}/cancelar', [StockTransferController::class, 'cancel'])->name('cancel');
        });

        Route::middleware('permission:production.manage')->prefix('produccion')->name('production.')->group(function () {
            Route::get('/', [ProductionOrderController::class, 'index'])->name('index');
            Route::post('/', [ProductionOrderController::class, 'store'])->name('store');
            Route::get('/insumos', [ProductionOrderController::class, 'exportRequirements'])->name('requirements');
            Route::get('/{order}', [ProductionOrderController::class, 'show'])->name('show');
            Route::post('/{order}/completar', [ProductionOrderController::class, 'complete'])->name('complete');
            Route::post('/{order}/cancelar', [ProductionOrderController::class, 'cancel'])->name('cancel');
        });

        Route::middleware('permission:inventory.manage')->prefix('inventarios')->name('inventory.')->group(function () {
            Route::get('/', [InventoryCountController::class, 'index'])->name('index');
            Route::post('/', [InventoryCountController::class, 'store'])->name('store');
            Route::get('/{count}', [InventoryCountController::class, 'show'])->name('show');
            Route::put('/{count}', [InventoryCountController::class, 'update'])->name('update');
            Route::post('/{count}/aplicar', [InventoryCountController::class, 'apply'])->name('apply');
            Route::post('/{count}/cancelar', [InventoryCountController::class, 'cancel'])->name('cancel');
        });
    });

    // ---------- Ventas ----------
    Route::get('/pos', [PosController::class, 'index'])->name('pos')->middleware('permission:sales.create');
    Route::post('/pos', [PosController::class, 'store'])->name('pos.store')->middleware('permission:sales.create');
    Route::prefix('ventas')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index')->middleware('permission:sales.view');
        Route::get('/{sale}', [SaleController::class, 'show'])->name('show')->middleware('permission:sales.view');
        Route::get('/{sale}/ticket', [SaleController::class, 'ticket'])->name('ticket')->middleware('permission:sales.view|sales.create');
        Route::post('/{sale}/anular', [SaleController::class, 'cancel'])->name('cancel')->middleware('permission:sales.cancel');
        Route::post('/{sale}/devolucion', [SaleController::class, 'return'])->name('return')->middleware('permission:sales.return');
    });

    Route::prefix('clientes')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index')->middleware('permission:customers.view');
        Route::get('/buscar', [CustomerController::class, 'search'])->name('search')->middleware('permission:customers.view|sales.create');
        Route::middleware('permission:customers.manage')->group(function () {
            Route::post('/', [CustomerController::class, 'store'])->name('store');
            Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
            Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
            Route::post('/{customer}/pagos', [CustomerController::class, 'payment'])->name('payment');
            Route::post('/{customer}/ajuste', [CustomerController::class, 'adjust'])->name('adjust');
        });
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('show')->middleware('permission:customers.view');
    });

    // ---------- Compras y proveedores ----------
    Route::prefix('proveedores')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index')->middleware('permission:suppliers.view');
        Route::middleware('permission:suppliers.manage')->group(function () {
            Route::post('/', [SupplierController::class, 'store'])->name('store');
            Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update');
            Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
            Route::post('/{supplier}/pagos', [SupplierController::class, 'payment'])->name('payment');
            Route::put('/{supplier}/productos/{supplierProduct}', [SupplierController::class, 'updateProduct'])->name('products.update');
            Route::delete('/{supplier}/productos/{supplierProduct}', [SupplierController::class, 'destroyProduct'])->name('products.destroy');
            Route::post('/{supplier}/bonificaciones', [SupplierController::class, 'storeRule'])->name('rules.store');
            Route::delete('/{supplier}/bonificaciones/{rule}', [SupplierController::class, 'destroyRule'])->name('rules.destroy');
        });
        Route::get('/{supplier}', [SupplierController::class, 'show'])->name('show')->middleware('permission:suppliers.view');
    });

    Route::prefix('compras')->group(function () {
        Route::name('purchases.')->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])->name('index')->middleware('permission:purchases.view');
            Route::get('/nueva', [PurchaseController::class, 'create'])->name('create')->middleware('permission:purchases.manage');
            Route::post('/', [PurchaseController::class, 'store'])->name('store')->middleware('permission:purchases.manage');
            Route::get('/sugerencias', [PurchaseController::class, 'suggestions'])->name('suggestions')->middleware('permission:purchases.view');
            Route::post('/sugerencias/pedidos-automaticos', [PurchaseController::class, 'autoOrders'])->name('auto-orders')->middleware('permission:purchases.manage');
            Route::get('/{purchase}', [PurchaseController::class, 'show'])->name('show')->middleware('permission:purchases.view')->whereNumber('purchase');
            Route::post('/{purchase}/anular', [PurchaseController::class, 'cancel'])->name('cancel')->middleware('permission:purchases.manage');
        });
        Route::prefix('ordenes-de-pago')->name('payment-orders.')->group(function () {
            Route::get('/', [PaymentOrderController::class, 'index'])->name('index')->middleware('permission:suppliers.view');
            Route::get('/nueva', [PaymentOrderController::class, 'create'])->name('create')->middleware('permission:suppliers.manage');
            Route::post('/', [PaymentOrderController::class, 'store'])->name('store')->middleware('permission:suppliers.manage');
            Route::get('/{order}', [PaymentOrderController::class, 'show'])->name('show')->middleware('permission:suppliers.view');
            Route::post('/{order}/anular', [PaymentOrderController::class, 'cancel'])->name('cancel')->middleware('permission:suppliers.manage');
        });
        Route::prefix('pedidos')->name('purchase-orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])->name('index')->middleware('permission:purchases.view');
            Route::middleware('permission:purchases.manage')->group(function () {
                Route::get('/nuevo', [PurchaseOrderController::class, 'create'])->name('create');
                Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
                Route::post('/{order}/estado', [PurchaseOrderController::class, 'status'])->name('status');
            });
            Route::get('/{order}', [PurchaseOrderController::class, 'show'])->name('show')->middleware('permission:purchases.view');
            Route::get('/{order}/imprimir', [PurchaseOrderController::class, 'print'])->name('print')->middleware('permission:purchases.view');
        });
    });

    // ---------- Caja y gastos ----------
    Route::prefix('caja')->name('cash.')->middleware('permission:cash.operate|cash.manage')->group(function () {
        Route::get('/', [CashController::class, 'index'])->name('index');
        Route::post('/abrir', [CashController::class, 'open'])->name('open');
        Route::post('/{session}/cerrar', [CashController::class, 'close'])->name('close');
        Route::post('/{session}/movimiento', [CashController::class, 'movement'])->name('movement');
        Route::get('/historial', [CashController::class, 'history'])->name('history');
        Route::get('/{session}', [CashController::class, 'show'])->name('show');
    });
    Route::middleware('permission:expenses.manage')->group(function () {
        Route::get('/gastos', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/gastos', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::delete('/gastos/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    });

    // ---------- Reportes ----------
    Route::prefix('reportes')->name('reports.')->middleware('permission:reports.view')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/ventas', [ReportController::class, 'sales'])->name('sales');
        Route::get('/rentabilidad', [ReportController::class, 'profitability'])->name('profitability');
        Route::get('/stock-valorizado', [ReportController::class, 'valuation'])->name('valuation');
        Route::get('/sin-rotacion', [ReportController::class, 'noRotation'])->name('no-rotation');
        Route::get('/bajo-margen', [ReportController::class, 'lowMargin'])->name('low-margin');
        Route::get('/cuentas-corrientes', [ReportController::class, 'accounts'])->name('accounts');
        Route::get('/resultados', [ReportController::class, 'results'])->name('results');
    });

    // ---------- Configuración ----------
    Route::prefix('configuracion')->group(function () {
        Route::get('/empresa', [CompanySettingsController::class, 'edit'])->name('settings.company')->middleware('permission:settings.manage');
        Route::put('/empresa', [CompanySettingsController::class, 'update'])->name('settings.company.update')->middleware('permission:settings.manage');

        Route::middleware('permission:branches.manage')->group(function () {
            Route::get('/sucursales', [BranchController::class, 'index'])->name('branches.index');
            Route::post('/sucursales', [BranchController::class, 'store'])->name('branches.store');
            Route::put('/sucursales/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::post('/sucursales/{branch}/cajas', [BranchController::class, 'storeRegister'])->name('branches.registers.store');
            Route::put('/cajas/{register}', [BranchController::class, 'updateRegister'])->name('registers.update');
        });

        Route::middleware('permission:users.manage')->group(function () {
            Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
            Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
            Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::get('/auditoria', [AuditLogController::class, 'index'])->name('audit.index')->middleware('permission:audit.view');
    });

    // ---------- Administración SaaS (dueño de la plataforma) ----------
    Route::prefix('admin')->name('admin.')->middleware('super_admin')->group(function () {
        Route::get('/', [AdminTenantController::class, 'dashboard'])->name('dashboard');
        Route::get('/empresas', [AdminTenantController::class, 'index'])->name('tenants.index');
        Route::post('/empresas', [AdminTenantController::class, 'store'])->name('tenants.store');
        Route::put('/empresas/{tenant}', [AdminTenantController::class, 'update'])->name('tenants.update');
        Route::get('/planes', [AdminPlanController::class, 'index'])->name('plans.index');
        Route::post('/planes', [AdminPlanController::class, 'store'])->name('plans.store');
        Route::put('/planes/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');
    });
});
