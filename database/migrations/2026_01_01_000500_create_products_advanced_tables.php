<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes, kits, productos fabricados, depósitos, órdenes de producción,
 * órdenes de pago a proveedores y bonificaciones por marca/categoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // simple | kit (se vende y descuenta sus componentes) | manufactured (se fabrica con orden de producción)
            $table->string('type', 15)->default('simple')->after('tenant_id');
            $table->foreignId('variant_of_id')->nullable()->after('type')->constrained('products')->nullOnDelete();
            $table->boolean('has_variants')->default(false)->after('variant_of_id');
            $table->json('variant_attributes')->nullable()->after('has_variants'); // {"Medida": "6x40"}
            $table->index(['tenant_id', 'variant_of_id']);
        });

        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();            // kit o producto fabricado
            $table->foreignId('component_id')->constrained('products')->cascadeOnDelete(); // insumo
            $table->decimal('quantity', 15, 4);
            $table->unique(['product_id', 'component_id']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('type', 15)->default('store')->after('code'); // store (sucursal con ventas) | warehouse (depósito)
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
        });

        Schema::create('supplier_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('discount_1', 6, 2)->default(0);
            $table->decimal('discount_2', 6, 2)->default(0);
            $table->decimal('discount_3', 6, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('number');
            $table->decimal('quantity', 15, 3);
            $table->string('status', 15)->default('pending'); // pending, completed, cancelled
            $table->date('due_date')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');
            $table->date('date');
            $table->decimal('total', 15, 2);
            $table->string('status', 15)->default('confirmed'); // confirmed, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('payment_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // cash, transfer, check, ...
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();   // n° de transferencia / cheque
            $table->string('bank')->nullable();
            $table->date('check_date')->nullable();     // fecha de cobro del cheque
        });

        Schema::create('payment_order_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
        });
    }

    public function down(): void
    {
        foreach (['payment_order_purchases', 'payment_order_lines', 'payment_orders', 'production_orders', 'supplier_discount_rules', 'product_components'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn('paid_amount'));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('type'));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('variant_of_id');
            $t->dropColumn(['type', 'has_variants', 'variant_attributes']);
        });
    }
};
