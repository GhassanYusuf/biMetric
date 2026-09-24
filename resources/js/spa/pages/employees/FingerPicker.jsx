import { GEOMETRY_BY_SIDE, HAND_H, HAND_W, SLOTS, palmRect, slotName } from './handGeometry';

/*
| Two-hand diagram for picking a fingerprint slot.
|
| Slot numbering follows the convention every open-source ZK client uses —
| 0-4 left little→thumb, 5-9 right thumb→little. That mapping is NOT verified
| against this project's hardware, so each finger is labelled with its slot
| NUMBER, which is the value actually written to the device. If a firmware
| numbers fingers differently, the number on screen is still the truth and the
| anatomical name is the guess; read the number, not the name.
|
| Enrolled slots come from the device's own template table, so "already
| enrolled" is a read fact, not an assumption. When the table can't be decoded
| the component says so rather than drawing every finger as empty.
*/

export default function FingerPicker({ value, onChange, enrolled, disabled = false, readOnly = false, neverRead = false }) {
    /*
     * null has TWO causes, and they are different facts (rule 3):
     *   neverRead  we have not read this terminal for this person, so there is
     *              nothing to decode yet
     *   otherwise  a read happened and the template table would not decode
     * Either way the fingers are unknown and must not be drawn as "empty".
     */
    const unknown = enrolled === null || enrolled === undefined;
    const enrolledSet = new Set(unknown ? [] : enrolled);

    const handProps = {
        value: readOnly ? null : value,
        onChange,
        enrolledSet,
        unknown,
        disabled,
        readOnly,
    };

    return (
        <div>
            <div className="flex items-start justify-center gap-4">
                <Hand side="left" {...handProps} />
                <Hand side="right" {...handProps} />
            </div>

            <div className="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 text-xs">
                <LegendKey className="bg-emerald-500" label="Enrolled" />
                {!readOnly && <LegendKey className="bg-violet-600" label="Selected" />}
                <LegendKey className="bg-slate-200" label={readOnly ? 'Not enrolled' : 'Empty'} />
            </div>

            {unknown && neverRead && (
                <p className="mt-2 text-center text-xs text-slate-500">
                    Not read from this terminal yet, so enrolled fingers aren't known.
                </p>
            )}

            {unknown && !neverRead && (
                <p className="mt-2 text-center text-xs text-amber-700">
                    {readOnly
                        ? "This firmware's fingerprint table couldn't be decoded, so enrolled fingers can't be shown."
                        : "This firmware's fingerprint table couldn't be decoded, so existing enrollments aren't shown. You can still capture into any slot."}
                </p>
            )}
        </div>
    );
}

function LegendKey({ className, label }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-slate-600">
            <span className={`h-2.5 w-2.5 rounded-full ${className}`} aria-hidden="true" />
            {label}
        </span>
    );
}

function Hand({ side, value, onChange, enrolledSet, unknown, disabled, readOnly }) {
    const geometry = GEOMETRY_BY_SIDE[side];
    const palm = palmRect(side);
    const interactive = !readOnly && !disabled;

    return (
        <div className="flex flex-col items-center">
            <svg
                width={HAND_W}
                height={HAND_H}
                viewBox={`0 0 ${HAND_W} ${HAND_H}`}
                role="group"
                aria-label={`${side} hand`}
                className="overflow-visible"
            >
                {/* Palm sits under the fingers so they read as attached to it. */}
                <rect {...palm} className="fill-slate-100" />

                {geometry.map((finger) => {
                    const slot = SLOTS[side][finger.key];
                    // value is null when read-only; Number(null) is 0, which lit slot 0 up as selected.
                    const isSelected = value != null && Number(value) === slot;
                    const isEnrolled = enrolledSet.has(slot);
                    const cx = finger.x + finger.w / 2;
                    const cy = finger.y + finger.h / 2;

                    const fill = isSelected
                        ? 'fill-violet-600'
                        : isEnrolled
                            ? 'fill-emerald-500'
                            : 'fill-slate-200';

                    return (
                        <g
                            key={finger.key}
                            role={readOnly ? 'img' : 'radio'}
                            aria-checked={readOnly ? undefined : isSelected}
                            aria-label={`Slot ${slot}, ${side} ${finger.name}${isEnrolled ? ', enrolled' : unknown ? '' : ', not enrolled'}`}
                            tabIndex={interactive ? 0 : undefined}
                            onClick={() => interactive && onChange(slot)}
                            onKeyDown={(e) => {
                                if (!interactive) return;
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    onChange(slot);
                                }
                            }}
                            className={`outline-none [&:focus-visible>rect]:stroke-violet-500 ${
                                readOnly ? '' : disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
                            }`}
                        >
                            <rect
                                x={finger.x}
                                y={finger.y}
                                width={finger.w}
                                height={finger.h}
                                rx={finger.w / 2}
                                transform={finger.rot ? `rotate(${finger.rot} ${cx} ${cy})` : undefined}
                                strokeWidth={2}
                                className={`${fill} stroke-transparent transition-[fill] duration-150 ${
                                    interactive && !isSelected ? 'hover:fill-violet-300' : ''
                                }`}
                            />
                            {/* Slot number is the authoritative label — never rotated,
                                so it stays legible on the angled thumb. */}
                            <text
                                x={cx}
                                y={cy + 4}
                                textAnchor="middle"
                                className={`pointer-events-none select-none text-[11px] font-bold ${
                                    isSelected || isEnrolled ? 'fill-white' : 'fill-slate-600'
                                }`}
                            >
                                {slot}
                            </text>
                        </g>
                    );
                })}
            </svg>
            <span className="mt-1 text-xs font-medium capitalize text-slate-600">{side} hand</span>
        </div>
    );
}

// Re-exported so existing importers keep working; it lives in handGeometry now.
export { slotName };
