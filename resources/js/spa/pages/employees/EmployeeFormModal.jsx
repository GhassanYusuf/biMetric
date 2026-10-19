import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, SelectField, Spinner, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { enrolledTargets, writableTargets } from './DeviceTargets';
import { useEscapeKey } from '../../shared/useEscapeKey';
import TransferPicker from './TransferPicker';
import { slotName } from './FingerPicker';
import { formatDeviceError } from '../devices/statusParts';
import { can as canDevice } from '../devices/parts';
import { FixButton, useTerminalFix } from './TerminalFixes';
import Combobox from '../../shared/Combobox';
import { CountrySelect, PhoneField, phoneError } from '../../shared/CountryFields';

/*
| Which tab each server-side field lives on, so a 422 can open the tab holding the
| first error — an error on a hidden tab is an error nobody can see.
*/
const TABS = [
    { key: 'profile', label: 'Personnel profile' },
    { key: 'details', label: 'Details' },
    { key: 'access', label: 'Access levels' },
];
const DETAIL_FIELDS = [
    'job_title', 'office', 'education', 'political_status', 'race', 'country', 'city',
    'postal_code', 'home_phone', 'home_address', 'work_address',
];
const ACCESS_FIELDS = ['multi_card_group_id', 'valid_period_enabled', 'access_valid_from', 'access_valid_to'];

const EMPLOYMENT_TYPES = [
    { value: 'full_time', label: 'Full-time' },
    { value: 'part_time', label: 'Part-time' },
];

/*
| COMPANY › DEPARTMENT (2026-10-12). The tree is one table (`departments.parent_id`):
| a company is a node with no parent, its departments sit beneath it. The employee
| holds ONE `department_id` — the department when one is chosen, else the company.
*/
function rootOf(id, byId) {
    let node = byId.get(id);
    const seen = new Set();
    while (node && node.parent_id != null && byId.has(node.parent_id) && !seen.has(node.id)) {
        seen.add(node.id);
        node = byId.get(node.parent_id);
    }
    return node ?? null;
}

/** Every department beneath a company, deeper levels labelled by their path. */
function departmentsUnder(companyId, list) {
    const out = [];
    const walk = (parentId, prefix) => {
        list.filter((d) => d.parent_id === parentId)
            .sort((a, b) => a.name.localeCompare(b.name))
            .forEach((d) => {
                const label = prefix ? `${prefix} › ${d.name}` : d.name;
                out.push({ value: d.id, label });
                if (out.length < 1000) walk(d.id, label);
            });
    };
    if (companyId != null) walk(companyId, '');
    return out;
}

function tabOf(field) {
    const base = field.split('.')[0];
    if (DETAIL_FIELDS.includes(base)) return 'details';
    if (ACCESS_FIELDS.includes(base)) return 'access';
    return 'profile';
}

const PRIVILEGES = [
    { value: 'user', label: 'User' },
    { value: 'enroller', label: 'Enroller' },
    { value: 'admin', label: 'Admin' },
    { value: 'superadmin', label: 'Super admin' },
];

const MAX_PHOTO_BYTES = 4 * 1024 * 1024; // mirrors the server's `max:4096`

// This firmware family's empty card field (AccessCard::SENTINEL) — not a card, so
// the field starts empty rather than offering it back as a number.
const CARD_EMPTY_MARKER = 4294967295;

