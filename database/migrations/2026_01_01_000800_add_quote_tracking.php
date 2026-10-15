<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Presupuestos completos: validez, estados (pendiente / convertido / rechazado; vencido se calcula),
 * vínculo presupuesto ↔ venta, condiciones comerciales y link público para el cliente.
 * Solo agrega columnas; los presupuestos existentes quedan pendientes con 7 días de validez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'valid_until')) {
                return;
            }
            $table->date('valid_until')->nullable()->after('voucher_type');
            $table->text('terms')->nullable()->after('notes');
            $table->foreignId('quote_id')->nullable()->after('price_list_id')->constrained('sales')->nullOnDelete();     // venta: presupuesto de origen
            $table->foreignId('converted_sale_id')->nullable()->after('quote_id')->constrained('sales')->nullOnDelete(); // presupuesto: venta generada
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->string('public_token', 64)->nullable()->unique();
            $table->index(['tenant_id', 'type', 'status']);
        });

        // Presupuestos existentes: validez de 7 días desde su fecha y link público
        DB::table('sales')->where('type', 'quote')->whereNull('public_token')->orderBy('id')->each(function ($q) {
            DB::table('sales')->where('id', $q->id)->update([
                'valid_until' => date('Y-m-d', strtotime($q->created_at.' +7 days')),
                'public_token' => Str::random(40),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
            $table->dropConstrainedForeignId('converted_sale_id');
            $table->dropUnique(['public_token']);
            $table->dropIndex(['tenant_id', 'type', 'status']);
            $table->dropColumn(['valid_until', 'terms', 'converted_at', 'rejected_at', 'reject_reason', 'sent_at', 'viewed_at', 'public_token']);
        });
    }
};
