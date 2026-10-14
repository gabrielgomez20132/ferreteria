<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock (movimientos, transferencias, inventario) y el motor de actualización de precios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // initial, purchase, sale, return, cancel, adjustment, transfer_in, transfer_out, inventory
            $table->string('type', 20);
            $table->decimal('quantity', 15, 3); // con signo
            $table->decimal('balance_after', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->nullableMorphs('reference');
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->string('status', 15)->default('in_transit'); // in_transit, received, cancelled
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->nullable();
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('open'); // open, applied, cancelled
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('system_quantity', 15, 3)->default(0);
            $table->decimal('counted_quantity', 15, 3)->nullable();
            $table->decimal('difference', 15, 3)->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->unique(['inventory_count_id', 'product_id']);
        });

        /*
         * ===== Motor de precios =====
         * price_updates: lote auditado y reversible de cambios de costo/precio.
         */
        Schema::create('price_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20); // supplier_import, mass_update, purchase, manual, review
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->json('options')->nullable();
            $table->unsignedInteger('products_count')->default(0);
            $table->decimal('avg_cost_variation', 8, 2)->nullable();
            $table->decimal('avg_price_variation', 8, 2)->nullable();
            $table->string('status', 15)->default('applied'); // applied, reverted
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('price_update_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_cost', 15, 4);
            $table->decimal('new_cost', 15, 4);
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->decimal('old_markup', 8, 2);
            $table->decimal('new_markup', 8, 2);
        });

        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_update_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 20); // purchase, supplier_import, mass_update, manual, revert, review
            $table->decimal('old_cost', 15, 4);
            $table->decimal('new_cost', 15, 4);
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        /*
         * Importación de listas de precios de proveedores (Excel / CSV)
         */
        Schema::create('supplier_price_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            // uploaded -> processed -> applied | cancelled
            $table->string('status', 15)->default('uploaded');
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->unsignedInteger('increased_rows')->default(0);
            $table->unsignedInteger('decreased_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('alert_rows')->default(0);
            $table->decimal('avg_variation', 8, 2)->nullable();
            $table->foreignId('price_update_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_price_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_price_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('supplier_code', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('description')->nullable();
            $table->string('brand', 100)->nullable();
            $table->decimal('list_price', 15, 4)->nullable(); // precio tal cual viene en la lista
            $table->decimal('new_cost', 15, 4)->nullable();   // costo neto por unidad de stock resultante
            $table->decimal('current_cost', 15, 4)->nullable();
            $table->decimal('variation_percent', 10, 2)->nullable();
            $table->decimal('current_price', 15, 2)->nullable();
            $table->decimal('suggested_price', 15, 2)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_type', 20)->nullable(); // supplier_code, barcode, internal_code, manual
            // matched, unmatched, unchanged, error, created
            $table->string('status', 15)->default('unmatched');
            $table->boolean('is_alert')->default(false);
            $table->boolean('selected')->default(true);
            $table->string('error')->nullable();
            $table->index(['supplier_price_import_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['supplier_price_import_rows', 'supplier_price_imports', 'product_price_histories', 'price_update_items', 'price_updates', 'inventory_count_items', 'inventory_counts', 'stock_transfer_items', 'stock_transfers', 'stock_movements'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
