<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerMovement;
use App\Models\Supplier;
use App\Models\SupplierMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessException;

/**
 * Cuentas corrientes de clientes y proveedores.
 */
class AccountService
{
    public function __construct(private CashService $cash) {}

    public function chargeCustomer(Customer $customer, float $amount, string $type, string $description, ?Model $reference = null, ?string $dueDate = null): CustomerMovement
    {
        $customer = Customer::lockForUpdate()->find($customer->id);
        $balance = round((float) $customer->balance + $amount, 2);
        $customer->update(['balance' => $balance]);

        return CustomerMovement::create([
            'customer_id' => $customer->id,
            'branch_id' => app(\App\Support\TenantContext::class)->branchId(),
            'user_id' => auth()->id(),
            'type' => $type,
            'description' => $description,
            'debit' => $amount,
            'credit' => 0,
            'balance_after' => $balance,
            'pending_amount' => $amount,
            'due_date' => $dueDate ?? now()->addDays(30)->toDateString(),
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /** Crédito al cliente (pago, devolución, anulación). Imputa a cargos pendientes por antigüedad. */
    public function creditCustomer(Customer $customer, float $amount, string $type, string $description, ?Model $reference = null, ?string $method = null): CustomerMovement
    {
        $customer = Customer::lockForUpdate()->find($customer->id);
        $balance = round((float) $customer->balance - $amount, 2);
        $customer->update(['balance' => $balance]);

        $remaining = $amount;
        $pending = CustomerMovement::where('customer_id', $customer->id)->where('pending_amount', '>', 0)->orderBy('due_date')->orderBy('id')->get();
        foreach ($pending as $m) {
            if ($remaining <= 0) {
                break;
            }
            $apply = min($remaining, (float) $m->pending_amount);
            $m->update(['pending_amount' => round((float) $m->pending_amount - $apply, 2)]);
            $remaining -= $apply;
        }

        return CustomerMovement::create([
            'customer_id' => $customer->id,
            'branch_id' => app(\App\Support\TenantContext::class)->branchId(),
            'user_id' => auth()->id(),
            'type' => $type,
            'description' => $description,
            'debit' => 0,
            'credit' => $amount,
            'balance_after' => $balance,
            'payment_method' => $method,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /** Cobranza: crédito en la cuenta + ingreso en caja */
    public function receiveCustomerPayment(Customer $customer, float $amount, string $method, ?CashSession $session, ?string $notes = null): CustomerMovement
    {
        if ($amount <= 0) {
            throw new BusinessException('El importe debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($customer, $amount, $method, $session, $notes) {
            $movement = $this->creditCustomer($customer, $amount, 'payment', 'Pago recibido'.($notes ? " - {$notes}" : ''), null, $method);
            if ($session) {
                $this->cash->record($session, 'customer_payment', 'in', $method, $amount, "Cobranza {$customer->name}", $movement);
            }

            return $movement;
        });
    }

    public function chargeSupplier(Supplier $supplier, float $amount, string $type, string $description, ?Model $reference = null, ?string $dueDate = null): SupplierMovement
    {
        $supplier = Supplier::lockForUpdate()->find($supplier->id);
        $balance = round((float) $supplier->balance + $amount, 2);
        $supplier->update(['balance' => $balance]);

        return SupplierMovement::create([
            'supplier_id' => $supplier->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'description' => $description,
            'debit' => max($amount, 0),
            'credit' => max(-$amount, 0),
            'balance_after' => $balance,
            'due_date' => $dueDate,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    public function paySupplier(Supplier $supplier, float $amount, string $method, ?CashSession $session, ?string $notes = null): SupplierMovement
    {
        if ($amount <= 0) {
            throw new BusinessException('El importe debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($supplier, $amount, $method, $session, $notes) {
            $supplier = Supplier::lockForUpdate()->find($supplier->id);
            $balance = round((float) $supplier->balance - $amount, 2);
            $supplier->update(['balance' => $balance]);
            $movement = SupplierMovement::create([
                'supplier_id' => $supplier->id,
                'user_id' => auth()->id(),
                'type' => 'payment',
                'description' => 'Pago a proveedor'.($notes ? " - {$notes}" : ''),
                'debit' => 0,
                'credit' => $amount,
                'balance_after' => $balance,
                'payment_method' => $method,
            ]);
            if ($session) {
                $this->cash->record($session, 'supplier_payment', 'out', $method, $amount, "Pago a {$supplier->name}", $movement);
            }

            return $movement;
        });
    }
}
