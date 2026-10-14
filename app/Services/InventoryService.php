<?php

namespace App\Services;

use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Support\Numbering;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessException;

/**
 * Inventario físico y transferencias entre sucursales.
 */
class InventoryService
{
    public function __construct(private StockService $stock, private TenantContext $context) {}

    public function startCount(int $branchId, ?int $categoryId, ?string $notes): InventoryCount
    {
        return DB::transaction(function () use ($branchId, $categoryId, $notes) {
            $count = InventoryCount::create([
                'branch_id' => $branchId,
                'user_id' => auth()->id(),
                'category_id' => $categoryId,
                'notes' => $notes,
                'status' => 'open',
            ]);
            $stocks = ProductStock::where('branch_id', $branchId)->pluck('quantity', 'product_id');
            $rows = Product::where('is_active', true)->where('track_stock', true)
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->get(['id', 'cost'])
                ->map(fn ($p) => [
                    'inventory_count_id' => $count->id,
                    'product_id' => $p->id,
                    'system_quantity' => (float) ($stocks[$p->id] ?? 0),
                    'unit_cost' => $p->cost,
                ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                InventoryCountItem::insert($chunk);
            }

            return $count;
        });
    }

    /** Aplica las diferencias contadas como ajustes de stock */
    public function applyCount(InventoryCount $count): int
    {
        if ($count->status !== 'open') {
            throw new BusinessException('El inventario ya fue aplicado o cancelado.');
        }

        return DB::transaction(function () use ($count) {
            $n = 0;
            $items = $count->items()->whereNotNull('counted_quantity')->with('product')->get();
            foreach ($items as $item) {
                // Se toma el stock actual (pudo haber ventas durante el conteo) y se ajusta por la diferencia contada
                $diff = round((float) $item->counted_quantity - (float) $item->system_quantity, 3);
                $item->update(['difference' => $diff]);
                if (abs($diff) > 0.0001 && $item->product) {
                    $this->stock->move($item->product, $count->branch_id, $diff, 'inventory', $count, null, "Inventario #{$count->id}");
                    $n++;
                }
            }
            $count->update(['status' => 'applied', 'applied_at' => now()]);

            return $n;
        });
    }

    public function sendTransfer(int $fromBranch, int $toBranch, array $items, ?string $notes): StockTransfer
    {
        if ($fromBranch === $toBranch) {
            throw new BusinessException('La sucursal de origen y destino deben ser distintas.');
        }

        return DB::transaction(function () use ($fromBranch, $toBranch, $items, $notes) {
            $transfer = StockTransfer::create([
                'number' => Numbering::next('stock_transfers', ['tenant_id' => $this->context->id()]),
                'from_branch_id' => $fromBranch,
                'to_branch_id' => $toBranch,
                'user_id' => auth()->id(),
                'status' => 'in_transit',
                'notes' => $notes,
            ]);
            $products = Product::whereIn('id', collect($items)->pluck('product_id'))->get()->keyBy('id');
            foreach ($items as $i) {
                $product = $products->get($i['product_id']) ?? throw new BusinessException('Producto inexistente');
                $qty = (float) $i['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                StockTransferItem::create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => $qty]);
                $this->stock->move($product, $fromBranch, -$qty, 'transfer_out', $transfer, null, "Transferencia #{$transfer->number}", true);
            }

            return $transfer;
        });
    }

    /** $received = [item_id => cantidad recibida] (si se omite, se recibe lo enviado) */
    public function receiveTransfer(StockTransfer $transfer, array $received = []): StockTransfer
    {
        if ($transfer->status !== 'in_transit') {
            throw new BusinessException('La transferencia no está en tránsito.');
        }

        return DB::transaction(function () use ($transfer, $received) {
            foreach ($transfer->items()->with('product')->get() as $item) {
                $qty = array_key_exists($item->id, $received) ? (float) $received[$item->id] : (float) $item->quantity;
                $item->update(['received_quantity' => $qty]);
                // Si se recibe menos de lo enviado, la diferencia queda registrada como faltante en tránsito
                // (received_quantity < quantity) y ya fue descontada del origen al enviar.
                $this->stock->move($item->product, $transfer->to_branch_id, $qty, 'transfer_in', $transfer, null, "Transferencia #{$transfer->number}");
            }
            $transfer->update(['status' => 'received', 'received_by' => auth()->id(), 'received_at' => now()]);

            return $transfer;
        });
    }

    public function cancelTransfer(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== 'in_transit') {
            throw new BusinessException('Solo se pueden cancelar transferencias en tránsito.');
        }

        return DB::transaction(function () use ($transfer) {
            foreach ($transfer->items()->with('product')->get() as $item) {
                $this->stock->move($item->product, $transfer->from_branch_id, (float) $item->quantity, 'transfer_in', $transfer, null, "Cancelación transferencia #{$transfer->number}");
            }
            $transfer->update(['status' => 'cancelled']);

            return $transfer;
        });
    }
}
