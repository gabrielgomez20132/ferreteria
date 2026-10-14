<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\TenantContext;

/**
 * Registra altas, modificaciones y bajas en audit_logs.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('created', null, $m->auditFilter($m->getAttributes())));
        static::updated(function ($m) {
            $changes = $m->auditFilter($m->getChanges());
            if ($changes) {
                $old = array_intersect_key($m->getOriginal(), $changes);
                $m->writeAudit('updated', $old, $changes);
            }
        });
        static::deleted(fn ($m) => $m->writeAudit('deleted', $m->auditFilter($m->getAttributes()), null));
    }

    protected function auditFilter(array $values): array
    {
        return array_diff_key($values, array_flip(['updated_at', 'created_at', 'password', 'remember_token', 'balance']));
    }

    protected function writeAudit(string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'tenant_id' => $this->tenant_id ?? app(TenantContext::class)->id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => request()?->ip(),
        ]);
    }
}
