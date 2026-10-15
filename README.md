# FerreteroSoft

Sistema de gestión para ferreterías, comercializable por suscripción (SaaS), multiempresa y multisucursal.
Hecho con **Laravel 13 + Vue 3 + Inertia 3 + MySQL + Tailwind 4**.

El foco está en lo que más tiempo le consume al ferretero: **actualizar precios cuando llega la lista del proveedor**.

---

## Instalación en Laragon (Windows)

Requisitos: Laragon con PHP 8.3+, MySQL, Composer, Node 20+ y Git (vienen con Laragon Full).

1. Abrí la **Terminal de Laragon** y entrá a la carpeta:
   ```bash
   cd C:\laragon\www\ferretero-soft
   ```
2. Creá la base de datos `ferretero_soft` (desde HeidiSQL, o con):
   ```bash
   mysql -u root -e "CREATE DATABASE ferretero_soft CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```
3. Instalá dependencias y prepará el proyecto:
   ```bash
   composer install
   copy .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   npm install
   npm run build
   ```
4. Recargá Laragon (**Menú → Recargar** o reiniciar Apache/Nginx) y abrí **http://ferretero-soft.test**
   (Laragon crea el dominio `.test` automáticamente apuntando a `/public`).

Para desarrollar con recarga en caliente usá `npm run dev` en una terminal aparte.

> Si tu MySQL tiene contraseña, editá `DB_PASSWORD` en `.env`.
> Extensiones PHP necesarias (activas por defecto en Laragon): `pdo_mysql`, `mbstring`, `intl`, `zip`, `gd`, `fileinfo`.
> Si `composer install` se queja de `ext-zip` o `ext-gd`, activalas en **Laragon → PHP → Extensions**.

### Cuentas de prueba (contraseña `password`)

| Email | Rol |
|---|---|
| `demo@ferreteria.com` | Administrador de "Ferretería El Tornillo" (2 sucursales, 62 productos, 60 días de ventas) |
| `encargado@ferreteria.com` | Encargado (Sucursal Norte) |
| `vendedor@ferreteria.com` | Vendedor (solo POS, clientes y caja) |
| `admin@ferreterosoft.com` | Dueño de la plataforma SaaS (empresas y planes) |

Para probar la actualización de precios: **Precios → Listas de proveedores**, elegí *Distribuidora Norte* y subí
`public/ejemplos/lista-distribuidora-norte.xlsx` (se genera con el seeder; trae subas, bajas, un aumento
sospechoso de +140 %, artículos nuevos y una fila sin precio).

---

## Módulos

