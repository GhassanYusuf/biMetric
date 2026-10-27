import { useEffect, useState } from 'react';
import { Button, Card, InlineAlert } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { isoToDmy } from '../../shared/dates';
import { cardErrorCode, readCard } from '../../shared/idCard/client';

/*
| "Read ID card" on the employee form (owner, 2026-10-27). Reads the Bahrain smart ID card
| through iGA's GCC CardRead Server on the PC with the reader (http://localhost:5050), using
| the colleague's client unchanged (shared/idCard/client.ts, @ahl/id-card-reader 0.1.0).
| Our server is not involved in reading: the card's values only go into the form, and only
| what staff then Save reaches the system.
|
| The owner's mapping: CPR → Personnel ID; the English name with its LAST word → Last name
| and everything before it → First name; gender; birthday; the photo (owner, 2026-10-27:
| "I don't want the number, I want the photo"). No Arabic name. The photo goes through the
| form's own pickPhoto(), so it is checked, previewed and uploaded after Save like a
| hand-picked one. It is filled in only when the employee has none; replacing one is a
| choice in the dialog, never ticked for you (the colleague's rule too).
|
| Add: fills the form when nothing there would be replaced; otherwise, and always on Edit,
| a card | on file | apply dialog with the differing fields ticked. If the card's CPR is not
| this employee's Personnel ID, nothing is ticked and it says so: a card must never quietly
| overwrite somebody else's record. Nothing card-related is logged (rule of the client too).
*/

const MESSAGES = {
    not_running: 'The ID card reader program is not running on this PC. Start the card reader (on the reception PC: the Windows VM) and try again.',
    reader_off: 'The card reader is not ready. Check it is connected, then try again.',
    no_card: 'No ID card in the reader. Insert the card and try again.',
    failed: 'The card is in the reader but could not be read. Take it out, put it back, and try again.',
    service_error: 'The card reader program gave an unexpected answer. Try again.',
};

/** The owner's split: the last word is the last name, everything before it the first name. */
export function splitCardName(full) {
    const words = (full ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return { name: null, last_name: null };
    if (words.length === 1) return { name: words[0], last_name: null };
    return { name: words.slice(0, -1).join(' '), last_name: words[words.length - 1] };
}

/** What the card offers for the form, field by field; missing values are left out. */
export function cardToForm(card) {
    const { name, last_name } = splitCardName(card.nameEn);
    const out = { employee_code: card.cpr, name, last_name, gender: card.gender, birthday: card.birthDate };
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v));
}

const FIELDS = [
    ['employee_code', 'Personnel ID (CPR)'],
    ['name', 'First name'],
    ['last_name', 'Last name'],
    ['gender', 'Gender'],
    ['birthday', 'Birthday'],
];

const shown = (key, value) => {
    if (!value) return '—';
    if (key === 'birthday') return isoToDmy(value);
    if (key === 'gender') return value === 'male' ? 'Male' : value === 'female' ? 'Female' : value;
    return value;
};

export default function IdCardReadButton({ form, isEdit, onApply, onPhoto, currentPhoto = null, onDialogChange, disabled = false }) {
    const toast = useToast();
    const [reading, setReading] = useState(false);
    const [offer, setOfferState] = useState(null); // { values, photo, otherPerson }
    // The form pauses its own Escape while this dialog is open, so Escape closes only this one.
    const setOffer = (next) => {
        setOfferState(next);
        onDialogChange?.(next !== null);
    };

    async function read() {
        setReading(true);
        try {
            const card = await readCard(undefined, true, true); // with the photo: about 4 s
            const values = cardToForm(card);
            const photo = card.photo ?? null; // null too for a JPEG 2000 photo, which browsers cannot show
            if (Object.keys(values).length === 0) {
                toast.error(MESSAGES.failed);
                return;
            }
            const current = (form.employee_code ?? '').trim();
            const otherPerson = Boolean(isEdit && current && values.employee_code && values.employee_code !== current);
            const replaces = Object.keys(values).some((k) => (form[k] ?? '') !== '' && form[k] !== values[k])
                || Boolean(photo && currentPhoto);

            if (!isEdit && !replaces) {
                onApply(values);
                if (photo) onPhoto?.(photo);
                toast.success('Filled in from the ID card. Check the details, then Save.');
                return;
            }
            if (!otherPerson && !photo && Object.keys(values).every((k) => form[k] === values[k])) {
                toast.info('The ID card matches what is on file.');
                return;
            }
            setOffer({ values, photo, otherPerson });
        } catch (e) {
            if (e?.name === 'AbortError') return;
            toast.error(MESSAGES[cardErrorCode(e)] ?? MESSAGES.service_error);
        } finally {
            setReading(false);
        }
    }

    return (
        <>
            <Button variant="soft" onClick={read} loading={reading} disabled={disabled || reading}>
                Read ID card
            </Button>
            {offer && (
                <CompareDialog
                    form={form}
                    values={offer.values}
                    photo={offer.photo}
                    currentPhoto={currentPhoto}
                    otherPerson={offer.otherPerson}
                    onClose={() => setOffer(null)}
                    onApply={(patch, takePhoto) => {
                        onApply(patch);
                        if (takePhoto && offer.photo) onPhoto?.(offer.photo);
                        setOffer(null);
                        toast.success('Applied from the ID card. Check the details, then Save.');
                    }}
                />
            )}
        </>
    );
}