/*
| Add / edit form (modal on desktop, sheet on mobile). Validation errors come back
| as JSON and render inline with no page reload.
|
| The employee code is SUGGESTED, not imposed: it prefills with the lowest free
| code so the common case is one less thing to think about, and stays a plain
| editable field for anyone who numbers staff their own way. Once you type in it,
| the suggestion stops touching your value. On an EDIT there is no suggestion — the
| code is theirs already, and the terminals address them by a PIN derived from it.
|
| THE DEVICE SECTION IS THE POINT OF THE EDIT FORM. Saving writes the database and
| nothing else; a name corrected here would otherwise stay wrong on every terminal
| until somebody remembered to open the enrollment dialog and push, device by
| device, with nothing on screen saying so. On an edit every terminal they are on
| starts on the right, so updating a person you have just corrected is the default;
| on a create the right list starts empty, because which terminals a new joiner
| belongs on is a real decision nobody else can make.
|
| THE PERSONNEL TABS (2026-09-23). Profile keeps every field this form always had;
| the rest are additions modelled on the ZKAccess Personnel dialog. EVERY NEW FIELD
| IS STORED IN THIS APP ONLY. The terminal push still writes exactly what it wrote
| before — PIN and name — and never the device password, privilege or access levels,
| until the owner approves those writes.
|
| THE TERMINALS ARE CHOSEN ON THE ACCESS LEVELS TAB (2026-09-24), in the ZKAccess
| two-list style, and replace the old checklist: right = on it (push, then read back),
| moved left = take them off (confirmed, destructive). The abstract, database-only
| access levels were dropped from this form at the owner's direction; their tables
| and endpoints remain, unused here.
|
| AFTER A SAVE THAT TOUCHES TERMINALS the form stays open and the device section
| becomes one result row per terminal per step, with Retry on the ones that failed.
| With no terminal involved it closes exactly as it always did.
*/
export default function EmployeeFormModal({
    employee = null, devices = [], onSubmit, onClose,
    // Closes after a result view; the caller refreshes the roster.
    onDone = onClose,
    // (employee, deviceIds) → the propagate result, for one terminal's Retry.
    onRetry = null,
    // (employee, 'terminal') → opens the existing enrollment dialog. Null hides it.
    onEnrollFingerprint = null,
    // (employee) → copies their fingerprints from the terminals into the system. Null hides it.
    onReadFingerprints = null,
    // Company setting: saves push without the "Push now" question.
    autoPush = false,
    // The Company › Department tree, as GET /departments returns it.
    departments = [],
    // (name, parentId|null) → the created department, or null. Null hides "Add".
    onCreateDepartment = null,
}) {
    const isEdit = !!employee;

    // Departments created from this form are used at once, before the page reloads the tree.
    const [createdDepartments, setCreatedDepartments] = useState([]);
    const allDepartments = [...departments, ...createdDepartments]
        .map((d) => ({ ...d, id: Number(d.id), parent_id: d.parent_id == null ? null : Number(d.parent_id) }))
        .filter((d, i, list) => list.findIndex((x) => x.id === d.id) === i);
    const departmentById = new Map(allDepartments.map((d) => [d.id, d]));
    // Numbers throughout: SQL Server hands some ids back as strings ("4"), and a strict
    // comparison of "4" with 4 left the Department box empty (2026-10-12).
    const employeeDeptId = employee?.department_id != null ? Number(employee.department_id) : null;
    const initialCompany = (employee?.company?.id != null ? Number(employee.company.id) : null)
        ?? (employeeDeptId ? rootOf(employeeDeptId, departmentById)?.id : null)
        ?? null;

    const [form, setForm] = useState({
        name: employee?.name ?? '',
        employee_code: employee?.employee_code ?? '',
        email: employee?.email ?? '',
        company_id: initialCompany ?? '',
        // The node itself when it is a department; empty when they sit on the company.
        unit_id: employeeDeptId && employeeDeptId !== initialCompany ? employeeDeptId : '',
        employment_type: employee?.employment_type ?? 'full_time',
        resigned_at: employee?.resigned_at ?? '',
        card_number: employee?.card_number && employee.card_number !== CARD_EMPTY_MARKER ? String(employee.card_number) : '',
        is_active: employee?.is_active ?? true,
        // Profile additions
        last_name: employee?.last_name ?? '',
        gender: employee?.gender ?? '',
        mobile_phone: employee?.mobile_phone ?? '',
        hired_date: employee?.hired_date ?? '',
        birthday: employee?.birthday ?? '',
        device_password: '', // never sent to the browser; typing replaces it
        // A new person defaults to User. An existing one with none recorded stays
        // "Not set" — nobody's privilege has been read off a terminal (rule 3).
        privilege: employee ? (employee.privilege ?? '') : 'user',
        // Details
        ...Object.fromEntries(DETAIL_FIELDS.map((k) => [k, employee?.[k] ?? ''])),
        // WRITE-ONLY: the stored number never reaches the browser (only a masked
        // tail does), so the box starts empty and typing replaces it.
        // Access levels
        multi_card_group_id: employee?.multi_card_group_id ?? '',
        valid_period_enabled: employee?.valid_period_enabled ?? false,
        access_valid_from: employee?.access_valid_from ?? '',
        access_valid_to: employee?.access_valid_to ?? '',
    });
    const [tab, setTab] = useState('profile');
    // Omitted from the payload unless typed in, so saving an edit never wipes a
    // password this screen cannot see.
    const passwordTouched = useRef(false);
    const [multiCardGroups, setMultiCardGroups] = useState([]);
    const [photoFile, setPhotoFile] = useState(null);
    const [photoPreview, setPhotoPreview] = useState(null);
    const [photoRemoved, setPhotoRemoved] = useState(false);
    const photoInput = useRef(null);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [suggestion, setSuggestion] = useState(null);
    const [loadingSuggestion, setLoadingSuggestion] = useState(!isEdit);
    // { employee, result: {written, skipped, failed} } once a save has pushed.
    const [outcome, setOutcome] = useState(null);
    const [retrying, setRetrying] = useState([]); // device ids being retried
    const fixTerminal = useTerminalFix();
    const [readingFingers, setReadingFingers] = useState(false);

    /*
     * THE TERMINALS, on the Access levels tab (owner's model, 2026-09-24): the right
     * list is the terminals this person is on. Moving one right means "put them on
     * it" — push, then read their fingerprints and card back. Moving one left means
     * "take them off it", which deletes their enrollment there and is confirmed as a
     * destructive action after the save.
     *
     * Right starts as their LIVE mappings — `enrolledTargets` drops closed ones, which
     * are PINs they used to hold and which somebody else may hold today. Left offers
     * every terminal that can take a write. A terminal they are on that this app
     * cannot write to (a C3 panel) sits on the right, LOCKED: moving it left would
     * promise a removal nothing here can perform.
     */
    const enrolled = isEdit ? enrolledTargets(employee) : [];
    const writable = writableTargets(devices);
    const writableIds = writable.map((t) => t.id);
    const enrolledIds = enrolled.map((t) => t.id);
    const terminalItems = [
        ...enrolled.map((t) => {
            const drifted = t.held && t.held !== form.name;
            return {
                id: t.id,
                name: t.name,
                detail: [
                    t.pin != null ? `user ID ${t.pin}` : null,
                    drifted ? `holds “${t.held}”` : null,
                    writableIds.includes(t.id) ? null : 'read-only here',
                ].filter(Boolean).join(' · '),
            };
        }),
        ...writable.filter((t) => !enrolledIds.includes(t.id)).map((t) => ({ id: t.id, name: t.name, detail: 'not on it' })),
    ];
    const lockedIds = enrolledIds.filter((id) => !writableIds.includes(id));

    // A resigned person still on a terminal (the removal was declined, or failed) opens
    // with those terminals already moved off, so the next save offers it again.
    const [onTerminals, setOnTerminals] = useState(() => (employee?.resigned_at ? lockedIds : enrolledIds));
    /*
     * A panel that takes no names (a C3) but can move a user to a new ID is pushed to
     * when the Personnel ID differs from its user ID there — the server then does only
     * the ID move (2026-09-24). It stays locked on the right: nothing here removes them.
     */
    const codeNow = String(form.employee_code).trim();
    const renumberOnly = enrolled
        .filter((t) => !writableIds.includes(t.id) && t.pin != null && String(t.pin) !== codeNow && /^\d{1,9}$/.test(codeNow))
        .filter((t) => canDevice(devices.find((d) => d.id === t.id), 'user.renumber'))
        .map((t) => t.id);
    /*
     * RESIGNED (2026-10-12, owner: "take them off the system"). A newly entered
     * resignation date moves every terminal they are on off the right-hand list — see
     * set() — so the save removes them through the normal removal, which keeps their
     * card and fingers in the system first (rule 19). Nothing is pushed to a resigned
     * person.
     */
    const resigned = form.resigned_at !== '';
    const wanted = resigned ? [] : onTerminals.filter((id) => writableIds.includes(id) || renumberOnly.includes(id));
    /*
     * PUSH ONLY WHAT IS OUT OF DATE (2026-10-12, owner: "if nothing changed you don't
     * need to push"). A push is a session, a write and a read-back per terminal —
     * 20 to 40 seconds a save — and a terminal holds only the name, the user ID, the
     * card and the fingerprints. Editing a job title, a phone or a company changes
     * none of those, so those saves no longer touch a terminal.
     *
     * A terminal IS written when: it is newly added; the name, ID or card changed in
     * this form; or what it held at its last read differs from the system — another
     * name, another ID it can be moved off, no card where the system has one, or
     * missing a finger the system keeps a copy of. Unknown (never read) counts as out
     * of date for the name, and as up to date for fingers: an explicit Push still
     * writes everything.
     *
     * The keypad password counts only where the model's password.write is CONFIRMED
     * (2026-10-12); the server holds the write back everywhere else. The device role
     * is not sent to terminals, so it cannot make one out of date.
     */
    const initialCard = employee?.card_number && employee.card_number !== CARD_EMPTY_MARKER ? String(employee.card_number) : '';
    const cardChanged = form.card_number.trim() !== initialCard;
    const nameChanged = isEdit && form.name.trim() !== (employee.name ?? '');
    const systemCard = employee?.card_number && employee.card_number !== CARD_EMPTY_MARKER ? String(employee.card_number) : null;
    const storedFingers = (employee?.stored_fingerprint_slots ?? []).map(Number);
    // A terminal keeps at most 24 characters of a name (seen on the F18, TF1700 and
    // inBIO460, 2026-10-12): its first 24 IS the name, not a difference to push.
    const sameName = (held, name) => held != null && (held === name || (held.length >= 24 && name.startsWith(held)));
    const passwordChanged = passwordTouched.current && form.device_password !== '';
    const passwordWritable = (id) => devices.find((d) => d.id === id)?.capabilities?.['password.write']?.confirmed === true;
    function outOfDate(id) {
        const t = enrolled.find((e) => e.id === id);
        if (!t || nameChanged || cardChanged) return true; // newly added, or edited here
        // A new keypad password, where this terminal's model has had a password write
        // read back (password.write CONFIRMED). Elsewhere a push would not write it.
        if (passwordChanged && passwordWritable(id)) return true;
        if (renumberOnly.includes(id)) return true;          // its user ID moves to the new code
        if (writableIds.includes(id)) {
            if (!sameName(t.held, form.name.trim())) return true;
            const code = String(form.employee_code).trim();
            if (t.pin != null && String(t.pin) !== code && /^\d{1,9}$/.test(code)
                && canDevice(devices.find((d) => d.id === id), 'user.renumber')) return true;
            if (systemCard && String(t.card ?? '') !== systemCard) return true;
            if (t.fingers && storedFingers.some((slot) => !t.fingers.includes(slot))) return true;
        }
        return false;
    }
    const pushTo = wanted.filter(outOfDate);
    // On the list and staying, but nothing to write — said in the footer, not hidden.
    const upToDate = wanted.filter((id) => !pushTo.includes(id));
    const removeFrom = enrolledIds.filter((id) => writableIds.includes(id) && !onTerminals.includes(id));
    const addedTo = pushTo.filter((id) => !enrolledIds.includes(id));
    const terminalName = (id) => terminalItems.find((t) => t.id === id)?.name ?? `Terminal ${id}`;
    // Tracks whether the operator has taken over the field, so a late-arriving
    // suggestion can never overwrite something they typed.
    const codeTouched = useRef(false);

    const busy = submitting || retrying.length > 0;
    const close = outcome ? onDone : onClose;

    useEscapeKey(close, !busy);

    useEffect(() => {
        // An existing employee already has a code, and changing it changes the PIN
        // the terminals know them by. Never suggest over it.
        if (isEdit) return undefined;

        let cancelled = false;

        api.get('/employees/next-code')
            .then((res) => {
                if (cancelled) return;
                setSuggestion(res.data.code);
                if (!codeTouched.current) {
                    setForm((f) => (f.employee_code === '' ? { ...f, employee_code: res.data.code } : f));
                }
            })
            // A failed suggestion is not an error worth showing — the field is
            // still perfectly usable by hand.
            .catch(() => {})
            .finally(() => {
                if (!cancelled) setLoadingSuggestion(false);
            });

        return () => { cancelled = true; };
    }, [isEdit]);

    // Multi-card groups for the Access levels tab. A failed load leaves the list
    // empty, which the tab shows as "none set up" — the rest of the form still works.
    useEffect(() => {
        let cancelled = false;
        api.get('/multi-card-groups').then((res) => { if (!cancelled) setMultiCardGroups(res.data ?? []); }).catch(() => {});
        return () => { cancelled = true; };
    }, []);

    useEffect(() => () => { if (photoPreview) URL.revokeObjectURL(photoPreview); }, [photoPreview]);

    function set(key, value) {
        if (key === 'employee_code') codeTouched.current = true;
        if (key === 'device_password') passwordTouched.current = true;
        if (key === 'resigned_at') {
            // Entered: off every terminal this app can write to. Cleared again: back as they were.
            if (value !== '' && form.resigned_at === '') setOnTerminals(lockedIds);
            if (value === '' && form.resigned_at !== '') setOnTerminals(enrolledIds);
        }
        // A new company empties the department: it belonged to the old one.
        if (key === 'company_id') {
            setForm((f) => ({ ...f, company_id: value, unit_id: '' }));
            return;
        }
        setForm((f) => ({ ...f, [key]: value, ...(key === 'resigned_at' && value !== '' ? { is_active: false } : {}) }));
    }

    async function createDepartment(name, parentId) {
        const made = await onCreateDepartment(name, parentId);
        if (!made) return null;
        setCreatedDepartments((list) => [...list, made]);
        return { value: Number(made.id), label: made.name };
    }

    function pickPhoto(file) {
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            setErrors((e) => ({ ...e, photo: ['Choose an image file.'] }));
            return;
        }
        if (file.size > MAX_PHOTO_BYTES) {
            setErrors((e) => ({ ...e, photo: ['The photo must be 4 MB or smaller.'] }));
            return;
        }
        setErrors(({ photo, ...rest }) => rest);
        setPhotoFile(file);
        setPhotoPreview(URL.createObjectURL(file));
        setPhotoRemoved(false);
    }

    function removePhoto() {
        setPhotoFile(null);
        setPhotoPreview(null);
        setPhotoRemoved(true);
        if (photoInput.current) photoInput.current.value = '';
    }

    /*
     * Mirrors the server rules so the common mistakes are caught before a round
     * trip. The server stays the authority and re-checks every one of them.
     */
    function validate() {
        const found = {};
        if (!form.name.trim()) found.name = ['Enter a name.'];
        const code = String(form.employee_code).trim();
        if (!code) found.employee_code = ['Enter a personnel ID.'];
        // Existing non-numeric codes ("10-12") stay editable — only a NEW or CHANGED
        // code must be digits, exactly as the server checks it.
        else if ((!isEdit || code !== String(employee.employee_code)) && !/^\d{1,9}$/.test(code)) {
            found.employee_code = ['The personnel ID must be digits only, up to 9.'];
        }
        /*
         * A card lives on the terminals, not in this app: the column behind this field
         * is what a terminal last reported (rule 10). So a changed card needs somewhere
         * to be written — at least one ticked terminal — or it would be a number typed
         * into a form and stored nowhere.
         */
        if (cardChanged) {
            const card = form.card_number.trim();
            if (card !== '' && !/^\d{1,10}$/.test(card)) found.card_number = ['Enter the card number as digits only, up to 10.'];
            else if (card !== '' && (Number(card) < 1 || Number(card) >= CARD_EMPTY_MARKER)) found.card_number = ['A card number must be between 1 and 4294967294.'];
            else if (pushTo.length === 0) found.card_number = ['Add a terminal on the Access levels tab to write the card to. Cards are stored on the terminals.'];
        }
        for (const key of ['mobile_phone', 'home_phone']) {
            const problem = phoneError(form[key]);
            if (problem) found[key] = [problem];
        }
        if (passwordTouched.current && form.device_password !== '' && !/^\d{1,8}$/.test(form.device_password)) {
            found.device_password = ['The device password must be 1 to 8 digits.'];
        }
        if (form.valid_period_enabled) {
            if (!form.access_valid_from) found.access_valid_from = ['Give a start date, or turn the valid period off.'];
            if (!form.access_valid_to) found.access_valid_to = ['Give an end date, or turn the valid period off.'];
            else if (form.access_valid_from && form.access_valid_to <= form.access_valid_from) {
                found.access_valid_to = ['The end date must be after the start date.'];
            }
        }
        return found;
    }

    function showErrors(next) {
        setErrors(next);
        // Open the tab holding the first error, in tab order.
        const withError = TABS.find((t) => Object.keys(next).some((f) => tabOf(f) === t.key));
        if (withError) setTab(withError.key);
    }

    async function submit(e) {
        e.preventDefault();
        if (outcome) return;

        const found = validate();
        if (Object.keys(found).length > 0) {
            showErrors(found);
            return;
        }

        setSubmitting(true);
        setErrors({});
        try {
            const result = await onSubmit(
                {
                    name: form.name,
                    employee_code: form.employee_code,
                    email: form.email || null,
                    // The department when chosen, else the company. The server keeps the
                    // old free-text `department` in step with it.
                    department_id: Number(form.unit_id || form.company_id) || null,
                    employment_type: form.employment_type,
                    // Only the update endpoint accepts it; sending it on create
                    // would be a silently ignored field.
                    ...(isEdit ? { is_active: form.is_active, resigned_at: form.resigned_at || null } : {}),
                    // Personnel-tab fields: stored only, never pushed to a terminal.
                    last_name: form.last_name || null,
                    gender: form.gender || null,
                    mobile_phone: form.mobile_phone || null,
                    hired_date: form.hired_date || null,
                    birthday: form.birthday || null,
                    privilege: form.privilege || null,
                    ...(passwordTouched.current ? { device_password: form.device_password || null } : {}),
                    ...Object.fromEntries(DETAIL_FIELDS.map((k) => [k, form[k] || null])),
                    multi_card_group_id: form.multi_card_group_id ? Number(form.multi_card_group_id) : null,
                    valid_period_enabled: form.valid_period_enabled,
                    access_valid_from: form.valid_period_enabled ? form.access_valid_from || null : null,
                    access_valid_to: form.valid_period_enabled ? form.access_valid_to || null : null,
                },
                // The terminals to write to AFTER the save succeeds. The caller
                // owns that second step, because it is a different operation with
                // its own outcome — the save can succeed while a terminal is
                // read-only, and both facts have to reach the operator.
                pushTo,
                // The photo rides its own request after the save.
                photoFile ?? (photoRemoved && employee?.photo_url ? 'remove' : null),
                // The card rides its own verified write per terminal, after the push:
                // a digit string, '' to remove it, or null for "unchanged".
                cardChanged ? form.card_number.trim() : null,
                // Terminals moved off the right list; the caller confirms before removing.
                removeFrom,
            );
            // Terminals were written: stay open and show a row per terminal.
            if (result?.result) setOutcome(result);
        } catch (err) {
            if (err instanceof ApiError) {
                showErrors(err.errors || {});
            } else {
                setErrors({ name: ['Something went wrong.'] });
                setTab('profile');
            }
        } finally {
            setSubmitting(false);
        }
    }

    /*
     * The fix a red terminal row names (2026-10-11, casebook DC-014), pressed by the
     * operator — every write here is theirs to approve:
     *   release_number          the code is held there by a record linked to no
     *                           employee: save it and its fingerprints, remove it,
     *                           give the number to this employee;
     *   test_fingerprint_write  the model never took a fingerprint from this app:
     *                           add one finger the system holds to someone missing it.
     * Then that terminal is pushed again.
     */
    async function resolveFailure(failure, mode) {
        setRetrying([failure.device_id]);
        const fixed = await fixTerminal(outcome.employee, failure, mode);
        setRetrying([]);
        if (fixed) await retry([failure.device_id]);
    }

    async function retry(deviceIds) {
        if (!onRetry || !outcome || deviceIds.length === 0) return;
        setRetrying(deviceIds);
        try {
            const again = await onRetry(outcome.employee, deviceIds);
            if (!again) return;
            // Replace those terminals' rows with the new answer; keep every other row.
            const drop = (list) => list.filter((row) => !deviceIds.includes(row.device_id));
            setOutcome((o) => ({
                ...o,
                result: {
                    written: [...drop(o.result.written), ...(again.written ?? [])],
                    skipped: [...drop(o.result.skipped), ...(again.skipped ?? [])],
                    failed: [...drop(o.result.failed), ...(again.failed ?? [])],
                    cards: [...drop(o.result.cards ?? []), ...(again.cards ?? [])],
                    reads: [...drop(o.result.reads ?? []), ...(again.reads ?? [])],
                },
            }));
        } finally {
            setRetrying([]);
        }
    }

    const usingSuggestion = suggestion != null && form.employee_code === suggestion;
    const tabHasError = (key) => Object.keys(errors).some((f) => tabOf(f) === key);
    const err = (key) => errors[key]?.[0];
    const shownPhoto = photoPreview ?? (!photoRemoved ? employee?.photo_url : null);
    const failedIds = outcome
        ? [...new Set([
            ...outcome.result.failed.map((f) => f.device_id),
            ...(outcome.result.cards ?? []).filter((c) => !c.ok && !c.skipped).map((c) => c.device_id),
            ...(outcome.result.reads ?? []).filter((c) => !c.ok && !c.skipped).map((c) => c.device_id),
        ])]
        : [];

    const codeHint = isEdit ? 'Digits only, up to 9.' : null;

    const companyOptions = allDepartments
        .filter((d) => d.parent_id == null || !departmentById.has(d.parent_id))
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((d) => ({ value: d.id, label: d.name }));
    const companyId = form.company_id === '' ? null : Number(form.company_id);
    const unitOptions = departmentsUnder(companyId, allDepartments);
    // Terminals the resignation takes them off — what the save will do, said beside the date.
    const resignRemoves = resigned ? removeFrom.map(terminalName) : [];

    // Which terminals a changed Personnel ID would move on, and which keep the old one.
    const newCode = String(form.employee_code).trim();
    const idPlan = isEdit && /^\d{1,9}$/.test(newCode) && newCode !== String(employee.employee_code)
        ? enrolled.reduce((plan, t) => {
            if (t.pin == null || String(t.pin) === newCode) return plan;
            const label = `${t.name} (${t.pin})`;
            (canDevice(devices.find((d) => d.id === t.id), 'user.renumber') ? plan.moves : plan.keeps).push(label);
            return plan;
        }, { moves: [], keeps: [] })
        : null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={busy ? undefined : close}
            role="presentation"
        >
            {/*
              | THE SHAPE OF THE ZKACCESS PERSONNEL DIALOG (owner's request, 2026-09-24),
              | in this app's colours: title bar with a close button, folder tabs over a
              | framed panel, labels to the LEFT of their fields, the photo column on the
              | right, wide buttons bottom-right. Fixed height, so switching tabs never
              | makes the dialog jump; the panel scrolls, the footer stays put.
            */}
            <Card
                className="flex h-[92vh] w-full max-w-[64rem] flex-col rounded-b-none sm:h-[min(92vh,700px)] sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
                    <div className="flex items-center justify-between gap-3 px-5 pb-3 pt-4">
                        <h2 className="truncate text-base font-semibold tracking-[-0.01em] text-slate-900">
                            {isEdit ? `Edit ${employee.name}` : 'Add employee'}
                        </h2>
                        <button
                            type="button"
                            onClick={close}
                            disabled={busy}
                            aria-label="Close"
                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-40 ${FOCUS}`}
                        >
                            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                                <path d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>

                    <Tabs tab={tab} onTab={setTab} hasError={tabHasError} accessCount={onTerminals.length} />

                    <fieldset disabled={!!outcome} className="mx-3 min-h-0 flex-1 overflow-y-auto rounded-b-xl rounded-tr-xl border border-slate-200 p-4 sm:mx-5 sm:p-6">
                        {/*
                          | Personnel profile: two label-left columns read top to bottom,
                          | photo on the right, fingerprints underneath. `grid-flow-col` with
                          | six rows keeps each left/right pair on one line while the DOM —
                          | and so the mobile stack and the tab order — runs down the left
                          | column first, as the original does.
                        */}
                        <div role="tabpanel" id="employee-tab-profile" aria-labelledby="employee-tabtrigger-profile" hidden={tab !== 'profile'} className="space-y-5">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_13rem]">
                                {/* Fills down then across, so the row count must hold every field:
                                    15 when editing (Resigned on is edit-only), 14 when adding. Too few
                                    rows spills the rest into a third, squashed column (2026-10-12). */}
                                <div className={`grid grid-cols-1 gap-x-8 gap-y-3 md:grid-flow-col md:grid-cols-2 ${isEdit ? 'md:grid-rows-[repeat(8,auto)]' : 'md:grid-rows-[repeat(7,auto)]'}`}>
                                    <Row label="Personnel ID" htmlFor="employee-code" required>
                                        <TextField
                                            id="employee-code"
                                            value={form.employee_code}
                                            onChange={(e) => set('employee_code', e.target.value)}
                                            error={errors.employee_code?.[0]}
                                            inputMode="numeric"
                                            title={codeHint ?? undefined}
                                            required
                                        />
                                        {/*
                                          | STATE, so content (rule 2): what a push will do to the
                                          | ID on each terminal they are on. A terminal with
                                          | `user.renumber` moves them (delete + re-upload, card and
                                          | fingerprints kept, 2026-09-24); one without keeps the old
                                          | ID. Per terminal, because the two now coexist.
                                        */}
                                        {isEdit && !errors.employee_code && idPlan && (
                                            <div className="mt-1 space-y-0.5 text-xs">
                                                {idPlan.moves.length > 0 && (
                                                    <span className="block text-slate-600">
                                                        Moves to this ID on {idPlan.moves.join(', ')} when you push — card and fingerprints kept.
                                                    </span>
                                                )}
                                                {idPlan.keeps.length > 0 && (
                                                    <span className="block font-medium text-amber-700">
                                                        Keeps the old ID on {idPlan.keeps.join(', ')} — {idPlan.keeps.length === 1 ? 'that terminal cannot' : 'those terminals cannot'} change an ID.
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                        {!isEdit && !errors.employee_code && (
                                            <div className="mt-1 flex flex-wrap items-center gap-x-2 text-xs">
                                                {loadingSuggestion ? (
                                                    <span className="inline-flex items-center gap-1.5 text-slate-500">
                                                        <Spinner className="h-3 w-3" /> finding the next free code…
                                                    </span>
                                                ) : suggestion == null ? (
                                                    <span className="text-slate-500">Digits only, up to 9.</span>
                                                ) : usingSuggestion ? (
                                                    <span className="text-slate-500">Lowest free code.</span>
                                                ) : (
                                                    <>
                                                        <span className="text-slate-500">Lowest free is {suggestion}.</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => setForm((f) => ({ ...f, employee_code: suggestion }))}
                                                            className={`rounded font-medium text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-800 ${FOCUS}`}
                                                        >
                                                            Use it
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </Row>
                                    <Row label="Name" htmlFor="employee-name" required>
                                        <TextField id="employee-name" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name?.[0]} required autoFocus />
                                    </Row>
                                    <Row label="Last name" htmlFor="employee-last-name">
                                        <TextField id="employee-last-name" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} error={err('last_name')} />
                                    </Row>
                                    <Row label="Gender" htmlFor="employee-gender">
                                        <SelectField id="employee-gender" value={form.gender} onChange={(e) => set('gender', e.target.value)} error={err('gender')}>
                                            <option value="">Not set</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </SelectField>
                                    </Row>
                                    <Row label="Password" htmlFor="employee-device-password">
                                        <TextField
                                            id="employee-device-password"
                                            type="password"
                                            inputMode="numeric"
                                            autoComplete="new-password"
                                            maxLength={8}
                                            value={form.device_password}
                                            onChange={(e) => set('device_password', e.target.value.replace(/\D/g, ''))}
                                            placeholder={employee?.has_device_password ? 'Set — type to replace' : '1–8 digits'}
                                            title="1–8 digits, typed on the terminal keypad."
                                            error={err('device_password')}
                                        />
                                    </Row>
                                    <Row label="Email" htmlFor="employee-email">
                                        <TextField id="employee-email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} error={errors.email?.[0]} />
                                    </Row>

                                    <Row label="Company" htmlFor="employee-company">
                                        <Combobox
                                            id="employee-company"
                                            options={companyOptions}
                                            value={companyId}
                                            onChange={(v) => set('company_id', v ?? '')}
                                            placeholder="Search…"
                                            emptyText="No company matches."
                                            onCreate={onCreateDepartment ? (name) => createDepartment(name, null) : null}
                                            createLabel={(q) => `Add company “${q}”`}
                                            allowClear
                                            error={!form.unit_id ? err('department_id') : undefined}
                                        />
                                    </Row>
                                    <Row label="Department" htmlFor="employee-unit">
                                        <Combobox
                                            id="employee-unit"
                                            options={unitOptions}
                                            value={form.unit_id === '' ? null : Number(form.unit_id)}
                                            onChange={(v) => set('unit_id', v ?? '')}
                                            disabled={companyId === null}
                                            placeholder={companyId === null ? 'Company first' : 'Search…'}
                                            emptyText={unitOptions.length === 0 ? 'This company has no departments yet.' : 'No department matches.'}
                                            onCreate={onCreateDepartment && companyId !== null ? (name) => createDepartment(name, companyId) : null}
                                            createLabel={(q) => `Add department “${q}”`}
                                            allowClear
                                            error={form.unit_id ? err('department_id') : undefined}
                                        />
                                    </Row>
                                    {/*
                                      | EDITABLE, as in ZKAccess — but never stored here as
                                      | typed. The column is what a terminal reported, and the
                                      | identity layer trusts it as evidence (rule 10). A change
                                      | is written to each terminal on the Access levels tab
                                      | through the verified card endpoint and read back; only
                                      | then does the column change.
                                    */}
                                    <Row label="Card number" htmlFor="employee-card">
                                        <TextField
                                            id="employee-card"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            maxLength={10}
                                            className="font-mono"
                                            value={form.card_number}
                                            onChange={(e) => set('card_number', e.target.value.replace(/\D/g, ''))}
                                            placeholder="No card"
                                            title="As the terminal last reported it."
                                            hint={cardChanged
                                                ? (form.card_number.trim() === '' ? 'Removed from the terminals on save.' : 'Written to the terminals on save, then read back.')
                                                : null}
                                            error={err('card_number')}
                                        />
                                    </Row>
                                    <Row label="Mobile phone" htmlFor="employee-mobile">
                                        <PhoneField id="employee-mobile" value={form.mobile_phone} onChange={(v) => set('mobile_phone', v)} fallback={form.country} error={err('mobile_phone')} />
                                    </Row>
                                    <Row label="Employment" htmlFor="employee-employment-type">
                                        <SelectField id="employee-employment-type" value={form.employment_type} onChange={(e) => set('employment_type', e.target.value)} error={err('employment_type')}>
                                            {EMPLOYMENT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                                        </SelectField>
                                    </Row>
                                    <Row label="Hired date" htmlFor="employee-hired">
                                        <TextField id="employee-hired" type="date" value={form.hired_date} onChange={(e) => set('hired_date', e.target.value)} error={err('hired_date')} />
                                    </Row>
                                    {isEdit && (
                                        <Row label="Resigned on" htmlFor="employee-resigned">
                                            <TextField
                                                id="employee-resigned"
                                                type="date"
                                                max={new Date().toLocaleDateString('en-CA')}
                                                value={form.resigned_at}
                                                onChange={(e) => set('resigned_at', e.target.value)}
                                                error={err('resigned_at')}
                                                hint={resignRemoves.length > 0
                                                    ? `Saving takes them off ${resignRemoves.join(', ')}. Their card and fingerprints are kept here and put back if they are added again.`
                                                    : resigned ? 'Inactive, and kept off every terminal. Clear the date to bring them back.' : 'Leave empty while they work here.'}
                                            />
                                        </Row>
                                    )}
                                    <Row label="Birthday" htmlFor="employee-birthday">
                                        <TextField id="employee-birthday" type="date" value={form.birthday} onChange={(e) => set('birthday', e.target.value)} error={err('birthday')} />
                                    </Row>
                                    <Row label="Device role" htmlFor="employee-privilege">
                                        <SelectField id="employee-privilege" value={form.privilege} onChange={(e) => set('privilege', e.target.value)} error={err('privilege')}>
                                            {isEdit && <option value="">Not set</option>}
                                            {PRIVILEGES.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                                        </SelectField>
                                    </Row>
                                </div>

                                <PhotoPanel
                                    photo={shownPhoto}
                                    initial={(form.name.trim()[0] ?? '').toUpperCase()}
                                    inputRef={photoInput}
                                    onPick={pickPhoto}
                                    onRemove={removePhoto}
                                    error={err('photo')}
                                />
                            </div>

                            {onEnrollFingerprint !== null || !isEdit ? (
                                <Row label="Fingerprints">
                                    <div className="flex min-h-[42px] flex-wrap items-center gap-2">
                                        {isEdit ? (
                                            <>
                                                {onEnrollFingerprint && (
                                                    <Button type="button" variant="secondary" className="min-h-[36px] px-4 text-sm" onClick={() => onEnrollFingerprint(employee, 'terminal')}>
                                                        On a terminal
                                                    </Button>
                                                )}
                                                {onReadFingerprints && (
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        className="min-h-[36px] px-4 text-sm"
                                                        loading={readingFingers}
                                                        disabled={busy || readingFingers || enrolled.length === 0}
                                                        title={enrolled.length === 0 ? 'They are not on any terminal yet.' : 'Copy their fingerprints from the terminals. Changes nothing there.'}
                                                        onClick={async () => {
                                                            setReadingFingers(true);
                                                            try { await onReadFingerprints(employee); } finally { setReadingFingers(false); }
                                                        }}
                                                    >
                                                        {readingFingers ? 'Reading…' : 'Read to system'}
                                                    </Button>
                                                )}
                                                <StoredCopies slots={employee.stored_fingerprint_slots} />
                                            </>
                                        ) : (
                                            <span className="text-xs text-slate-500">Save the employee first, then enroll.</span>
                                        )}
                                    </div>
                                </Row>
                            ) : null}

                            <div className="flex flex-wrap items-center justify-between gap-3">
                                {isEdit ? (
                                    <label className="flex items-center gap-2.5">
                                        <input
                                            type="checkbox"
                                            checked={form.is_active && !resigned}
                                            disabled={resigned}
                                            title={resigned ? 'A resigned employee is always inactive.' : undefined}
                                            onChange={(e) => set('is_active', e.target.checked)}
                                            className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                        />
                                        <span className="text-sm text-slate-700">Active</span>
                                    </label>
                                ) : <span />}
                                <p className="text-xs text-slate-500">
                                    {pushTo.some(passwordWritable) || writableIds.some(passwordWritable)
                                        ? 'The password is written to terminals that can take it. Device role is saved here only.'
                                        : 'Device role and password are saved here. Terminals keep their own password until writing it is proven on their model.'}
                                </p>
                            </div>
                        </div>

                        {/* Details: two label-left columns, then the two full-width addresses. */}
                        <div role="tabpanel" id="employee-tab-details" aria-labelledby="employee-tabtrigger-details" hidden={tab !== 'details'} className="space-y-3">
                            <div className="grid grid-cols-1 gap-x-8 gap-y-3 md:grid-cols-2">
                                <div className="space-y-3">
                                    <Row label="Education" htmlFor="employee-education">
                                        <TextArea id="employee-education" value={form.education} onChange={(e) => set('education', e.target.value)} error={err('education')} />
                                    </Row>
                                    <Row label="Political status" htmlFor="employee-political">
                                        <TextField id="employee-political" value={form.political_status} onChange={(e) => set('political_status', e.target.value)} error={err('political_status')} />
                                    </Row>
                                    <Row label="Office" htmlFor="employee-office">
                                        <TextField id="employee-office" value={form.office} onChange={(e) => set('office', e.target.value)} error={err('office')} />
                                    </Row>
                                    <Row label="Postal code" htmlFor="employee-postal">
                                        <TextField id="employee-postal" value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} error={err('postal_code')} />
                                    </Row>
                                    <Row label="Job title" htmlFor="employee-job-title">
                                        <TextField id="employee-job-title" maxLength={255} placeholder="e.g. Accountant" value={form.job_title} onChange={(e) => set('job_title', e.target.value)} error={err('job_title')} />
                                    </Row>
                                </div>
                                <div className="space-y-3">
                                    <Row label="Country" htmlFor="employee-country">
                                        <CountrySelect id="employee-country" value={form.country} onChange={(v) => set('country', v)} error={err('country')} />
                                    </Row>
                                    <Row label="City" htmlFor="employee-city">
                                        <TextField id="employee-city" value={form.city} onChange={(e) => set('city', e.target.value)} error={err('city')} />
                                    </Row>
                                    <Row label="Home phone" htmlFor="employee-home-phone">
                                        <PhoneField id="employee-home-phone" value={form.home_phone} onChange={(v) => set('home_phone', v)} fallback={form.country} error={err('home_phone')} />
                                    </Row>
                                    <Row label="Race" htmlFor="employee-race">
                                        <TextField id="employee-race" value={form.race} onChange={(e) => set('race', e.target.value)} error={err('race')} />
                                    </Row>
                                </div>
                            </div>
                            <Row label="Home address" htmlFor="employee-home-address">
                                <TextField id="employee-home-address" value={form.home_address} onChange={(e) => set('home_address', e.target.value)} error={err('home_address')} />
                            </Row>
                            <Row label="Work address" htmlFor="employee-work-address">
                                <TextField id="employee-work-address" value={form.work_address} onChange={(e) => set('work_address', e.target.value)} error={err('work_address')} />
                            </Row>
                            <p className="pt-1 text-xs text-slate-500">These fields are kept in your system only. Terminals don't store them.</p>
                        </div>

                        {/* Access levels: the two terminal tables, then the group and the valid period beneath. */}
                        <div role="tabpanel" id="employee-tab-access" aria-labelledby="employee-tabtrigger-access" hidden={tab !== 'access'} className="space-y-4">
                            <div>
                                <TransferPicker
                                    items={terminalItems}
                                    selected={onTerminals}
                                    onChange={setOnTerminals}
                                    locked={lockedIds}
                                    // A resigned person is kept off the terminals; clear the date to change that.
                                    disabled={submitting || resigned}
                                    titles={{ left: 'Terminals', right: 'On these terminals' }}
                                    columns={{ name: 'Terminal', detail: 'Status' }}
                                    empty={{
                                        left: writable.length === 0 ? 'No terminal here can be written to.' : 'They are on every terminal.',
                                        right: 'Not on any terminal.',
                                    }}
                                />
                                <p className="mt-2 text-xs text-slate-500">
                                    Moving a terminal to the right puts them on it when you save, then reads their fingerprints and card back.
                                    Moving one to the left removes them from it, after a confirmation.
                                    {resigned && ' They have resigned, so they are kept off every terminal — clear the resignation date to change that.'}
                                </p>
                            </div>

                            <div className="grid grid-cols-1 gap-x-8 gap-y-3 md:grid-cols-2">
                                <Row label="Multi-card group" htmlFor="employee-multi-card">
                                    <SelectField
                                        id="employee-multi-card"
                                        value={multiCardGroups.length === 0 ? '' : form.multi_card_group_id}
                                        onChange={(e) => set('multi_card_group_id', e.target.value)}
                                        error={err('multi_card_group_id')}
                                        disabled={multiCardGroups.length === 0}
                                        title={multiCardGroups.length === 0 ? 'No multi-card groups are set up yet.' : undefined}
                                    >
                                        <option value="">{multiCardGroups.length === 0 ? 'None set up' : 'None'}</option>
                                        {multiCardGroups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                                    </SelectField>
                                </Row>
                                <div className="hidden md:block" />
                                <Row label="Valid period" htmlFor="employee-valid-period">
                                    <label className="flex min-h-[42px] items-center gap-2.5">
                                        <input
                                            id="employee-valid-period"
                                            type="checkbox"
                                            checked={form.valid_period_enabled}
                                            onChange={(e) => set('valid_period_enabled', e.target.checked)}
                                            className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                        />
                                        <span className="text-sm text-slate-700">Set valid period</span>
                                    </label>
                                </Row>
                                <div className="hidden md:block" />
                                <Row label="Start date" htmlFor="employee-valid-from">
                                    <TextField id="employee-valid-from" type="date" disabled={!form.valid_period_enabled} value={form.access_valid_from} onChange={(e) => set('access_valid_from', e.target.value)} error={err('access_valid_from')} />
                                </Row>
                                <Row label="End date" htmlFor="employee-valid-to">
                                    <TextField id="employee-valid-to" type="date" disabled={!form.valid_period_enabled} value={form.access_valid_to} min={form.access_valid_from || undefined} onChange={(e) => set('access_valid_to', e.target.value)} error={err('access_valid_to')} />
                                </Row>
                            </div>

                            <p className="text-xs text-slate-500">
                                The group and the valid period are saved here only. Nothing is written to the door panels yet.
                            </p>
                        </div>
                    </fieldset>

                    {/*
                      | THE FOOTER, on every tab: what the save will do to the terminals on
                      | the left, the buttons on the right. Without the summary a save could
                      | remove somebody from a terminal while the operator was on another tab.
                    */}
                    <div className="px-5 pb-4 pt-3">
                        {outcome && (
                            <div className="mb-3">
                                <TerminalResults outcome={outcome} retrying={retrying} onRetry={onRetry ? retry : null} onResolve={onRetry ? resolveFailure : null} />
                            </div>
                        )}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                {!outcome && (
                                    <PendingTerminals
                                        isEdit={isEdit}
                                        autoPush={autoPush}
                                        name={terminalName}
                                        push={pushTo}
                                        current={upToDate}
                                        added={addedTo}
                                        removed={removeFrom}
                                        onOpen={() => setTab('access')}
                                    />
                                )}
                            </div>
                            <div className="flex shrink-0 justify-end gap-2">
                                {outcome ? (
                                    <>
                                        {failedIds.length > 0 && onRetry && (
                                            <Button type="button" variant="secondary" className="sm:min-w-[8rem]" onClick={() => retry(failedIds)} loading={retrying.length > 0} disabled={busy}>
                                                Retry failed
                                            </Button>
                                        )}
                                        <Button type="button" className="sm:min-w-[8rem]" onClick={onDone} disabled={busy}>Done</Button>
                                    </>
                                ) : (
                                    <>
                                        <Button type="submit" className="sm:min-w-[8rem]" loading={submitting}>
                                            {submitting ? (isEdit ? 'Saving…' : 'Adding…') : isEdit ? 'Save' : 'Add'}
                                        </Button>
                                        <Button type="button" variant="secondary" className="sm:min-w-[8rem]" onClick={onClose} disabled={submitting}>Cancel</Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                </form>
            </Card>
        </div>
    );
}

/*
| Which fingers the SYSTEM holds a copy of (2026-09-24) — kept so a Personnel ID change
| or a new terminal does not depend on one terminal. Three states, never collapsed
| (rule 3): not loaded (undefined → nothing said), none stored yet, and the list.
*/
function StoredCopies({ slots }) {
    if (slots === undefined) return null;
    return (
        <span className="text-xs text-slate-500">
            {slots.length === 0
                ? 'No copy stored in the system yet.'
                : `Copy stored in the system: ${slots.map(slotName).join(', ')}.`}
        </span>
    );
}

/*
| One label-left field row, as every field on the ZKAccess dialog is laid out. The
| label column is a fixed width so fields line up down the whole tab, including the
| full-width addresses under the two columns. Below `sm` the label stacks on top —
| a label beside a 40%-wide input on a phone helps nobody.
*/
function Row({ label, htmlFor, required = false, children }) {
    const Label = htmlFor ? 'label' : 'span';
    return (
        <div className="grid grid-cols-1 gap-x-3 gap-y-1 sm:grid-cols-[6.75rem_minmax(0,1fr)] sm:items-start">
            <Label htmlFor={htmlFor} className="text-sm text-slate-700 sm:pt-[11px] sm:leading-5">
                {label}{required && <> <Req /></>}
            </Label>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

/*
| The photo column, on the right as in ZKAccess. Upload and remove only: there is no
| webcam capture here, so the camera button the original has is left out rather
| than shown as a second way to pick a file.
*/
function PhotoPanel({ photo, initial, inputRef, onPick, onRemove, error }) {
    return (
        <div className="order-first flex flex-col items-center lg:order-none">
            <div className="flex aspect-square w-36 items-center justify-center overflow-hidden rounded-2xl bg-slate-100 lg:w-full">
                {photo ? (
                    <img src={photo} alt="" className="h-full w-full object-cover" />
                ) : initial ? (
                    <span className="text-4xl font-semibold text-slate-500">{initial}</span>
                ) : (
                    <svg viewBox="0 0 24 24" className="h-1/2 w-1/2 text-slate-300" fill="currentColor" aria-hidden="true">
                        <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.5-8 5.5V21h16v-1.5c0-3-3.6-5.5-8-5.5Z" />
                    </svg>
                )}
            </div>
            <span className="mt-1.5 text-xs text-slate-500">Best at 230 × 230 px</span>
            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                className="sr-only"
                id="employee-photo"
                tabIndex={-1}
                onChange={(e) => onPick(e.target.files?.[0])}
            />
            <div className="mt-2 flex gap-2">
                <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    title={photo ? 'Change photo' : 'Upload photo'}
                    aria-label={photo ? 'Change photo' : 'Upload photo'}
                    className={`flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-violet-700 ${FOCUS}`}
                >
                    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                    </svg>
                </button>
                {photo && (
                    <button
                        type="button"
                        onClick={onRemove}
                        title="Remove photo"
                        aria-label="Remove photo"
                        className={`flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 hover:bg-rose-50 hover:text-rose-600 ${FOCUS}`}
                    >
                        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" />
                        </svg>
                    </button>
                )}
            </div>
            {error && <span role="alert" className="mt-1 block text-center text-xs font-medium text-rose-600">{error}</span>}
        </div>
    );
}

function Req() {
    return <span className="text-violet-600" aria-hidden="true">*</span>;
}

// Same look as TextField, for Education and Job title.
function TextArea({ label, error, id, ...props }) {
    return (
        <div className="block">
            {label && <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">{label}</label>}
            <textarea
                id={id}
                rows={2}
                aria-invalid={error ? 'true' : undefined}
                className={`block w-full resize-y rounded-xl border bg-slate-50/70 px-3.5 py-2.5 text-sm text-slate-900 transition-colors duration-150 placeholder:text-slate-500 focus:bg-white focus:outline-none focus:ring-2 ${
                    error ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100' : 'border-slate-200 focus:border-violet-400 focus:ring-violet-100'
                }`}
                {...props}
            />
            {error && <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
        </div>
    );
}

/*
| One row per terminal after a save. Failed rows get Retry; skipped rows do not,
| because a skip is a refusal made before any write (read-only, not adopted) and
| retrying it would only repeat the refusal.
*/
function TerminalResults({ outcome, retrying, onRetry, onResolve }) {
    const { written, skipped, failed, cards = [], reads = [], removals = [] } = outcome.result;
    const total = written.length + skipped.length + failed.length;

    return (
        <div>
            <span className="text-sm font-medium text-slate-700">Terminals</span>
            <ul className="mt-2 max-h-36 space-y-1.5 overflow-y-auto">
                {written.map((w) => (
                    <li key={`w${w.device_id}`} className="flex items-start gap-2 text-sm text-slate-700">
                        <span className="text-emerald-600" aria-hidden="true">✓</span>
                        <span className="sr-only">Written:</span>
                        <span className="min-w-0">
                            {w.device_name} · PIN {w.pin} written
                            {/* Card and fingers put back from the system's copy (2026-10-11). */}
                            {(w.restored ?? []).map((note) => (
                                <span key={note} className="block text-xs text-slate-500">{note}</span>
                            ))}
                        </span>
                    </li>
                ))}
                {failed.map((f) => (
                    <li key={`f${f.device_id}`} className="flex items-center justify-between gap-2 text-sm text-slate-700">
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="text-rose-600" aria-hidden="true">✕</span>
                            <span className="sr-only">Failed:</span>
                            <span className="truncate" title={f.error}>{f.device_name} · {formatDeviceError(f.error)}</span>
                        </span>
                        {/* The fix this failure names, when there is one (DC-014). */}
                        {onResolve && f.action && (
                            <FixButton failure={f} onClick={(mode) => onResolve(f, mode)} busy={retrying.includes(f.device_id)} disabled={retrying.length > 0} />
                        )}
                        {onRetry && !f.action && (
                            <Button type="button" variant="secondary" className="min-h-[30px] shrink-0 px-2.5 text-xs" onClick={() => onRetry([f.device_id])} loading={retrying.includes(f.device_id)} disabled={retrying.length > 0}>
                                Retry
                            </Button>
                        )}
                    </li>
                ))}
                <StepRows rows={removals} prefix="r" retrying={retrying} onRetry={null} />
                <StepRows rows={cards} prefix="c" retrying={retrying} onRetry={onRetry} />
                <StepRows rows={reads} prefix="d" retrying={retrying} onRetry={onRetry} />
                {skipped.map((s) => (
                    <li key={`s${s.device_id}`} className="flex items-center gap-2 text-sm text-slate-700">
                        <span className="text-amber-600" aria-hidden="true">–</span>
                        <span className="sr-only">Skipped:</span>
                        <span className="min-w-0">{s.device_name} · skipped — {s.reason}</span>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-slate-500">
                Saved to the database.{total > 0 && <> {written.length} of {total} terminal{total === 1 ? '' : 's'} updated.</>}
            </p>
        </div>
    );
}

/*
| Card writes, read-backs and removals: one row per terminal per step, in the same
| shape ({ device_id, device_name, ok, skipped, message }). Retry re-runs the push
| and what follows it, so a removal row never offers it — retrying a failed removal
| by pushing would do the opposite of what was asked.
*/
function StepRows({ rows, prefix, retrying, onRetry }) {
    return rows.map((c) => (
        <li key={`${prefix}${c.device_id}`} className="flex items-center justify-between gap-2 text-sm text-slate-700">
            <span className="flex min-w-0 items-center gap-2">
                <span className={c.ok ? 'text-emerald-600' : c.skipped ? 'text-amber-600' : 'text-rose-600'} aria-hidden="true">
                    {c.ok ? '✓' : c.skipped ? '–' : '✕'}
                </span>
                <span className="sr-only">{c.ok ? 'Done:' : c.skipped ? 'Skipped:' : 'Failed:'}</span>
                <span className="min-w-0 truncate" title={c.message}>{c.device_name} · {c.message}</span>
            </span>
            {!c.ok && !c.skipped && onRetry && (
                <Button type="button" variant="secondary" className="min-h-[30px] shrink-0 px-2.5 text-xs" onClick={() => onRetry([c.device_id])} loading={retrying.includes(c.device_id)} disabled={retrying.length > 0}>
                    Retry
                </Button>
            )}
        </li>
    ));
}

/*
| What a save will do to the terminals, from the Access levels tab's right list. Shown
| on every tab, because a save that removes somebody from a terminal must never be a
| surprise to an operator who was only fixing a phone number on the first tab.
*/
function PendingTerminals({ isEdit, autoPush = false, name, push, current = [], added, removed, onOpen }) {
    const list = (ids) => ids.map(name).join(', ');
    const updated = push.filter((id) => !added.includes(id));

    return (
        <div>
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium text-slate-700">Terminals</span>
                <button type="button" onClick={onOpen} className={`rounded text-xs font-medium text-violet-700 hover:underline ${FOCUS}`}>
                    Choose terminals
                </button>
            </div>
            {push.length === 0 && removed.length === 0 ? (
                <p className="mt-1 text-xs text-slate-500">
                    {current.length > 0
                        ? `Saved here only — ${list(current)} already ${current.length === 1 ? 'has' : 'have'} their name, ID, card and fingerprints.`
                        : isEdit ? 'Saved here only. No terminal is changed.' : 'Saved here only. Add terminals on the Access levels tab to put them on one.'}
                </p>
            ) : (
                <ul className="mt-1 space-y-0.5 text-xs text-slate-600">
                    {added.length > 0 && <li>Added to {list(added)}, then read back.</li>}
                    {updated.length > 0 && <li>Updated on {list(updated)}, then read back{isEdit && !autoPush ? ' — you are asked first' : ' — as soon as you save'}.</li>}
                    {current.length > 0 && <li>Already up to date on {list(current)} — not written.</li>}
                    {removed.length > 0 && <li className="font-medium text-rose-700">Removed from {list(removed)} — their fingerprints and card there are deleted. You are asked first.</li>}
                </ul>
            )}
        </div>
    );
}

/*
| The folder-tab strip. Arrow keys move between tabs (WAI-ARIA tabs pattern); a tab
| holding a validation error carries a dot, so an error off-screen is still seen.
*/
function Tabs({ tab, onTab, hasError, accessCount }) {
    function onKeyDown(e) {
        const i = TABS.findIndex((t) => t.key === tab);
        let next = null;
        if (e.key === 'ArrowRight') next = TABS[(i + 1) % TABS.length];
        if (e.key === 'ArrowLeft') next = TABS[(i - 1 + TABS.length) % TABS.length];
        if (e.key === 'Home') next = TABS[0];
        if (e.key === 'End') next = TABS[TABS.length - 1];
        if (!next) return;
        e.preventDefault();
        onTab(next.key);
        document.getElementById(`employee-tabtrigger-${next.key}`)?.focus();
    }

    return (
        <div role="tablist" aria-label="Employee sections" className="flex gap-1 overflow-x-auto px-3 sm:px-5" onKeyDown={onKeyDown}>
            {TABS.map((t) => {
                const active = t.key === tab;
                return (
                    <button
                        key={t.key}
                        type="button"
                        role="tab"
                        id={`employee-tabtrigger-${t.key}`}
                        aria-selected={active}
                        aria-controls={`employee-tab-${t.key}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onTab(t.key)}
                        // Folder tabs: the active one is open into the panel below (its
                        // bottom edge covers the panel's top border), as in ZKAccess.
                        className={`relative z-10 -mb-px flex items-center gap-1.5 whitespace-nowrap rounded-t-lg border px-4 py-2 text-sm transition-colors ${
                            active
                                ? 'border-slate-200 border-b-white bg-white font-semibold text-slate-900'
                                : 'border-transparent bg-slate-50 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                        } ${FOCUS}`}
                    >
                        {t.label}
                        {t.key === 'access' && accessCount > 0 && (
                            <span className="rounded-full bg-violet-100 px-1.5 text-xs font-semibold tabular-nums text-violet-700">{accessCount}</span>
                        )}
                        {hasError(t.key) && (
                            <span className="h-1.5 w-1.5 rounded-full bg-rose-500" aria-label="has errors" />
                        )}
                    </button>
                );
            })}
        </div>
    );
}