| Módulo | Qué incluye |
|---|---|
| **Precios** (foco) | Importación de listas Excel/CSV de proveedores, mapeo de columnas con detección automática, bonificaciones en cascada, IVA incluido o no, cotización USD, unidades por bulto, vinculación automática (código de proveedor → código de barras → código interno) y manual que "aprende", alta de productos nuevos desde la lista, alertas de variación, revisión con distribución de subas/bajas, aplicación selectiva, historial y **reversión** de cada lote, actualización masiva por proveedor/categoría/marca, precios a revisar (margen real debajo del objetivo), comparativa de costos entre proveedores, etiquetas de góndola de lo que cambió |
| **Catálogo** | Productos con código interno, código de barras, códigos alternativos, códigos por proveedor, categorías jerárquicas con margen sugerido, marcas, unidades, venta fraccionada, unidad de compra con conversión (rollo 100 m → metros), listas de precio (minorista, mayorista, gremio) con precios especiales, importación/exportación Excel |
| **Variantes, kits y compuestos** | Productos modelo con variantes (ej. Látex exterior → Color × Envase) generadas automáticamente, cada una con su código, stock y precio. **Kits**: se venden como un artículo y descuentan el stock de cada componente. **Productos fabricados**: se producen con órdenes de producción. El costo de kits y fabricados se recalcula solo cuando cambia el costo de un componente |
| **Producción** | Órdenes de producción por sucursal: al completarlas se consumen los insumos y entra el producto terminado con su costo real. Lista de insumos necesarios para las órdenes pendientes con stock disponible y faltantes, exportable a Excel |
| **Punto de venta** | Lector de código de barras, búsqueda por palabras, cantidades fraccionadas, descuentos por ítem y general limitados por rol, cambio de precio con permiso, pagos combinados (efectivo, transferencia, débito, crédito, Mercado Pago, cheque, cuenta corriente), vuelto, presupuestos convertibles en venta, alta rápida de clientes, ticket 80 mm. Atajos: F2 abre el POS, F3 busca, F9 cobra |
| **Presupuestos** | Se arman en el punto de venta. Validez configurable (por defecto 7 días) y condiciones comerciales. Estados: vigente, vencido, convertido en venta, rechazado (con motivo). Al cobrarlo se respetan los precios y descuentos presupuestados mientras esté vigente, aunque el vendedor no tenga permiso para cambiar precios; si venció, se cobra a precio actual y se muestra qué cambió. Editar (mismo número), duplicar, reabrir, extender validez. PDF en A4, envío por WhatsApp y **link público** donde el cliente lo ve en el celular, lo descarga y responde "Acepto". Se registra cuándo se envió y cuándo lo abrió el cliente. Si se anula la venta, el presupuesto vuelve a quedar abierto |
| **Ventas** | Listado con filtros, anulación total (repone stock, devuelve dinero, revierte cuenta corriente), devoluciones parciales con reintegro en caja o crédito en cuenta |
| **Stock** | Stock por sucursal y por **depósito** (sucursales tipo depósito que no venden), mínimos/máximos con **alertas de reposición y sobrestock** (campana en la barra superior), ubicación, cada variación genera un movimiento con saldo, ajustes con motivo, faltantes, transferencias entre sucursales (envío → recepción con faltantes), inventario físico total o por categoría |
| **Compras** | Facturas de compra con conversión de unidades, bonificaciones, IVA, percepciones y prorrateo de flete en el costo, costo promedio ponderado y último costo, decisión sobre precios de venta, pedidos a proveedores con recepción parcial, **pedidos automáticos** (uno por proveedor según stock mínimo, descontando lo ya pedido), envío del pedido por **WhatsApp** (email: próximamente), sugerencias de compra por mínimo y rotación |
| **Proveedores** | Cuenta corriente, **órdenes de pago** con varios medios (efectivo, transferencia, cheque, e-cheq), retenciones e imputación a facturas pendientes, imprimibles; **condiciones especiales**: bonificaciones distintas por marca y/o rubro además de las generales del proveedor y las de cada artículo |
| **Caja** | Apertura/cierre por caja y usuario, ingresos/egresos/retiros, totales por medio de pago, efectivo esperado vs contado con diferencia, historial |
| **Cuentas corrientes** | Clientes con límite de crédito, vencimientos, imputación de pagos por antigüedad, ajustes; proveedores con deuda por compras y pagos |
| **Portal de clientes** | Link secreto por cliente (se habilita, regenera o deshabilita desde la ficha y se envía por WhatsApp): el cliente ve sin usuario ni contraseña su saldo, lo vencido y a vencer, los comprobantes pendientes de pago, todos sus comprobantes y sus pagos; descarga los tickets, los recibos de pago y el resumen de cuenta en PDF. Pensado para celular |
| **Reportes** | Ventas (día/mes/hora/vendedor/sucursal/medio de pago), rentabilidad (categoría/marca/proveedor/producto/vendedor), estado de resultados con gastos, stock valorizado, sin rotación, bajo margen, antigüedad de deuda, **compras** (por proveedor/rubro/marca/mes/producto, variación de costos, facturas impagas, compras vs costo de lo vendido), **presupuestos** (tasa de cierre por vendedor y cliente, qué se presupuesta y no se vende, motivos de rechazo, los que vencen esta semana), **clientes** (ranking por compras y ganancia, clasificación ABC, ticket promedio, frecuencia de compra, clientes nuevos, los que dejaron de comprar con contacto por WhatsApp, qué compra cada uno; exportable a Excel) |
| **SaaS** | Registro self-service con 15 días de prueba, planes con límites de sucursales y usuarios, suspensión, panel del dueño con MRR |
| **Seguridad** | Roles y permisos granulares (30 permisos), usuarios por sucursal, auditoría de cambios, aislamiento total entre empresas |

---

## Arquitectura

