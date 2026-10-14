<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashService;
use App\Services\PricingService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Empresa demo: "Ferretería El Tornillo" con 2 sucursales, proveedores, ~70 productos y 60 días de ventas.
 * Login: demo@ferreteria.com / password
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'demo@ferreteria.com')->exists()) {
            return;
        }
        mt_srand(42);

        $ctx = app(TenantContext::class);
        $admin = app(TenantProvisioner::class)->provision(
            ['name' => 'Ferretería El Tornillo', 'cuit' => '30-71234567-8', 'address' => 'Av. San Martín 1234, Córdoba', 'phone' => '351 555-1234', 'status' => 'active', 'branch_name' => 'Casa Central'],
            ['name' => 'Juan Pérez', 'email' => 'demo@ferreteria.com', 'password' => 'password'],
            Plan::where('slug', 'multi')->first(),
        );
        $tenant = $admin->tenant;
        $tenant->update(['legal_name' => 'El Tornillo S.R.L.', 'tax_condition' => 'Responsable Inscripto']);
        $main = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $ctx->set($tenant, $main);
        Auth::setUser($admin);

        $sucursal2 = Branch::create(['name' => 'Sucursal Norte', 'code' => '0002', 'address' => 'Av. Colón 4500']);
        CashRegister::create(['branch_id' => $sucursal2->id, 'name' => 'Caja 1']);

        $roles = Role::pluck('id', 'name');
        $vendedor = User::create(['tenant_id' => $tenant->id, 'role_id' => $roles['Vendedor'], 'current_branch_id' => $main->id, 'name' => 'María Gómez', 'email' => 'vendedor@ferreteria.com', 'password' => 'password']);
        $vendedor->branches()->sync([$main->id]);
        $encargado = User::create(['tenant_id' => $tenant->id, 'role_id' => $roles['Encargado'], 'current_branch_id' => $sucursal2->id, 'name' => 'Carlos Ruiz', 'email' => 'encargado@ferreteria.com', 'password' => 'password']);

        // ---------- Catálogo ----------
        $cats = [];
        foreach (['Bulonería' => 60, 'Electricidad' => 45, 'Plomería' => 45, 'Pinturería' => 35, 'Herramientas manuales' => 50, 'Herramientas eléctricas' => 30, 'Construcción' => 30, 'Jardín' => 45] as $name => $mk) {
            $cats[$name] = Category::create(['name' => $name, 'default_markup' => $mk]);
        }
        $brands = collect(['Fischer', 'Pirelli', 'Tigre', 'Alba', 'Stanley', 'Bosch', 'Loma Negra', 'Tramontina', 'Black+Decker', 'Genérico'])
            ->mapWithKeys(fn ($b) => [$b => Brand::create(['name' => $b])]);
        $units = Unit::pluck('id', 'abbreviation');

        $suppliers = [
            'Distribuidora Norte' => Supplier::create(['name' => 'Distribuidora Norte', 'cuit' => '30-70111222-3', 'contact_name' => 'Roberto', 'phone' => '351 444-0001', 'payment_term_days' => 30, 'discount_1' => 10, 'discount_2' => 5, 'tax_condition' => 'Responsable Inscripto']),
            'Electro Mayorista' => Supplier::create(['name' => 'Electro Mayorista', 'cuit' => '30-70333444-5', 'contact_name' => 'Laura', 'phone' => '351 444-0002', 'payment_term_days' => 15, 'discount_1' => 8, 'tax_condition' => 'Responsable Inscripto']),
            'Pinturerías Unidas' => Supplier::create(['name' => 'Pinturerías Unidas', 'cuit' => '30-70555666-7', 'phone' => '351 444-0003', 'payment_term_days' => 30, 'discount_1' => 15, 'prices_include_vat' => true, 'tax_condition' => 'Responsable Inscripto']),
            'Herramientas del Centro' => Supplier::create(['name' => 'Herramientas del Centro', 'cuit' => '30-70777888-9', 'phone' => '351 444-0004', 'payment_term_days' => 45, 'discount_1' => 12, 'tax_condition' => 'Responsable Inscripto']),
        ];

        // [código, nombre, categoría, marca, unidad, proveedor, cod.prov, precio lista proveedor, fracc, unidad compra, factor]
        $items = [
            ['TOR-6X40', 'Tornillo autoperforante 6x40 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'DN-1001', 28, false],
            ['TOR-8X50', 'Tornillo autoperforante 8x50 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'DN-1002', 41, false],
            ['TAR-6', 'Tarugo de nylon 6 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'DN-1010', 9, false],
            ['TAR-8', 'Tarugo de nylon 8 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'DN-1011', 13, false],
            ['TAR-10', 'Tarugo de nylon 10 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'DN-1012', 19, false],
            ['BUL-1/4', 'Bulón cabeza hexagonal 1/4 x 2"', 'Bulonería', 'Genérico', 'un', 'Distribuidora Norte', 'DN-1020', 95, false],
            ['BUL-3/8', 'Bulón cabeza hexagonal 3/8 x 3"', 'Bulonería', 'Genérico', 'un', 'Distribuidora Norte', 'DN-1021', 210, false],
            ['ARA-6', 'Arandela plana 6 mm', 'Bulonería', 'Genérico', 'un', 'Distribuidora Norte', 'DN-1030', 6, false],
            ['CLA-2', 'Clavo punta paris 2" (kg)', 'Bulonería', 'Genérico', 'kg', 'Distribuidora Norte', 'DN-1040', 3400, true],
            ['CLA-3', 'Clavo punta paris 3" (kg)', 'Bulonería', 'Genérico', 'kg', 'Distribuidora Norte', 'DN-1041', 3300, true],
            ['CAB-1.5', 'Cable unipolar 1,5 mm', 'Electricidad', 'Pirelli', 'm', 'Electro Mayorista', 'EM-UNI15', 42000, true, 'Rollo x100 m', 100],
            ['CAB-2.5', 'Cable unipolar 2,5 mm', 'Electricidad', 'Pirelli', 'm', 'Electro Mayorista', 'EM-UNI25', 65000, true, 'Rollo x100 m', 100],
            ['CAB-4', 'Cable unipolar 4 mm', 'Electricidad', 'Pirelli', 'm', 'Electro Mayorista', 'EM-UNI40', 98000, true, 'Rollo x100 m', 100],
            ['TAP-2X1', 'Cable taller 2x1 mm', 'Electricidad', 'Pirelli', 'm', 'Electro Mayorista', 'EM-TAP21', 71000, true, 'Rollo x100 m', 100],
            ['TOM-DOB', 'Toma corriente doble 10A', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-2001', 2100, false],
            ['LLA-PUN', 'Llave de punto simple', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-2002', 1850, false],
            ['LAM-LED9', 'Lámpara LED 9W luz fría', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-3001', 1400, false],
            ['LAM-LED15', 'Lámpara LED 15W luz cálida', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-3002', 2300, false],
            ['TER-2X16', 'Termomagnética 2x16A', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-4001', 8900, false],
            ['DIS-2X25', 'Disyuntor diferencial 2x25A', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-4002', 24500, false],
            ['CIN-AIS', 'Cinta aisladora 20 m', 'Electricidad', 'Genérico', 'un', 'Electro Mayorista', 'EM-5001', 900, false],
            ['CAÑ-PVC20', 'Caño termofusión 20 mm', 'Plomería', 'Tigre', 'm', 'Distribuidora Norte', 'DN-3001', 9800, true, 'Barra x4 m', 4],
            ['CAÑ-PVC25', 'Caño termofusión 25 mm', 'Plomería', 'Tigre', 'm', 'Distribuidora Norte', 'DN-3002', 14500, true, 'Barra x4 m', 4],
            ['COD-20', 'Codo termofusión 20 mm', 'Plomería', 'Tigre', 'un', 'Distribuidora Norte', 'DN-3010', 380, false],
            ['TEE-20', 'Te termofusión 20 mm', 'Plomería', 'Tigre', 'un', 'Distribuidora Norte', 'DN-3011', 460, false],
            ['LLP-20', 'Llave de paso 20 mm', 'Plomería', 'Tigre', 'un', 'Distribuidora Norte', 'DN-3020', 6200, false],
            ['CAN-110', 'Caño PVC cloacal 110 mm', 'Plomería', 'Tigre', 'm', 'Distribuidora Norte', 'DN-3030', 21000, true, 'Barra x4 m', 4],
            ['TEF-ROL', 'Cinta teflón 3/4"', 'Plomería', 'Genérico', 'un', 'Distribuidora Norte', 'DN-3040', 450, false],
            ['SIL-ACE', 'Sellador silicona acética 280 ml', 'Plomería', 'Genérico', 'un', 'Distribuidora Norte', 'DN-3050', 3100, false],
            ['LAT-BL4', 'Látex interior blanco 4 L', 'Pinturería', 'Alba', 'un', 'Pinturerías Unidas', 'PU-L04', 21500, false],
            ['LAT-BL10', 'Látex interior blanco 10 L', 'Pinturería', 'Alba', 'un', 'Pinturerías Unidas', 'PU-L10', 48900, false],
            ['LAT-BL20', 'Látex interior blanco 20 L (fraccionable)', 'Pinturería', 'Alba', 'l', 'Pinturerías Unidas', 'PU-L20', 89000, true, 'Lata 20 L', 20],
            ['ESM-NE1', 'Esmalte sintético negro 1 L', 'Pinturería', 'Alba', 'un', 'Pinturerías Unidas', 'PU-E01', 14200, false],
            ['ESM-BL1', 'Esmalte sintético blanco 1 L', 'Pinturería', 'Alba', 'un', 'Pinturerías Unidas', 'PU-E02', 14200, false],
            ['RODI-22', 'Rodillo lana 22 cm', 'Pinturería', 'Genérico', 'un', 'Pinturerías Unidas', 'PU-R22', 5600, false],
            ['PIN-2', 'Pincel N°20', 'Pinturería', 'Genérico', 'un', 'Pinturerías Unidas', 'PU-P20', 2300, false],
            ['DIL-1', 'Diluyente aguarrás 1 L', 'Pinturería', 'Genérico', 'un', 'Pinturerías Unidas', 'PU-D01', 4100, false],
            ['LIJ-120', 'Lija al agua grano 120', 'Pinturería', 'Genérico', 'un', 'Pinturerías Unidas', 'PU-L120', 650, false],
            ['MAR-500', 'Martillo carpintero 500 g', 'Herramientas manuales', 'Stanley', 'un', 'Herramientas del Centro', 'HC-M500', 12500, false],
            ['DES-PH2', 'Destornillador Phillips PH2', 'Herramientas manuales', 'Stanley', 'un', 'Herramientas del Centro', 'HC-D02', 4200, false],
            ['DES-PL6', 'Destornillador plano 6 mm', 'Herramientas manuales', 'Stanley', 'un', 'Herramientas del Centro', 'HC-D06', 3900, false],
            ['PIN-UNI8', 'Pinza universal 8"', 'Herramientas manuales', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-P08', 11800, false],
            ['LLA-AJ10', 'Llave ajustable 10"', 'Herramientas manuales', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-LA10', 15600, false],
            ['CIN-5M', 'Cinta métrica 5 m', 'Herramientas manuales', 'Stanley', 'un', 'Herramientas del Centro', 'HC-CM5', 7800, false],
            ['NIV-40', 'Nivel de aluminio 40 cm', 'Herramientas manuales', 'Stanley', 'un', 'Herramientas del Centro', 'HC-N40', 9900, false],
            ['SER-ARC', 'Sierra de arco con hoja', 'Herramientas manuales', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-SA', 13400, false],
            ['TAL-650', 'Taladro percutor 650 W', 'Herramientas eléctricas', 'Bosch', 'un', 'Herramientas del Centro', 'HC-T650', 98000, false],
            ['AMO-115', 'Amoladora angular 115 mm 850 W', 'Herramientas eléctricas', 'Bosch', 'un', 'Herramientas del Centro', 'HC-A115', 112000, false],
            ['ATO-12V', 'Atornillador inalámbrico 12 V', 'Herramientas eléctricas', 'Black+Decker', 'un', 'Herramientas del Centro', 'HC-AT12', 85000, false],
            ['DIS-CORTE', 'Disco de corte metal 115 mm', 'Herramientas eléctricas', 'Bosch', 'un', 'Herramientas del Centro', 'HC-DC115', 1600, false],
            ['MEC-6', 'Mecha widia 6 mm', 'Herramientas eléctricas', 'Bosch', 'un', 'Herramientas del Centro', 'HC-MW6', 2900, false],
            ['MEC-8', 'Mecha widia 8 mm', 'Herramientas eléctricas', 'Bosch', 'un', 'Herramientas del Centro', 'HC-MW8', 3600, false],
            ['CEM-50', 'Cemento portland 50 kg', 'Construcción', 'Loma Negra', 'un', 'Distribuidora Norte', 'DN-5001', 9800, false],
            ['CAL-25', 'Cal hidratada 25 kg', 'Construcción', 'Loma Negra', 'un', 'Distribuidora Norte', 'DN-5002', 5200, false],
            ['PEG-30', 'Pegamento cerámico 30 kg', 'Construcción', 'Genérico', 'un', 'Distribuidora Norte', 'DN-5003', 8900, false],
            ['HIE-8', 'Hierro aletado 8 mm x 12 m', 'Construcción', 'Genérico', 'un', 'Distribuidora Norte', 'DN-5010', 11500, false],
            ['ALA-NEG', 'Alambre negro recocido N°16 (kg)', 'Construcción', 'Genérico', 'kg', 'Distribuidora Norte', 'DN-5020', 3900, true],
            ['MAN-1/2', 'Manguera de jardín 1/2" (m)', 'Jardín', 'Genérico', 'm', 'Distribuidora Norte', 'DN-6001', 32000, true, 'Rollo x25 m', 25],
            ['PAL-PUN', 'Pala punta corazón', 'Jardín', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-PP', 16500, false],
            ['RAS-14', 'Rastrillo 14 dientes', 'Jardín', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-R14', 9800, false],
            ['TIJ-POD', 'Tijera de podar', 'Jardín', 'Tramontina', 'un', 'Herramientas del Centro', 'HC-TP', 10900, false],
            ['ASP-REG', 'Aspersor de riego giratorio', 'Jardín', 'Genérico', 'un', 'Distribuidora Norte', 'DN-6010', 4500, false],
        ];

        $pricing = app(PricingService::class);
        $stock = app(StockService::class);
        $products = [];
        DB::transaction(function () use ($items, $cats, $brands, $units, $suppliers, $pricing, $stock, $main, $sucursal2, &$products) {
            $i = 0;
            foreach ($items as $it) {
                [$code, $name, $cat, $brand, $unit, $sup, $supCode, $listPrice, $fraction] = $it;
                $purchaseUnit = $it[9] ?? null;
                $factor = $it[10] ?? 1;
                $supplier = $suppliers[$sup];
                $cost = $pricing->supplierCost($listPrice, $supplier->discounts(), $factor, (bool) $supplier->prices_include_vat, 21);
                $markup = (float) $cats[$cat]->default_markup;
                $p = Product::create([
                    'code' => $code,
                    'barcode' => '779'.str_pad((string) (100000000 + $i * 7919), 10, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'category_id' => $cats[$cat]->id,
                    'brand_id' => $brands[$brand]->id,
                    'unit_id' => $units[$unit] ?? $units['un'],
                    'supplier_id' => $supplier->id,
                    'cost' => $cost, 'average_cost' => $cost, 'last_purchase_cost' => $cost,
                    'vat_rate' => 21, 'markup' => $markup,
                    'price' => $pricing->priceFromCost($cost, $markup, 21),
                    'allow_fraction' => $fraction,
                    'purchase_unit_name' => $purchaseUnit,
                    'purchase_unit_factor' => $factor,
                    'min_stock' => $fraction ? 50 : mt_rand(3, 20),
                    'location' => 'Pasillo '.mt_rand(1, 6).' - Estante '.chr(65 + mt_rand(0, 4)),
                    'cost_updated_at' => now()->subDays(40), 'price_updated_at' => now()->subDays(40),
                ]);
                SupplierProduct::create([
                    'supplier_id' => $supplier->id, 'product_id' => $p->id, 'supplier_code' => $supCode,
                    'supplier_description' => mb_strtoupper($name), 'list_price' => $listPrice, 'pack_quantity' => $factor,
                    'cost' => $cost, 'is_preferred' => true, 'last_price_at' => now()->subDays(40),
                ]);
                $qty = $fraction ? mt_rand(80, 400) : mt_rand(0, 60);
                $stock->move($p, $main->id, $qty, 'initial', null, $cost, 'Stock inicial demo');
                $stock->move($p, $sucursal2->id, (int) ($qty / 2), 'initial', null, $cost, 'Stock inicial demo');
                $products[] = $p;
                $i++;
            }
            // Segundo proveedor para algunos artículos (comparativa de costos)
            foreach (array_slice($products, 0, 10) as $p) {
                $alt = $suppliers['Herramientas del Centro'];
                SupplierProduct::create([
                    'supplier_id' => $alt->id, 'product_id' => $p->id, 'supplier_code' => 'ALT-'.$p->code,
                    'list_price' => round((float) $p->cost * 1.05 / 0.88, 2), 'cost' => round((float) $p->cost * (mt_rand(0, 1) ? 0.95 : 1.07), 4), 'last_price_at' => now()->subDays(10),
                ]);
            }
        });

        // ---------- Clientes ----------
        Customer::create(['name' => 'Consumidor Final', 'document_type' => 'DNI', 'tax_condition' => 'Consumidor Final']);
        $customers = [
            Customer::create(['name' => 'Constructora Delta S.A.', 'document_type' => 'CUIT', 'document_number' => '30-71555000-1', 'tax_condition' => 'Responsable Inscripto', 'allow_credit' => true, 'credit_limit' => 1500000, 'price_list_id' => \App\Models\PriceList::where('name', 'Mayorista')->value('id'), 'phone' => '351 600-1111']),
            Customer::create(['name' => 'Electricista Martín López', 'document_type' => 'CUIT', 'document_number' => '20-28999111-4', 'tax_condition' => 'Monotributo', 'allow_credit' => true, 'credit_limit' => 300000, 'price_list_id' => \App\Models\PriceList::where('name', 'Gremio / Profesionales')->value('id'), 'phone' => '351 600-2222']),
            Customer::create(['name' => 'Ana Fernández', 'document_type' => 'DNI', 'document_number' => '30111222', 'tax_condition' => 'Consumidor Final', 'phone' => '351 600-3333']),
        ];

        // ---------- Historial de ventas (60 días) ----------
        $register = CashRegister::where('branch_id', $main->id)->first();
        $cash = app(CashService::class);
        $sales = app(SaleService::class);
        $base = now()->copy();
        for ($d = 60; $d >= 1; $d--) {
            $day = $base->copy()->subDays($d)->setTime(9, 0);
            if ($day->isSunday()) {
                continue;
            }
            \Illuminate\Support\Carbon::setTestNow($day);
            $session = $cash->open($register, $admin, 50000);
            $n = mt_rand(4, 12);
            for ($s = 0; $s < $n; $s++) {
                \Illuminate\Support\Carbon::setTestNow($day->copy()->addMinutes(30 + $s * 40));
                $lines = [];
                foreach ((array) array_rand($products, mt_rand(1, 4)) as $idx) {
                    $p = $products[$idx];
                    $lines[] = ['product_id' => $p->id, 'quantity' => $p->allow_fraction ? mt_rand(2, 25) : mt_rand(1, 6)];
                }
                $customer = mt_rand(1, 10) > 8 ? $customers[mt_rand(0, 1)] : null;
                try {
                    $preview = $this->estimate($lines, $customer);
                    $payments = $customer && mt_rand(0, 1)
                        ? [['method' => 'account', 'amount' => $preview]]
                        : [['method' => ['cash', 'cash', 'transfer', 'debit_card', 'mercadopago'][mt_rand(0, 4)], 'amount' => $preview]];
                    $sales->create(['type' => 'sale', 'customer_id' => $customer?->id, 'items' => $lines, 'payments' => $payments], $admin);
                } catch (\Throwable $e) {
                    if (! $e instanceof \App\Support\BusinessException) { throw $e; }
                }
            }
            \Illuminate\Support\Carbon::setTestNow($day->copy()->setTime(20, 0));
            $cash->close($session, $cash->expectedCash($session) + [0, 0, 0, -500, 200][mt_rand(0, 4)]);
        }
        \Illuminate\Support\Carbon::setTestNow();

        // Gastos operativos de los últimos 3 meses
        $expCats = \App\Models\ExpenseCategory::pluck('id', 'name');
        foreach ([2, 1, 0] as $m) {
            $d = $base->copy()->subMonthsNoOverflow($m)->startOfMonth()->addDays(4)->toDateString();
            foreach ([['Alquiler', 'Alquiler del local', 850000], ['Sueldos', 'Sueldos del personal', 1900000], ['Servicios', 'Luz, agua e internet', 145000], ['Impuestos', 'Ingresos brutos', 210000]] as [$c, $desc, $amount]) {
                \App\Models\Expense::create(['branch_id' => $main->id, 'expense_category_id' => $expCats[$c], 'user_id' => $admin->id, 'date' => $d, 'description' => $desc, 'amount' => $amount * (1 + $m * -0.03), 'payment_method' => 'transfer']);
            }
        }

        $this->seedAdvanced($main, $suppliers, $cats, $brands, $units, $admin);

        $this->writeSampleSupplierList($suppliers['Distribuidora Norte']);
    }

    private function estimate(array $lines, ?Customer $customer): float
    {
        $pricing = app(PricingService::class);
        $list = $customer?->priceList ?? \App\Models\PriceList::where('is_default', true)->first();
        $total = 0;
        foreach ($lines as $l) {
            $p = Product::with('prices')->find($l['product_id']);
            $total += round($l['quantity'] * $pricing->priceFor($p, $list), 2);
        }

        return round($total, 2);
    }

    /**
     * Genera una lista de precios de ejemplo del proveedor (con aumentos, bajas, artículos nuevos y errores)
     * para probar el módulo de actualización de precios.
     */
    private function writeSampleSupplierList(Supplier $supplier): void
    {
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Lista Octubre');
        $ws->fromArray(['DISTRIBUIDORA NORTE - LISTA DE PRECIOS OCTUBRE (precios netos sin IVA)'], null, 'A1');
        $ws->fromArray(['Código', 'Descripción', 'Marca', 'Precio Lista', 'Unidades x bulto'], null, 'A3');
        $row = 4;
        $sps = SupplierProduct::where('supplier_id', $supplier->id)->with('product.brand')->orderBy('supplier_code')->get();
        foreach ($sps as $i => $sp) {
            // la mayoría sube entre 4% y 12%, algunos bajan, uno con aumento sospechoso
            $factor = match (true) {
                $i === 3 => 2.4,
                $i % 9 === 0 => 0.95,
                $i % 7 === 0 => 1.0,
                default => 1 + mt_rand(40, 120) / 1000,
            };
            $ws->fromArray([$sp->supplier_code, $sp->supplier_description, $sp->product?->brand?->name, round((float) $sp->list_price * $factor, 2), (float) $sp->pack_quantity > 1 ? (float) $sp->pack_quantity : null], null, 'A'.$row++);
        }
        $ws->fromArray(['RUBRO: NOVEDADES'], null, 'A'.$row++);
        $ws->fromArray(['DN-9001', 'TORNILLO DRYWALL 6X1 1/4 (x100)', 'Fischer', 4200, null], null, 'A'.$row++);
        $ws->fromArray(['DN-9002', 'TACO FISCHER S6 CON TORNILLO (x50)', 'Fischer', 6800, null], null, 'A'.$row++);
        $ws->fromArray(['DN-9003', 'ESPUMA POLIURETANO 500ML', 'Genérico', 7300, null], null, 'A'.$row++);
        $ws->fromArray(['DN-9004', 'PRODUCTO SIN PRECIO', 'Genérico', 'consultar', null], null, 'A'.$row++);
        foreach (range('A', 'E') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $dir = public_path('ejemplos');
        @mkdir($dir, 0775, true);
        (new Xlsx($sheet))->save($dir.'/lista-distribuidora-norte.xlsx');
    }

    /** Depósito, kit, producto fabricado, variantes, bonificación por marca, compras con orden de pago y producción */
    private function seedAdvanced(Branch $main, array $suppliers, array $cats, $brands, $units, User $admin): void
    {
        $pricing = app(PricingService::class);
        $stock = app(StockService::class);
        $structure = app(\App\Services\ProductStructureService::class);

        Branch::create(['name' => 'Depósito Central', 'code' => 'DEP1', 'type' => 'warehouse', 'address' => 'Ruta 9 km 695']);

        $mk = function (array $a, float $qty = 0) use ($pricing, $stock, $main) {
            $cost = $a['cost'];
            $p = Product::create($a + ['average_cost' => $cost, 'vat_rate' => 21, 'price' => $pricing->priceFromCost($cost, $a['markup'], 21),
                'cost_updated_at' => now(), 'price_updated_at' => now()]);
            if ($qty) {
                $stock->move($p, $main->id, $qty, 'initial', null, $cost, 'Stock inicial demo');
            }

            return $p;
        };
        $el = $cats['Electricidad']->id;
        $ficha = $mk(['code' => 'FIC-MAC', 'name' => 'Ficha macho 10A', 'category_id' => $el, 'unit_id' => $units['un'], 'supplier_id' => $suppliers['Electro Mayorista']->id, 'cost' => 620, 'markup' => 45, 'min_stock' => 10], 40);
        $hembra = $mk(['code' => 'TOM-HEM', 'name' => 'Toma hembra de prolongación 10A', 'category_id' => $el, 'unit_id' => $units['un'], 'supplier_id' => $suppliers['Electro Mayorista']->id, 'cost' => 780, 'markup' => 45, 'min_stock' => 10], 4);

        // Producto fabricado: alargue armado en el local
        $taller = Product::where('code', 'TAP-2X1')->first();
        $alargue = $mk(['code' => 'ALG-5M', 'name' => 'Alargue armado 5 m (cable taller 2x1)', 'type' => 'manufactured', 'category_id' => $el, 'unit_id' => $units['un'], 'cost' => 0, 'markup' => 60, 'min_stock' => 3], 0);
        $structure->syncComponents($alargue, [['component_id' => $taller->id, 'quantity' => 5], ['component_id' => $ficha->id, 'quantity' => 1], ['component_id' => $hembra->id, 'quantity' => 1]]);
        $c = $structure->componentsCost($alargue->fresh());
        $alargue->update(['cost' => $c, 'average_cost' => $c, 'price' => $pricing->priceFromCost($c, 60, 21)]);
        \App\Models\ProductionOrder::create(['branch_id' => $main->id, 'product_id' => $alargue->id, 'user_id' => $admin->id, 'number' => 1, 'quantity' => 6,
            'due_date' => now()->addDays(2)->toDateString(), 'status' => 'pending', 'notes' => 'Para la vidriera']);

        // Kit: se vende como una unidad y descuenta cada componente
        $kit = $mk(['code' => 'KIT-TOMA', 'name' => 'Kit instalación de toma (toma + llave + cinta)', 'type' => 'kit', 'category_id' => $el, 'cost' => 0, 'markup' => 35, 'track_stock' => false], 0);
        $structure->syncComponents($kit, [
            ['component_id' => Product::where('code', 'TOM-DOB')->value('id'), 'quantity' => 1],
            ['component_id' => Product::where('code', 'LLA-PUN')->value('id'), 'quantity' => 1],
            ['component_id' => Product::where('code', 'CIN-AIS')->value('id'), 'quantity' => 1],
        ]);
        $c = $structure->componentsCost($kit->fresh());
        $kit->update(['cost' => $c, 'average_cost' => $c, 'price' => $pricing->priceFromCost($c, 35, 21)]);

        // Variantes: un modelo con color y envase
        $latex = $mk(['code' => 'LAT-EXT', 'name' => 'Látex exterior', 'category_id' => $cats['Pinturería']->id, 'brand_id' => $brands['Alba']->id, 'unit_id' => $units['un'],
            'supplier_id' => $suppliers['Pinturerías Unidas']->id, 'cost' => 18000, 'markup' => 35, 'min_stock' => 2], 0);
        $structure->generateVariants($latex, ['Color' => ['Blanco', 'Gris', 'Verde'], 'Envase' => ['4 L', '10 L']]);
        foreach ($latex->variants()->get() as $i => $v) {
            if (str_contains($v->name, '10 L')) {
                $v->update(['cost' => 41000, 'price' => $pricing->priceFromCost(41000, 35, 21)]);
            }
            $stock->move($v, $main->id, [6, 3, 2, 1, 4, 0][$i] ?? 2, 'initial', null, (float) $v->cost, 'Stock inicial demo');
        }

        // Bonificación especial: Distribuidora Norte da 15% extra en Fischer
        \App\Models\SupplierDiscountRule::create(['supplier_id' => $suppliers['Distribuidora Norte']->id, 'brand_id' => $brands['Fischer']->id,
            'discount_1' => 15, 'discount_2' => 5, 'notes' => 'Acuerdo anual Fischer']);

        // Máximos de stock (para la alerta de sobrestock)
        ProductStock::where('branch_id', $main->id)->whereIn('product_id', Product::whereIn('code', ['TAR-6', 'ARA-6', 'TOR-6X40'])->pluck('id'))->update(['max_stock' => 20]);

        // Compras a Electro Mayorista y una orden de pago parcial
        $ps = app(\App\Services\PurchaseService::class);
        $buy = fn ($days, $num, $code, $qty) => $ps->create([
            'supplier_id' => $suppliers['Electro Mayorista']->id, 'voucher_type' => 'FA', 'voucher_number' => $num,
            'date' => now()->subDays($days)->toDateString(), 'due_date' => now()->subDays($days - 15)->toDateString(),
            'update_costs' => true, 'price_action' => 'keep_markup',
            'items' => [['product_id' => Product::where('code', $code)->value('id'), 'quantity' => $qty, 'unit_factor' => 1,
                'unit_cost' => (float) Product::where('code', $code)->value('cost'), 'discount_percent' => 0, 'vat_rate' => 21]],
        ]);
        $buy(40, '0003-00011020', 'LAM-LED9', 50);
        $buy(12, '0003-00011388', 'TER-2X16', 10);
        app(\App\Services\PaymentOrderService::class)->create([
            'supplier_id' => $suppliers['Electro Mayorista']->id, 'date' => now()->subDays(5)->toDateString(),
            'lines' => [['method' => 'transfer', 'amount' => 60000, 'reference' => 'TRF 88213', 'bank' => 'Banco Nación'],
                ['method' => 'check', 'amount' => 25000, 'reference' => '00451238', 'bank' => 'Banco Galicia', 'check_date' => now()->addDays(25)->toDateString()]],
        ]);
    }
}
