import { GEOMETRY_BY_SIDE, HAND_H, HAND_W, SLOTS, describeSlots, palmRect } from './handGeometry';

/*
| Roster-cell version of the hand diagram: both hands at ~40px, enrolled fingers
| filled, everything else pale.
|
| No slot numbers at this size — they would be sub-legible noise. The full
| reading lives in the row's detail panel, and the accessible name plus the
| native tooltip carry it here ("Slot 6 (right index)"), so the information is
| never only available as colour.
*/

const SCALE = 0.3; // 132×176 → ~40×53 per hand

export default function MiniHands({ slots }) {
    // null/undefined = the terminal's template table could not be decoded. That
    // is not the same as "no fingers", so it must not render as empty hands.
    const unknown = slots == null;
    const enrolled = new Set(unknown ? [] : slots);
    const label = describeSlots(slots ?? null);

    return (
        <span
            className="inline-flex items-center gap-1.5 align-middle"
            title={label}
            role="img"
            aria-label={label}
        >
            {['left', 'right'].map((side) => (
                <MiniHand key={side} side={side} enrolled={enrolled} unknown={unknown} />
            ))}
            {unknown && <span className="text-xs font-medium text-amber-700">?</span>}
        </span>
    );
}

function MiniHand({ side, enrolled, unknown }) {
    const palm = palmRect(side);

    return (
        <svg
            width={Math.round(HAND_W * SCALE)}
            height={Math.round(HAND_H * SCALE)}
            viewBox={`0 0 ${HAND_W} ${HAND_H}`}
            aria-hidden="true"
            focusable="false"
        >
            <rect {...palm} className={unknown ? 'fill-amber-100' : 'fill-slate-100'} />

            {GEOMETRY_BY_SIDE[side].map((finger) => {
                const slot = SLOTS[side][finger.key];
                const isEnrolled = enrolled.has(slot);
                const cx = finger.x + finger.w / 2;
                const cy = finger.y + finger.h / 2;

                return (
                    <rect
                        key={finger.key}
                        x={finger.x}
                        y={finger.y}
                        width={finger.w}
                        height={finger.h}
                        rx={finger.w / 2}
                        transform={finger.rot ? `rotate(${finger.rot} ${cx} ${cy})` : undefined}
                        className={
                            unknown
                                ? 'fill-amber-200'
                                : isEnrolled
                                    ? 'fill-emerald-500'
                                    : 'fill-slate-200'
                        }
                    />
                );
            })}
        </svg>
    );
}