```
app/
├── Support/
│   ├── TenantContext.php      Empresa y sucursal activas del request
│   ├── Permissions.php        Catálogo de permisos y roles por defecto
│   ├── SpreadsheetReader.php  Lectura de XLSX/XLS/ODS/CSV + detección de encabezados
│   └── BusinessException.php  Errores de negocio → mensaje al usuario
├── Models/Concerns/
│   ├── BelongsToTenant.php    Scope global por tenant_id (aislamiento SaaS)
│   └── Auditable.php          Registro de altas/cambios/bajas
├── Services/                  Toda la lógica de negocio vive acá
│   ├── PricingService.php              Fórmulas de costo, precio, margen y redondeo
│   ├── SupplierPriceImportService.php  Flujo completo de listas de proveedores
│   ├── PriceUpdateService.php          Lotes de cambios auditados y reversibles
│   ├── StockService.php                Único punto que modifica stock
│   ├── SaleService.php · PurchaseService.php · CashService.php
│   ├── AccountService.php · InventoryService.php
│   ├── ProductImportService.php · TenantProvisioner.php
└── Http/
    ├── Middleware/SetTenantContext.php · EnsurePermission.php · EnsureSuperAdmin.php
    └── Controllers/           Delgados: validan y delegan en servicios
resources/js/
├── Layouts/AppLayout.vue      Menú lateral por permisos + selector de sucursal
├── Components/                Buscador de productos, modales, paginación, etc.
└── Pages/                     Una página Vue por pantalla (Inertia)
```

**Multiempresa**: base de datos única con `tenant_id` en cada tabla; un scope global filtra todas las consultas
y el route model binding devuelve 404 si alguien intenta acceder a un registro de otra empresa.

**Multisucursal**: stock, cajas, ventas y compras se registran por sucursal. Cada usuario trabaja en una sucursal
activa (selector arriba) y puede tener sucursales asignadas.

### Reglas de precios

```
costo neto  = precio lista × (1 − bonif1) × (1 − bonif2) × (1 − bonif3) × (1 + recargo) × cotización
              [÷ (1 + IVA) si la lista viene con IVA] ÷ unidades por bulto
precio      = costo × (1 + margen objetivo) × (1 + IVA)  → redondeado ($1, $5, $10, $50, $100…)
```

El **margen objetivo** se guarda en cada producto. Cuando cambia un costo, se decide qué hacer con el precio:

| Acción | Resultado |
|---|---|
| Mantener margen | Recalcula el precio |
| Mantener margen, nunca bajar | Sube si corresponde; si el costo baja, conserva el precio |
| Mantener precio / Solo costo | El precio no cambia; el producto aparece en **Precios a revisar** |
| Margen fijo | Aplica un margen nuevo a todos |

Si un artículo se compra a varios proveedores, la política de costo define si se toma siempre la última lista,
solo la del proveedor principal o el menor costo entre todos.

Cada aplicación (lista de proveedor, actualización masiva, compra, edición manual) crea un lote en
`price_updates` con el costo/precio anterior y nuevo de cada producto: se puede consultar, imprimir etiquetas
de ese lote y **revertir** (si ningún lote posterior tocó esos productos).

---

## Actualizar una instalación existente

Si ya tenías el sistema corriendo y recibís una versión nueva:

```bash
composer install
php artisan migrate            # aplica las tablas nuevas conservando tus datos
# o, para empezar de cero con los datos demo actualizados:
php artisan migrate:fresh --seed
```

## Tests

Usan MySQL (las consultas usan funciones de MySQL). Creá la base `ferretero_test` y ejecutá:

```bash
mysql -u root -e "CREATE DATABASE ferretero_test"
php artisan test
```

Cubren el flujo completo de listas de proveedores (subir, procesar, vincular, dar de alta, aplicar, revertir),
reglas de precios, actualización masiva, ventas (caja, fraccionado, descuentos por rol, cuenta corriente,
anulación, devolución), compras con conversión de unidades y flete, aislamiento entre empresas y que todas las
pantallas carguen.

---

## Próximos pasos sugeridos (Fase 3 y 4 del roadmap)

- Facturación electrónica ARCA (WSFE): la venta ya guarda tipo de comprobante, IVA por ítem y datos fiscales del cliente.
- Notas de crédito electrónicas a partir de devoluciones.
- Catálogo online / pedidos por WhatsApp y API pública.
- Integración con balanzas y Mercado Libre.
- Lectura automática de facturas de proveedores (PDF) para cargar compras.
- Cobro de la suscripción (Mercado Pago suscripciones) para el panel SaaS.
