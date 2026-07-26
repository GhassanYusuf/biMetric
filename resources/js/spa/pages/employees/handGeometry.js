/*
| Shared hand geometry for the fingerprint diagrams — the full-size picker in the
| enrollment dialog and the miniature in each roster row draw from this one
| source, so the two can never disagree about which shape is which slot.
|
| Slot numbering follows the convention every open-source ZK client uses:
| 0-4 left little→thumb, 5-9 right thumb→little. That anatomical mapping is NOT
| something the device reports — the terminal only ever gives a slot number. So
| the number is the truth and the finger name is the convention; anywhere a name
| is shown, the number is shown too.
*/

export const HAND_W = 132;
export const HAND_H = 176;

// Right hand, palm toward the viewer: thumb on the left, fingers pointing up.
export const RIGHT_GEOMETRY = [
    { key: 'thumb', name: 'Thumb', x: 6, y: 100, w: 17, h: 48, rot: -30 },
    { key: 'index', name: 'Index', x: 41, y: 42, w: 18, h: 62 },
    { key: 'middle', name: 'Middle', x: 63, y: 28, w: 18, h: 76 },
    { key: 'ring', name: 'Ring', x: 85, y: 40, w: 18, h: 64 },
    { key: 'little', name: 'Little', x: 107, y: 58, w: 16, h: 46 },
];

// Same hand mirrored. Rotation flips with it so the thumb still angles outward.
export const LEFT_GEOMETRY = RIGHT_GEOMETRY.map((f) => ({
    ...f,
    x: HAND_W - f.x - f.w,
    rot: f.rot ? -f.rot : undefined,
}));

export const SLOTS = {
    left: { little: 0, ring: 1, middle: 2, index: 3, thumb: 4 },
    right: { thumb: 5, index: 6, middle: 7, ring: 8, little: 9 },
};

export const GEOMETRY_BY_SIDE = { left: LEFT_GEOMETRY, right: RIGHT_GEOMETRY };

/** Palm rect for one side, in the same coordinate space as the fingers. */
export function palmRect(side) {
    return {
        x: side === 'right' ? 30 : HAND_W - 30 - 92,
        y: 98,
        width: 92,
        height: 66,
        rx: 24,
    };
}

/** Anatomical name for a slot. A convention, not device data — see file header. */
export function slotName(slot) {
    for (const side of ['left', 'right']) {
        for (const [key, value] of Object.entries(SLOTS[side])) {
            if (value === slot) return `${side} ${key}`;
        }
    }
    return `slot ${slot}`;
}

/** "Slot 6 (right index)" — used in tooltips and confirmation copy. */
export function describeSlots(slots) {
    if (slots == null) return 'Fingerprints unknown — the terminal\'s template table could not be decoded';
    if (slots.length === 0) return 'No fingerprints enrolled on the terminal';

    return slots.map((s) => `Slot ${s} (${slotName(s)})`).join(', ');
}
