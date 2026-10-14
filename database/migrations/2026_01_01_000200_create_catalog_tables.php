<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo: categorías, marcas, unidades, listas de precio, productos, códigos alternativos y stock por sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->decimal('default_markup', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('abbreviation', 10);
            $table->boolean('allows_decimals')->default(false);
            $table->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Ajuste relativo al precio base minorista (ej: -10 = mayorista 10% menos)
            $table->decimal('adjustment_percent', 8, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('cuit', 20)->nullable();
            $table->string('tax_condition', 40)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->unsignedInteger('payment_term_days')->default(0);
            // Bonificaciones habituales en cascada sobre el precio de lista del proveedor
            $table->decimal('discount_1', 6, 2)->default(0);
            $table->decimal('discount_2', 6, 2)->default(0);
            $table->decimal('discount_3', 6, 2)->default(0);
            $table->boolean('prices_include_vat')->default(false);
            $table->decimal('default_markup', 8, 2)->nullable();
            $table->json('import_mapping')->nullable(); // última configuración de columnas usada
            $table->decimal('balance', 15, 2)->default(0); // deuda con el proveedor
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // Costos netos (sin IVA) por unidad de stock
            $table->decimal('cost', 15, 4)->default(0);            // costo de reposición vigente
            $table->decimal('average_cost', 15, 4)->default(0);    // promedio ponderado
            $table->decimal('last_purchase_cost', 15, 4)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(21);
            $table->decimal('markup', 8, 2)->default(40);          // % de ganancia sobre costo
            $table->decimal('price', 15, 2)->default(0);           // precio final minorista con IVA
            $table->timestamp('cost_updated_at')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->boolean('allow_fraction')->default(false);
            $table->string('purchase_unit_name', 30)->nullable();  // Ej: Rollo, Caja, Barra
            $table->decimal('purchase_unit_factor', 15, 4)->default(1); // Ej: rollo = 100 metros
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->string('location', 60)->nullable();
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('type', 20)->default('barcode'); // barcode, alternative
            $table->timestamps();
            $table->index(['tenant_id', 'code']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestamps();
            $table->unique(['product_id', 'price_list_id']);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reserved', 15, 3)->default(0);
            $table->decimal('min_stock', 15, 3)->nullable();
            $table->decimal('max_stock', 15, 3)->nullable();
            $table->string('location', 60)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code', 60)->nullable();
            $table->string('supplier_description')->nullable();
            $table->decimal('list_price', 15, 4)->default(0);   // precio de lista del proveedor (por su unidad)
            $table->decimal('discount_1', 6, 2)->nullable();    // null = usa la bonificación del proveedor
            $table->decimal('discount_2', 6, 2)->nullable();
            $table->decimal('discount_3', 6, 2)->nullable();
            $table->decimal('pack_quantity', 15, 4)->default(1); // unidades de stock por unidad del proveedor
            $table->decimal('cost', 15, 4)->default(0);         // costo neto calculado por unidad de stock
            $table->boolean('is_preferred')->default(false);
            $table->timestamp('last_price_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'product_id']);
            $table->index(['supplier_id', 'supplier_code']);
        });
    }

    public function down(): void
    {
        foreach (['supplier_products', 'product_stocks', 'product_prices', 'product_codes', 'products', 'suppliers', 'price_lists', 'units', 'brands', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
