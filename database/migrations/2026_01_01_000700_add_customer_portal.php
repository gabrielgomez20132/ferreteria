<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal de clientes: cada cliente puede tener un link secreto para ver su cuenta corriente.
 * Solo agrega columnas (seguro para instalaciones en uso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'portal_token')) {
                $table->string('portal_token', 64)->nullable()->unique()->after('notes');
                $table->timestamp('portal_last_seen_at')->nullable()->after('portal_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn(['portal_token', 'portal_last_seen_at']);
        });
    }
};
