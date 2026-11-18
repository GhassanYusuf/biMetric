<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records create/update/delete of audited models to the immutable audit trail.
 * Since 2026-11-09 an update records each field as [before, after] and a delete the whole
 * record as it was; earlier rows hold the new values only (AuditLogController infers the
 * old ones from the trail, and says so).
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

    /**
     * Each changed field as [before, after] (owner, 2026-11-09: "you did not show what the
     * record was before updating"). Until then only the new values were kept. The `updated`
     * event fires before Eloquent syncs its originals, so getRawOriginal() is still the value
     * that was in the database.
     */
    public function updated(Model $model): void
    {
        $after = $this->clean($model->getChanges());
        unset($after['updated_at']);
        if ($after === []) {
            return;
        }
        $before = $this->clean(array_intersect_key($model->getRawOriginal(), $after));
        $changes = [];
        foreach ($after as $field => $value) {
            $changes[$field] = [$before[$field] ?? null, $value];
        }
        $this->record('updated', $model, $changes);
    }

    /** The whole record as it was, each field as [value, null] (2026-11-09; was nothing). */
    public function deleted(Model $model): void
    {
        $was = $this->clean($model->getRawOriginal() ?: $model->getAttributes());
        unset($was['updated_at']);
        $this->record('deleted', $model, array_map(fn ($v) => [$v, null], $was));
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
