<?php

namespace App\Support;

/**
 * How far a device read has got, in terms the app can use without knowing which
 * vendor produced it.
 *
 * WHY THIS EXISTS RATHER THAN THE PROTOCOL LIBRARY'S OWN PROGRESS OBJECT: rule 8
 * allows exactly one class in app/ to name the vendor library. Services and
 * controllers cannot receive the library's value object without breaking that
 * boundary — and a second vendor has no bytes to report at all, since a CSL
 * terminal returns rows from a web form. So the driver translates into this, the
 * same way it already translates a Device model into the library's device config.
 *
 * This file deliberately contains no reference to any vendor namespace, and
 * ProtocolLibraryBoundaryTest greps for exactly that. Do not add one.
 *
 * THE UNIT IS PART OF THE VALUE, not an assumption. The ZKTeco transport honestly
 * knows only bytes; the driver converts to records when it can work out the record
 * width, and reports bytes when it cannot. A caller that renders "3,400 of 10,302"
 * must check which it was given, or it will one day tell an operator there are
 * 412,000 punches left to read.
 */
final class DeviceProgress
{
    public const UNIT_RECORDS = 'records';

    public const UNIT_BYTES = 'bytes';

    public function __construct(
        /** Which read this describes: 'attendance', 'users', 'templates'. */
        public readonly string $operation,
        public readonly int $read,
        /** 0 means the device never declared a total — not "nothing to read". */
        public readonly int $total,
        public readonly string $unit = self::UNIT_RECORDS,
        /** 1-based; a retry restarts the count, and the UI should say so. */
        public readonly int $attempt = 1,
    ) {}

    /**
     * Whole percent 0–100, or NULL when the device declared no total.
     *
     * Null rather than 0. Rule 10: "we were not told" and "none" are different
     * answers and must stay visually distinct — the bar goes indeterminate, it does
     * not sit at zero pretending to know.
     */
    public function percent(): ?int
    {
        if ($this->total <= 0) {
            return null;
        }

        return (int) round(min(1.0, $this->read / $this->total) * 100);
    }

    public function isRecords(): bool
    {
        return $this->unit === self::UNIT_RECORDS;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'read' => $this->read,
            'total' => $this->total,
            'unit' => $this->unit,
            'percent' => $this->percent(),
            'attempt' => $this->attempt,
        ];
    }
}
