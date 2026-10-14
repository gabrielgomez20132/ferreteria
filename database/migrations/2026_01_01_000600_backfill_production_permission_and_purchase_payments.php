<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste de datos para instalaciones que ya estaban en uso antes de las órdenes de producción y de pago:
 *
 * 1. Agrega el permiso "production.manage" a los roles Encargado y Depósito existentes
 *    (el Administrador ya tiene todos los permisos con '*').
 * 2. Imputa a las facturas de compra los pagos a proveedores registrados antes de existir las
 *    órdenes de pago (de la factura más vieja a la más nueva), para que no figuren como pendientes.
 *
 * Es seguro correrla más de una vez y no modifica saldos de cuenta corriente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. Permiso de producción ----
        DB::table('roles')->whereIn('name', ['Encargado', 'Depósito'])->orderBy('id')->get()->each(function ($role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];
            if (! in_array('*', $perms, true) && ! in_array('production.manage', $perms, true)) {
                $perms[] = 'production.manage';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values($perms))]);
            }
        });

        // ---- 2. Pagos anteriores imputados a facturas ----
        $suppliers = DB::table('purchases')->where('status', 'confirmed')->distinct()->pluck('supplier_id');
        foreach ($suppliers as $supplierId) {
            // Si el proveedor ya tiene imputaciones (órdenes de pago), no se toca
            $alreadyApplied = DB::table('purchases')->where('supplier_id', $supplierId)->where('paid_amount', '>', 0)->exists();
            if ($alreadyApplied) {
                continue;
            }
            // Pagos sueltos registrados antes (no vinculados a una orden de pago)
            $paid = (float) DB::table('supplier_movements')
                ->where('supplier_id', $supplierId)
                ->where('type', 'payment')
                ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '!=', 'App\\Models\\PaymentOrder'))
                ->sum('credit');
            if ($paid <= 0) {
                continue;
            }
            $purchases = DB::table('purchases')->where('supplier_id', $supplierId)->where('status', 'confirmed')
                ->orderBy('date')->orderBy('id')->get(['id', 'total']);
            foreach ($purchases as $p) {
                if ($paid <= 0.009) {
                    break;
                }
                $apply = round(min($paid, (float) $p->total), 2);
                DB::table('purchases')->where('id', $p->id)->update(['paid_amount' => $apply]);
                $paid -= $apply;
            }
        }
    }

    public function down(): void
    {
        // Ajuste de datos: no se revierte.
    }
};
