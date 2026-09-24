<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;

/**
 * Per-company settings, stored in the `settings` table (tenant_id, key, value).
 *
 * ALLOW-LISTED: only keys declared in DEFAULTS can be read or written, each with its
 * type and default, so the table can never fill with arbitrary keys from a request.
 * Every change is audited (setting.updated, old and new value).
 */
class TenantSettings
{
    /**
     * key => [default, type]
     *
     * employees.auto_push_on_save (owner's decision, 2026-09-24): when on, saving an
     * employee pushes name, card and — where the terminal supports it — the user ID to
     * every terminal on their Access levels list without the "Push now / Not now"
     * question. OFF by default: it turns every edit into immediate terminal writes, so
     * an administrator switches it on knowingly. Removals from a terminal always ask.
     */
    public const DEFAULTS = [
        'employees.auto_push_on_save' => [false, 'bool'],
    ];

    /** @return array<string,mixed> every known key with its current value */
    public function all(): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');

        $out = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            $out[$key] = $stored->has($key) ? $this->cast($stored[$key], $type) : $default;
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}.");
    }

    public function set(string $key, mixed $value): mixed
    {
        if (! isset(self::DEFAULTS[$key])) {
            throw new \InvalidArgumentException("Unknown setting {$key}.");
        }

        $old = $this->get($key);
        $new = $this->cast($value, self::DEFAULTS[$key][1]);

        Setting::updateOrCreate(['tenant_id' => Tenancy::id(), 'key' => $key], ['value' => $new]);

        if ($old !== $new) {
            AuditLog::create([
                'tenant_id' => Tenancy::id(),
                'user_id' => Auth::id(),
                'action' => 'setting.updated',
                'auditable_type' => Setting::class,
                'auditable_id' => null,
                'changes' => ['key' => $key, 'old' => $old, 'new' => $new],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
            ]);
        }

        return $new;
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
