<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StockTransferController extends Controller
{
    public function __construct(private InventoryService $service, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        return Inertia::render('Stock/Transfers/Index', [
            'transfers' => StockTransfer::with('fromBranch:id,name', 'toBranch:id,name', 'user:id,name')->withCount('items')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(25)->withQueryString(),
            'filters' => $request->only('status'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Stock/Transfers/Create', [
            'branches' => Branch::where('is_active', true)->where('id', '!=', $this->ctx->branchId())->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $this->ctx->id())],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $t = $this->service->sendTransfer($this->ctx->branchId(), (int) $data['to_branch_id'], $data['items'], $data['notes'] ?? null);

        return redirect()->route('stock.transfers.show', $t)->with('success', "Transferencia #{$t->number} enviada.");
    }

    public function show(StockTransfer $transfer)
    {
        return Inertia::render('Stock/Transfers/Show', [
            'transfer' => $transfer->load('fromBranch:id,name', 'toBranch:id,name', 'user:id,name', 'receiver:id,name', 'items.product:id,code,name'),
            'canReceive' => $transfer->status === 'in_transit' && $transfer->to_branch_id === $this->ctx->branchId(),
        ]);
    }

    public function receive(Request $request, StockTransfer $transfer)
    {
        abort_unless($transfer->to_branch_id === $this->ctx->branchId(), 403, 'Debe estar trabajando en la sucursal de destino para recibir.');
        $data = $request->validate(['received' => ['array'], 'received.*' => ['numeric', 'min:0']]);
        $this->service->receiveTransfer($transfer, $data['received'] ?? []);

        return back()->with('success', 'Mercadería recibida e ingresada al stock.');
    }

    public function cancel(StockTransfer $transfer)
    {
        $this->service->cancelTransfer($transfer);

        return back()->with('success', 'Transferencia cancelada. El stock volvió al origen.');
    }
}
