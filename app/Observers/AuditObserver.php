<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records create/update/delete of audited models to the immutable audit trail.
 * Registered for specific models in AppServiceProvider. Secrets are stripped
 * from the recorded diff so the trail never stores credentials.
 */
class AuditObserver
{
    // device_password: the terminal keypad password (2026-10-12) — that it changed is
    // audited, not even its ciphertext.
    private const REDACT = ['password', 'comm_key', 'web_password', 'remember_token', 'device_password'];

    public function created(Model $model): void
    {
        $this->record('created', $model, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        unset($changes['updated_at']);
        if ($changes === []) {
            return;
        }
        $this->record('updated', $model, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record('deleted', $model, null);
    }

    private function record(string $verb, Model $model, ?array $changes): void
    {
        $type = strtolower(class_basename($model));

        AuditLog::create([
            // tenant_id auto-stamped by BelongsToTenant; fall back to the
            // model's own tenant_id for console/queue contexts.
            'tenant_id' => Tenancy::id() ?? $model->getAttribute('tenant_id'),
            'user_id' => Auth::id(),
            'action' => "{$type}.{$verb}",
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    private function clean(array $attributes): array
    {
        foreach (self::REDACT as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '***';
            }
        }

        return $attributes;
    }
}