function CompareDialog({ form, values, photo, currentPhoto, otherPerson, onClose, onApply }) {
    const rows = FIELDS.filter(([k]) => values[k]);
    const [ticked, setTicked] = useState(() => new Set(
        otherPerson ? [] : rows.filter(([k]) => (form[k] ?? '') !== values[k]).map(([k]) => k),
    ));
    // Ticked only when there is no photo yet; replacing one is always the operator's choice.
    const [takePhoto, setTakePhoto] = useState(() => Boolean(photo && !currentPhoto && !otherPerson));
    const [photoUrl] = useState(() => (photo ? URL.createObjectURL(photo) : null));
    useEffect(() => () => { if (photoUrl) URL.revokeObjectURL(photoUrl); }, [photoUrl]);
    useEscapeKey(onClose);

    const toggle = (k) => setTicked((t) => {
        const next = new Set(t);
        next.has(k) ? next.delete(k) : next.add(k);
        return next;
    });

    return (
        <div className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={onClose} role="presentation">
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="idcard-title">
                <div className="p-5">
                    <h2 id="idcard-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">ID card</h2>
                    <p className="mt-0.5 text-sm text-slate-500">Tick what to take from the card. Nothing is saved until you press Save.</p>

                    {otherPerson && (
                        <div className="mt-4">
                            <InlineAlert tone="error">
                                This card's CPR is not this employee's Personnel ID. It may be someone else's card, so nothing is ticked.
                            </InlineAlert>
                        </div>
                    )}

                    <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-medium text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Apply</th>
                                    <th className="px-3 py-2">Field</th>
                                    <th className="px-3 py-2">On the card</th>
                                    <th className="px-3 py-2">On file</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map(([k, label]) => {
                                    const same = (form[k] ?? '') === values[k];
                                    return (
                                        <tr key={k} className={same ? 'text-slate-500' : 'text-slate-900'}>
                                            <td className="px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Apply ${label}`}
                                                    checked={ticked.has(k)}
                                                    disabled={same}
                                                    onChange={() => toggle(k)}
                                                    className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                                />
                                            </td>
                                            <td className="px-3 py-2 font-medium">{label}</td>
                                            <td className="px-3 py-2">{shown(k, values[k])}</td>
                                            <td className="px-3 py-2">{same ? 'Same' : shown(k, form[k])}</td>
                                        </tr>
                                    );
                                })}
                                {photo && (
                                    <tr className="text-slate-900">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label="Apply Photo"
                                                checked={takePhoto}
                                                onChange={() => setTakePhoto((v) => !v)}
                                                className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                            />
                                        </td>
                                        <td className="px-3 py-2 font-medium">Photo</td>
                                        <td className="px-3 py-2">
                                            <img src={photoUrl} alt="Photo on the ID card" className="h-16 w-12 rounded-md object-cover ring-1 ring-slate-200" />
                                        </td>
                                        <td className="px-3 py-2">
                                            {currentPhoto
                                                ? <img src={currentPhoto} alt="Photo on file" className="h-16 w-12 rounded-md object-cover ring-1 ring-slate-200" />
                                                : '—'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button
                            onClick={() => onApply(Object.fromEntries([...ticked].map((k) => [k, values[k]])), takePhoto)}
                            disabled={ticked.size === 0 && !takePhoto}
                        >
                            Apply {ticked.size + (takePhoto ? 1 : 0) || ''} {ticked.size + (takePhoto ? 1 : 0) === 1 ? 'field' : 'fields'}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
