<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $ctx)
    {
        $logs = AuditLog::where('tenant_id', $ctx->id())->with('user:id,name')
            ->when($request->type, fn ($q, $t) => $q->where('auditable_type', 'App\\Models\\'.$t))
            ->when($request->action, fn ($q, $a) => $q->where('action', $a))
            ->latest('created_at')->latest('id')->paginate(50)->withQueryString()
            ->through(fn ($l) => [
                'id' => $l->id, 'action' => $l->action, 'user' => $l->user?->name, 'ip' => $l->ip,
                'model' => class_basename($l->auditable_type), 'model_id' => $l->auditable_id,
                'old' => $l->old_values, 'new' => $l->new_values, 'created_at' => $l->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Settings/Audit', ['logs' => $logs, 'filters' => $request->only(['type', 'action'])]);
    }
}
