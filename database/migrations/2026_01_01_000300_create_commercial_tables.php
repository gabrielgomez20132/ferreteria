<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes, cuentas corrientes, caja, ventas, devoluciones, compras, pedidos y gastos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document_type', 10)->default('DNI');
            $table->string('document_number', 20)->nullable();
            $table->string('tax_condition', 40)->default('Consumidor Final');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('allow_credit')->default(false);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0); // positivo = nos debe
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customer_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // sale, payment, return, adjustment, cancel
            $table->string('description');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->decimal('pending_amount', 15, 2)->default(0); // saldo pendiente del cargo (para vencimientos)
            $table->date('due_date')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();
        });

        Schema::create('supplier_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // purchase, payment, adjustment, cancel
            $table->string('description');
            $table->decimal('debit', 15, 2)->default(0);  // aumenta deuda
            $table->decimal('credit', 15, 2)->default(0); // pago
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_amount', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->string('status', 10)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30); // sale, refund, income, expense, customer_payment, supplier_payment, cancel
            $table->string('direction', 3); // in, out
            $table->string('payment_method', 20)->default('cash');
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('type', 10)->default('sale'); // sale, quote
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('completed'); // completed, cancelled, quote
            $table->string('voucher_type', 20)->default('ticket');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_percent', 6, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('on_account_amount', 15, 2)->default(0);
            $table->decimal('returned_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'type', 'number']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('description');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_percent', 6, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(21);
            $table->decimal('returned_quantity', 15, 3)->default(0);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // cash, transfer, debit_card, credit_card, mercadopago, check, account
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->decimal('total', 15, 2);
            $table->string('refund_method', 20); // cash, account, transfer...
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->boolean('restock')->default(true);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('status', 15)->default('draft'); // draft, sent, partial, received, cancelled
            $table->date('expected_date')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('supplier_code', 60)->nullable();
            $table->string('description');
            $table->decimal('quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('voucher_type', 20)->default('FA'); // FA, FB, FC, REMITO
            $table->string('voucher_number', 40)->nullable();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('other_taxes', 15, 2)->default(0);   // percepciones IIBB/IVA
            $table->decimal('extra_costs', 15, 2)->default(0);   // fletes / gastos a prorratear en el costo
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status', 15)->default('confirmed'); // confirmed, cancelled
            $table->boolean('update_costs')->default(true);
            $table->string('price_action', 20)->default('review'); // review, keep_markup, keep_price
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 15, 3);        // en unidad de compra
            $table->decimal('unit_factor', 15, 4)->default(1); // conversión a unidad de stock
            $table->decimal('stock_quantity', 15, 3);  // quantity * unit_factor
            $table->decimal('unit_cost', 15, 4);       // neto por unidad de compra
            $table->decimal('discount_percent', 6, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(21);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('previous_cost', 15, 4)->default(0);
            $table->decimal('new_cost', 15, 4)->default(0); // por unidad de stock incluyendo prorrateo
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'expense_categories', 'purchase_items', 'purchases', 'purchase_order_items', 'purchase_orders', 'sale_return_items', 'sale_returns', 'sale_payments', 'sale_items', 'sales', 'cash_movements', 'cash_sessions', 'cash_registers', 'supplier_movements', 'customer_movements', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
