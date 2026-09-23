<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The pair of device-local dates an operator can bound an attendance read with.
 *
 * ONE definition, because two endpoints accept it — the devices list posts to
 * `devices/{device}/sync-attendance`, device detail queues the same command through
 * `devices/{device}/commands` — and a window that validated differently on the two
 * screens would be a bug nobody finds until a month imports on one page and is
 * refused on the other.
 *
 * It carries strings, not instants, on purpose. Turning "2026-08-01" into a moment
 * requires the device's timezone, and only AttendanceSyncService knows that; the
 * request layer's job is to establish that the operator typed two real dates in the
 * right order and that the later one is not before the earlier.
 *
 * Both halves are optional and independent: "everything since 1 August" and
 * "everything up to 31 March" are legitimate requests. Both null means no window,
 * which is an ordinary watermark sync.
 */
final class AttendanceWindow
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    /**
     * Validation rules to merge into an endpoint's own.
     *
     * `after_or_equal:from` is attached ONLY when `from` was actually sent: the rule
     * resolves the other field's value at validation time, and comparing against a
     * missing one would reject the perfectly valid "up to 31 March" request.
     *
     * @return array<string,list<string>>
     */
    public static function rules(Request $request): array
    {
        $to = ['nullable', 'date_format:Y-m-d'];

        if ($request->filled('from')) {
            $to[] = 'after_or_equal:from';
        }

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => $to,
        ];
    }

    /** @param array<string,mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self($validated['from'] ?? null, $validated['to'] ?? null);
    }

    public function isBounded(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * The window in the words the operator used, for the queued-confirmation message.
     *
     * Echoing the dates back matters more here than in most confirmations: this
     * action reads a whole terminal and imports a slice of it, and the operator has
     * no other way to check that the slice they meant is the slice that was queued.
     */
    public function describe(): string
    {
        if ($this->from !== null && $this->to !== null) {
            return "{$this->from} to {$this->to}";
        }

        return $this->from !== null ? "from {$this->from} onwards" : "up to {$this->to}";
    }

    /** @return array{full?:bool, from:?string, to:?string} */
    public function toPayload(bool $full = false): array
    {
        return ['full' => $full, 'from' => $this->from, 'to' => $this->to];
    }
}
