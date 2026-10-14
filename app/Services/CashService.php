<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessException;

class CashService
{
    public const METHODS = [
        'cash' => 'Efectivo',
        'transfer' => 'Transferencia',
        'debit_card' => 'Tarjeta de débito',
        'credit_card' => 'Tarjeta de crédito',
        'mercadopago' => 'Mercado Pago / QR',
        'check' => 'Cheque',
        'account' => 'Cuenta corriente',
    ];

    public const TYPES = [
        'sale' => 'Venta',
        'refund' => 'Devolución',
        'cancel' => 'Anulación de venta',
        'income' => 'Ingreso manual',
        'expense' => 'Egreso / gasto',
        'customer_payment' => 'Cobranza cuenta corriente',
        'supplier_payment' => 'Pago a proveedor',
        'withdrawal' => 'Retiro de efectivo',
    ];

    /** Sesión de caja abierta del usuario en la sucursal */
    public function openSessionFor(User $user, int $branchId): ?CashSession
    {
        return CashSession::where('branch_id', $branchId)
            ->where('status', 'open')
            ->where('user_id', $user->id)
            ->latest('opened_at')
            ->first()
            ?? CashSession::where('branch_id', $branchId)->where('status', 'open')
                ->whereHas('cashRegister', fn ($q) => $q->where('is_active', true))
                ->when(! $user->hasPermission('cash.manage'), fn ($q) => $q->whereRaw('1 = 0'))
                ->latest('opened_at')->first();
    }

    public function open(CashRegister $register, User $user, float $amount, ?string $notes = null): CashSession
    {
        if (CashSession::where('cash_register_id', $register->id)->where('status', 'open')->exists()) {
            throw new BusinessException("La caja \"{$register->name}\" ya está abierta.");
        }

        return CashSession::create([
            'branch_id' => $register->branch_id,
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $amount,
            'status' => 'open',
            'notes' => $notes,
        ]);
    }

    public function record(CashSession $session, string $type, string $direction, string $method, float $amount, ?string $description = null, ?Model $reference = null): ?CashMovement
    {
        if ($session->status !== 'open') {
            throw new BusinessException('La caja está cerrada.');
        }
        if ($amount <= 0 || $method === 'account') {
            return null;
        }

        return CashMovement::create([
            'cash_session_id' => $session->id,
            'branch_id' => $session->branch_id,
            'user_id' => auth()->id(),
            'type' => $type,
            'direction' => $direction,
            'payment_method' => $method,
            'amount' => round($amount, 2),
            'description' => $description,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /** Totales por medio de pago (in - out) */
    public function totals(CashSession $session): array
    {
        $rows = $session->movements()
            ->selectRaw("payment_method, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) ins, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) outs")
            ->groupBy('payment_method')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->payment_method] = ['in' => (float) $r->ins, 'out' => (float) $r->outs, 'net' => (float) $r->ins - (float) $r->outs];
        }

        return $out;
    }

    public function expectedCash(CashSession $session): float
    {
        $t = $this->totals($session);

        return round((float) $session->opening_amount + ($t['cash']['net'] ?? 0), 2);
    }

    public function close(CashSession $session, float $counted, ?string $notes = null): CashSession
    {
        if ($session->status !== 'open') {
            throw new BusinessException('La caja ya está cerrada.');
        }

        return DB::transaction(function () use ($session, $counted, $notes) {
            $expected = $this->expectedCash($session);
            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => round($counted - $expected, 2),
                'notes' => trim(($session->notes ? $session->notes."\n" : '').($notes ?? '')) ?: null,
            ]);

            return $session;
        });
    }
}
