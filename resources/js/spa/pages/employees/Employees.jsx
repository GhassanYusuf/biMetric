import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useMqtt } from '../../shared/useMqtt';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import { can as canDevice } from '../devices/parts';
import EmployeesDesktop from './EmployeesDesktop';
import EmployeesMobile from './EmployeesMobile';
import EmployeeFormModal from './EmployeeFormModal';
import MergeModal from './MergeModal';
import DuplicateScanModal from './DuplicateScanModal';
import PushModal from './PushModal';
import EnrollModal from './EnrollModal';
import EmployeeDetailModal from './EmployeeDetailModal';

/*
| The screen's filters as query parameters: the list request and the PDF / Excel export
| (2026-10-21) both send exactly these, so a file lists the people on the screen — every
| page of them, with the tree's company or department, search, terminal and status.
*/
function rosterParams(term, active) {
    const params = new URLSearchParams();
    if (term) params.set('search', term);
    if (active.department_id) params.set('department_id', active.department_id);
    // Status: resigned people are left out unless asked for (2026-10-12).
    const status = active.status ?? '';
    if (status === 'active' || status === 'inactive') params.set('is_active', status === 'active' ? '1' : '0');
    if (status === 'resigned') params.set('resigned', '1');
    else if (status !== 'all') params.set('resigned', '0');
    // "On no terminal" is a different question from "on terminal N", so it is a
    // separate parameter rather than a magic device id.
    if (active.device === 'none') params.set('unenrolled', '1');
    else if (active.device) params.set('device_id', active.device);
    return params;
}

// Owns employee data + all mutations. Every action updates local state in place
// (no page reload) per the standards; the device-specific file only renders.
export default function Employees() {
    const deviceClass = useDeviceClass();
    const navigate = useNavigate();
    const { can, user } = useAuth();
    const confirm = useConfirm();
    // The operator's last answer to "change the user ID?", reused by a result-row Retry.
    const lastToCode = useRef(false);
    // The card from the last save (digits, '' to remove, null = unchanged), for Retry.
    const lastCard = useRef(null);
    /*
     * LIVE (CLAUDE.md rule 18). Any change to employees, their terminal mappings, cards
     * or fingerprint copies — by this operator, another one, or a background job —
     * refreshes the roster in place with the current search and filters; a change to
     * terminals refreshes the terminal list, which carries what each can do.
     */
    useMqtt(
        user?.tenant ? [`tenant/${user.tenant.id}/module/employees/+`, `tenant/${user.tenant.id}/module/devices/+`] : [],
        (topic) => {
            if (topic.includes('/devices')) {
                api.get('/devices').then((r) => setDevices(r.data ?? [])).catch(() => {});
            } else {
                load(search, filters);
                // The tree's counts move with the roster, and its nodes with the
                // departments table, both of which stamp the employees module.
                loadDepartments();
            }
        },
    );

    // Employees whose fingerprints are being read into the system right now.
    const readingFingers = useRef(new Set());
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [devices, setDevices] = useState([]);
    // Company setting employees.auto_push_on_save (2026-09-24). Off until loaded.
    const [autoPush, setAutoPush] = useState(false);
    const [savingAutoPush, setSavingAutoPush] = useState(false);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    // Server-side filters. Kept as plain strings so they map straight onto the query
    // string and an empty value means "no filter" without a second flag per field.
    const [filters, setFilters] = useState({});
    const [departments, setDepartments] = useState([]);
    const [unassignedCount, setUnassignedCount] = useState(0);
    const [departmentsLoaded, setDepartmentsLoaded] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [enrolling, setEnrolling] = useState(null); // employee being enrolled
    const [editing, setEditing] = useState(null); // employee being edited
    const [pushing, setPushing] = useState(null); // employee being pushed to terminals
    const [merging, setMerging] = useState(null); // employee being merged with duplicates
    // null = closed, 'all' = the whole-roster scan, otherwise one employee's scan
    const [scanning, setScanning] = useState(null);
    const [viewing, setViewing] = useState(null); // employee whose enrollment is open
    const [readingDevice, setReadingDevice] = useState(false);
    const [total, setTotal] = useState(null);
    const [pagination, setPagination] = useState(null);
    /*
     * Has the roster EVER loaded, as opposed to is it empty right now.
     *
     * This is the difference between "show a skeleton" and "show the list". It
     * was `rows.length === 0`, which meant a search matching nobody replaced the
     * ENTIRE page — including the search box — with a skeleton, so the box
     * vanished under the cursor halfway through typing a name and took the
     * keystrokes with it (2026-10-05). An empty result is not a loading state;
     * the list has its own empty state that keeps the box on screen.
     */
    const [loadedOnce, setLoadedOnce] = useState(false);
    /*
     * Which page to request. A ref, not state: it is an input to the request, and
     * the response is the authority on which page is on screen
     * (meta.pagination.current_page). Nothing renders it, so nothing can go stale.
     */
    const pageRef = useRef(1);
    const debounce = useRef(null);
    /*
     * Which request owns the screen.
     *
     * Loads overlap: the search debounce, a filter change, a page change and an
     * MQTT roster event can all be in flight at once, and they do not come back
     * in order. Whichever resolved LAST used to win, so a slow pre-search
     * response could land after the search and repaint the list with people who
     * do not match what is in the box. Each request takes a number on the way
     * out and only the newest one is allowed to write.
     */
    const requestId = useRef(0);

    // 100 a page. The roster is 362 people and each row carries every terminal
    // mapping, so this is four requests rather than one 362-row payload.
    const PER_PAGE = 100;

    const load = useCallback(async (term = '', active = {}, pageNum = pageRef.current) => {
        const id = ++requestId.current;
        setLoading(true);
        try {
            const params = rosterParams(term, active);
            params.set('per_page', String(PER_PAGE));
            params.set('page', String(pageNum));

            const res = await api.get(`/employees?${params.toString()}`);
            // Superseded while in flight: a newer request already owns the screen.
            if (id !== requestId.current) return;
            setRows(res.data);
            // The endpoint paginates, so this list is one page of the roster, not
            // the roster. Hand the whole block to the pager and follow the server's
            // idea of the current page: it clamps an out-of-range request, and
            // following it means a filter that narrows the list can never leave the
            // pager reading "page 9 of 2".
            const meta = res.meta?.pagination ?? null;
            setPagination(meta);
            pageRef.current = meta?.current_page ?? 1;
            setTotal(meta?.total ?? null);
            setLoadedOnce(true);
        } catch (err) {
            // A failure the user has already moved past is not worth a toast.
            if (id !== requestId.current) return;
            toast.error(err instanceof ApiError ? err.message : 'Could not load employees.');
        } finally {
            // Only the newest request may clear the spinner; an older one
            // finishing would hide it while its replacement is still loading.
            if (id === requestId.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        // A changed filter is a different result set: start at the top of it.
        pageRef.current = 1;
        load(search, filters, 1);
        // Filters apply immediately; only the free-text search is debounced, and it
        // calls load() itself.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, filters]);

    // Departments for the filter. Absent permission simply means no department filter
    // is offered, rather than an error the operator can do nothing about.
    // The response's meta also carries the tree's "Unassigned" count.
    const loadDepartments = useCallback(() => {
        api.get('/departments')
            .then((res) => {
                setDepartments(res.data ?? []);
                setUnassignedCount(res.meta?.unassigned_count ?? 0);
            })
            .catch(() => setDepartments([]))
            .finally(() => setDepartmentsLoaded(true));
    }, []);

    useEffect(() => { loadDepartments(); }, [loadDepartments]);

    /*
     * A company (no parent) or a department beneath one, created from the employee
     * form's dropdowns by whoever manages departments (2026-10-12). The tree reloads
     * so the new node is listed straight away; the form also keeps its own copy.
     */
    const createDepartment = useCallback(async (name, parentId) => {
        try {
            const res = await api.post('/departments', { name, parent_id: parentId });
            toast.success(`${parentId ? 'Department' : 'Company'} “${res.data.name}” added.`);
            loadDepartments();
            return res.data;
        } catch (err) {
            toast.error(err instanceof ApiError ? (err.errors?.name?.[0] ?? err.message) : `Could not add “${name}”.`);
            return null;
        }
    }, [loadDepartments, toast]);

    // Terminals for the enroll dialog. A user who may push employees but not
    // view devices simply gets no terminals offered, which the dialog explains.
    useEffect(() => {
        if (!can('devices.view')) return;
        api.get('/devices').then((res) => setDevices(res.data ?? [])).catch(() => setDevices([]));
        // A failed read leaves it OFF — the safe side: saves keep asking before pushing.
        api.get('/settings').then((res) => setAutoPush(!!res.data?.['employees.auto_push_on_save'])).catch(() => {});
    }, [can]);

    // Debounced server-side search — no full reload, just the list re-queries.
    function onSearch(term) {
        setSearch(term);
        clearTimeout(debounce.current);
        debounce.current = setTimeout(() => {
            // A new term is a new result set, so page 1 of it — page 4 of the old
            // one is rarely part of the answer.
            pageRef.current = 1;
            load(term, filters, 1);
        }, 200);
    }

    function goToPage(next) {
        pageRef.current = next;
        load(search, filters, next);
    }

    /*
     * "Read fingerprints to system" (2026-09-24): copies this person's fingerprints
     * from every terminal they are on into the system's encrypted store, and writes
     * nothing to any terminal. A terminal that could not be read is an error toast
     * naming it — a partial read must not look like a complete one.
     */
    async function readFingerprintsToSystem(employee) {
        /*
         * FEEDBACK FIRST. The read asks each terminal in turn — about 7 s for someone
         * on four (measured 2026-09-24) — and the menu that started it has already
         * closed, so without this the click looks like it did nothing. The notice
         * stays until the result replaces it; a second click meanwhile is ignored
         * rather than queueing a second read of the same terminals.
         */
        if (readingFingers.current.has(employee.id)) {
            toast.info(`Still reading ${employee.name}'s fingerprints…`);
            return null;
        }
        const terminals = (employee.enrollments ?? []).filter((m) => m.is_live !== false).length;
        if (terminals === 0) {
            toast.info(`${employee.name} is not on any terminal, so there is nothing to read.`);
            return null;
        }

        readingFingers.current.add(employee.id);
        const notice = toast.info(
            `Reading ${employee.name}'s fingerprints from ${terminals} terminal${terminals === 1 ? '' : 's'} — this takes a few seconds…`,
            { duration: 120000 },
        );

        try {
            const res = await api.post(`/employees/${employee.id}/fingerprints/read`);
            toast.dismiss(notice);
            const failed = (res.data?.terminals ?? []).filter((t) => t.status === 'failed');
            if (failed.length > 0) {
                toast.error(`${res.message} Not read: ${failed.map((t) => `${t.device_name} (${t.message})`).join('; ')}`);
            } else {
                toast.success(res.message);
            }
            setRows((prev) => prev.map((r) => (r.id === employee.id ? { ...r, stored_fingerprint_slots: res.data?.stored_fingerprint_slots ?? [] } : r)));
            return res.data;
        } catch (err) {
            toast.dismiss(notice);
            toast.error(err instanceof ApiError ? err.message : 'Could not read the fingerprints.');
            return null;
        } finally {
            readingFingers.current.delete(employee.id);
        }
    }

    /*
     * The company-wide switch. Turning it ON is confirmed, because from then on every
     * save writes to terminals immediately; turning it OFF is not, because that only
     * brings the question back.
     */
    async function toggleAutoPush(next) {
        if (next) {
            const ok = await confirm({
                title: 'Push to terminals automatically on save?',
                body: [
                    'Every time anyone saves an employee, their name, card and — where the terminal supports it — their user ID are written straight away to every terminal on that employee\'s Access levels list. There is no "Push now / Not now" question.',
                    'Removing someone from a terminal still asks first, because it deletes their fingerprints there.',
                    'This applies to everyone in the company. You can switch it off again here at any time.',
                ],
                confirmLabel: 'Switch on',
            });
            if (!ok) return;
        }
        setSavingAutoPush(true);
        try {
            const res = await api.put('/settings', { employees: { auto_push_on_save: next } });
            setAutoPush(!!res.data?.['employees.auto_push_on_save']);
            toast.success(next ? 'Saves now push to terminals automatically.' : 'Saves will ask before pushing to terminals again.');
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not change the setting.');
        } finally {
            setSavingAutoPush(false);
        }
    }

    /**
     * Saves, then writes them onto the terminals the form selected.
     *
     * TWO STEPS ON PURPOSE, and reported as two. The save either worked or came
     * back 422 for the form to render; the push reaches each device separately and
     * can succeed on some and fail on others. A single "Saved!" covering both would
     * hide the case that actually needs the operator: the record is right here and
     * still wrong on the hardware.
     *
     * The push never fails the save. By the time it runs the employee exists, and
     * throwing here would leave the form open over a record that was already
     * written — so its failure is a toast, not an exception.
     */
    async function propagate(employee, deviceIds, toEmployeeCode = false) {
        if (!deviceIds?.length) return null;

        try {
            const res = await api.post(`/employees/${employee.id}/propagate`, {
                device_ids: deviceIds,
                to_employee_code: toEmployeeCode,
            });
            /*
             * The message names every terminal written, failed and skipped with why,
             * so it is deliberately not shortened here. A run with any failure is an
             * ERROR toast even though the request succeeded — the HTTP status is
             * about the request, and the operator cares about the hardware.
             */
            const failed = (res.data?.failed ?? []).length;
            // Say where the fix is, when the operator has one to press (2026-10-11).
            const fixable = (res.data?.failed ?? []).filter((f) => f.action).map((f) => f.device_name);
            const hint = fixable.length ? ` ${fixable.join(' and ')}: use the button in the dialog to fix ${fixable.length === 1 ? 'it' : 'them'}.` : '';
            if (failed > 0) toast.error(res.message + hint);
            else toast.success(res.message);
            // Returned so the form can show one result row per terminal, with Retry.
            return res.data;
        } catch (err) {
            const message = err instanceof ApiError ? err.message : 'Saved, but the terminals could not be written to.';
            toast.error(message);
            // The request itself failed, so every chosen terminal is unaccounted for.
            return {
                written: [],
                skipped: [],
                failed: deviceIds.map((id) => ({
                    device_id: id,
                    device_name: devices.find((d) => d.id === id)?.name ?? `Terminal ${id}`,
                    error: message,
                })),
            };
        }
    }

    /**
     * The photo goes in its own request after the JSON save (a file cannot ride the
     * JSON body). Its failure is a toast and never fails the save — the record exists.
     */
    async function applyPhoto(employee, photo) {
        if (!photo) return employee;
        try {
            let res;
            if (photo === 'remove') {
                res = await api.delete(`/employees/${employee.id}/photo`);
            } else {
                const body = new FormData();
                body.append('photo', photo);
                res = await api.post(`/employees/${employee.id}/photo`, body);
            }
            setRows((prev) => prev.map((r) => (r.id === employee.id ? { ...r, photo_url: res.data.photo_url } : r)));
            return res.data;
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Saved, but the photo could not be uploaded.');
            return employee;
        }
    }

    /*
     * A card typed in the edit form, written to each terminal AFTER the push — a
     * terminal that has just gained this person needs the user record first. Uses the
     * enrollment dialog's endpoints, which read the record back, so a row marked
     * written is the terminal's word and not its acknowledgement (rule 10). One
     * terminal at a time: a card is unique per terminal, and a refusal on one
     * ("already on somebody else") says nothing about the next.
     */
    async function writeCard(employee, deviceIds, card) {
        const rows = [];
        for (const id of deviceIds) {
            const device = devices.find((d) => d.id === id);
            const deviceName = device?.name ?? `Terminal ${id}`;
            if (!canDevice(device, 'card.write')) {
                rows.push({ device_id: id, device_name: deviceName, ok: false, skipped: true, message: 'card not written — this terminal cannot take a typed card' });
                continue;
            }
            try {
                const url = `/employees/${employee.id}/devices/${id}/card`;
                if (card === '') await api.delete(url); else await api.put(url, { card_number: card });
                rows.push({ device_id: id, device_name: deviceName, ok: true, message: card === '' ? 'card removed' : `card ${card} written` });
            } catch (err) {
                const reason = err instanceof ApiError
                    ? (err.errors?.card_number?.[0] ?? err.message)
                    : `could not reach ${deviceName}`;
                rows.push({ device_id: id, device_name: deviceName, ok: false, message: `card not written — ${reason}` });
            }
        }

        const failed = rows.filter((r) => !r.ok && !r.skipped);
        const written = rows.filter((r) => r.ok);
        if (failed.length > 0) toast.error(`The card was not written on ${failed.map((r) => r.device_name).join(', ')}.`);
        else if (written.length > 0) toast.success(card === '' ? `Card removed on ${written.length} terminal${written.length === 1 ? '' : 's'}.` : `Card ${card} written on ${written.length} terminal${written.length === 1 ? '' : 's'}.`);
        return rows;
    }

    // Only where the push got through: a failed or skipped push may mean there is no
    // user record on that terminal to carry a card.
    async function withCard(employee, result, card) {
        if (card == null || !result) return result;
        const reached = (result.written ?? []).map((w) => w.device_id);
        return { ...result, cards: reached.length ? await writeCard(employee, reached, card) : [] };
    }

    /*
     * After a push, read the person back off each terminal it reached: which finger
     * slots are filled and which card is on the record. This is the "read the data
     * of this person" half of putting them on a terminal (2026-09-24), and it lands
     * on that terminal's row, so the roster shows what the device holds.
     *
     * Three outcomes kept apart (rule 3): not found, fingerprints UNKNOWN (the
     * template table did not decode — null), and a real count.
     */
    async function readBack(employee, deviceIds) {
        const rows = [];
        for (const id of deviceIds) {
            const deviceName = devices.find((d) => d.id === id)?.name ?? `Terminal ${id}`;
            try {
                const res = await api.get(`/employees/${employee.id}/devices/${id}/enrollment`);
                const e = res.data ?? {};
                if (!e.on_device) {
                    rows.push({ device_id: id, device_name: deviceName, ok: false, message: 'read back — not found on the terminal' });
                    continue;
                }
                const fingers = e.fingerprint_count == null
                    ? 'fingerprints unknown'
                    : `${e.fingerprint_count} fingerprint${e.fingerprint_count === 1 ? '' : 's'}`;
                const cardText = !e.card_number
                    ? 'no card'
                    : e.card_number === 4294967295 ? 'probably no card' : `card ${e.card_number}`;
                rows.push({ device_id: id, device_name: deviceName, ok: true, message: `read back — ${fingers}, ${cardText}` });
            } catch (err) {
                rows.push({
                    device_id: id,
                    device_name: deviceName,
                    ok: false,
                    message: `could not read back — ${err instanceof ApiError ? err.message : `could not reach ${deviceName}`}`,
                });
            }
        }
        const failed = rows.filter((r) => !r.ok);
        if (failed.length > 0) toast.error(`Could not read ${employee.name} back from ${failed.map((r) => r.device_name).join(', ')}.`);
        return rows;
    }

    // Push → card (if changed) → read back, on the terminals the push reached.
    async function putOnTerminals(employee, deviceIds, toCode, card) {
        const pushed = await withCard(employee, await propagate(employee, deviceIds, toCode), card);
        if (!pushed) return pushed;
        const reached = (pushed.written ?? []).map((w) => w.device_id);
        return { ...pushed, reads: reached.length ? await readBack(employee, reached) : [] };
    }

    /*
     * Terminals moved off the Access levels tab's right list. Destructive: the record,
     * fingerprints and card go from each device, and fingerprints can only come back
     * with the person standing at the terminal. So it is confirmed, in red, naming
     * every terminal, and declining keeps them on all of them.
     */
    async function removeFromTerminals(employee, deviceIds) {
        if (!deviceIds.length) return [];
        const names = deviceIds.map((id) => devices.find((d) => d.id === id)?.name ?? `Terminal ${id}`);
        const ok = await confirm({
            title: `Remove ${employee.name} from ${names.length === 1 ? names[0] : `${names.length} terminals`}?`,
            body: [
                `This deletes their record, fingerprints and card from ${names.join(', ')}. They will have to be added and re-enrolled in person.`,
                'Past attendance records in this app are not affected.',
            ],
            confirmLabel: names.length === 1 ? 'Remove from terminal' : `Remove from ${names.length} terminals`,
            cancelLabel: 'Keep them on',
            danger: true,
        });
        if (!ok) {
            toast.info(`${employee.name} was kept on ${names.join(', ')}.`);
            return [];
        }

        const rows = [];
        for (const [i, id] of deviceIds.entries()) {
            try {
                await api.post(`/employees/${employee.id}/devices/${id}/remove`);
                rows.push({ device_id: id, device_name: names[i], ok: true, message: 'removed from the terminal' });
            } catch (err) {
                rows.push({
                    device_id: id,
                    device_name: names[i],
                    ok: false,
                    message: `not removed — ${err instanceof ApiError ? err.message : `could not reach ${names[i]}`}`,
                });
            }
        }
        const failed = rows.filter((r) => !r.ok);
        // The reason goes in the toast: "could not be removed" alone left the operator
        // nothing to act on (2026-10-11 — the real answer was "terminal not adopted").
        if (failed.length > 0) {
            toast.error(failed.map((r) => `${employee.name} was not removed from ${r.device_name}: ${r.message.replace(/^not removed — /, '')}`).join(' '));
        }
        else toast.success(`${employee.name} removed from ${names.join(', ')}.`);
        return rows;
    }

    /** One terminal again, from the form's result row. */
    async function retryPropagate(employee, deviceIds) {
        // A retry repeats the operator's last answers (user ID, card), not new defaults.
        const result = await putOnTerminals(employee, deviceIds, lastToCode.current, lastCard.current);
        await load(search, filters);
        return result;
    }

    /*
     * With no terminal ticked, a save closes the form exactly as it always has. With
     * terminals ticked, the form STAYS OPEN and is handed the per-terminal result, so
     * it can show a row per terminal and offer Retry on the ones that failed; the
     * toast still fires as before.
     */
    async function createEmployee(payload, deviceIds, photo, card = null) {
        lastCard.current = card;
        const res = await api.post('/employees', payload); // throws ApiError(422) for the form to show
        setRows((prev) => [res.data, ...prev]);
        const saved = await applyPhoto(res.data, photo);
        if (!deviceIds?.length) {
            setFormOpen(false);
            return null;
        }
        const result = await putOnTerminals(saved, deviceIds, false, card);
        return { employee: saved, result };
    }

    /*
     * One line per terminal saying what a push will actually write there. A push
     * writes the NAME only (DeviceUserSyncService::pushEmployee → saveUser), under the
     * PIN already mapped on that terminal. Moving that PIN to the employee code is a
     * separate question asked next (2026-09-23). The "before" is the name the terminal held at its last read, so it
     * is what we last SAW there, not a live read.
     */
    function describePush(before, after, deviceId, list = devices) {
        const device = list.find((d) => d.id === deviceId);
        const terminal = device?.name ?? `Terminal ${deviceId}`;
        const map = (before.enrollments ?? []).find((e) => e.device_id === deviceId && e.is_live);
        const code = String(after.employee_code);

        if (!map) return `${terminal}: added as "${after.name}" under user ID ${/^\d{1,9}$/.test(code) ? `${code} if it is free` : 'the next free number'}.`;

        const held = map.device_held_name;
        const name = held == null
            ? `name set to "${after.name}"`
            : held === after.name
                ? `name stays "${after.name}"`
                : `name "${held}" → "${after.name}"`;

        /*
         * THE USER ID, said where the operator decides (rule 3). A terminal with
         * `user.renumber` moves them to the new ID — delete + re-upload, card and
         * fingerprints kept and checked (2026-09-24). One without it keeps the old ID,
         * and the line says so instead of letting a successful push look like a move.
         */
        let idLine = '';
        if (String(map.device_user_id) !== code && /^\d{1,9}$/.test(code)) {
            idLine = canDevice(device, 'user.renumber')
                ? ` User ID ${map.device_user_id} → ${code}, card and fingerprints kept.`
                : ` Keeps user ID ${map.device_user_id} — this terminal cannot change an ID.`;
        }

        return `${terminal} (user ID ${map.device_user_id}): ${name}.${idLine}`;
    }

    async function updateEmployee(payload, deviceIds, photo, card = null, removeFrom = []) {
        lastCard.current = card;
        const res = await api.put(`/employees/${editing.id}`, payload); // 422 → inline errors
        setRows((prev) => prev.map((r) => (r.id === editing.id ? res.data : r)));
        const saved = await applyPhoto(res.data, photo);

        // Removals first and on their own confirmation: a separate, destructive
        // decision that must not be bundled into "Push now".
        const removals = await removeFromTerminals(saved, removeFrom);
        const empty = { written: [], skipped: [], failed: [] };

        /*
         * ASKED AFTER THE SAVE, NOT BEFORE. The record is already stored here, so
         * this question is only about the hardware: an operator correcting a typo
         * may not want to touch a terminal right now, and declining leaves the save
         * intact. The Push row action still writes them out later.
         */
        /*
         * A FRESH terminal list, not the one loaded with the page: what each terminal
         * can do (user.renumber) may have changed since. A stale list is exactly how a
         * push once rewrote a name while the operator believed the ID had moved.
         */
        const list = deviceIds?.length
            ? await api.get('/devices').then((r) => { setDevices(r.data ?? []); return r.data ?? []; }).catch(() => devices)
            : devices;
        const code = String(saved.employee_code);
        // The server re-checks every terminal's capability; this only decides whether to ask.
        const toCode = /^\d{1,9}$/.test(code) && (deviceIds ?? []).some((id) => {
            const map = (editing.enrollments ?? []).find((e) => e.device_id === id && e.is_live);
            return !map || String(map.device_user_id) !== code;
        });
        const moving = toCode && (deviceIds ?? []).some((id) => {
            const map = (editing.enrollments ?? []).find((e) => e.device_id === id && e.is_live);
            return map && String(map.device_user_id) !== code && canDevice(list.find((d) => d.id === id), 'user.renumber');
        });

        /*
         * With the company setting employees.auto_push_on_save ON, the save pushes
         * without asking (the owner's choice, made knowingly — see toggleAutoPush);
         * the per-terminal result rows still say what each terminal did. OFF, the
         * question below stays.
         */
        const push = deviceIds?.length > 0 && (autoPush || await confirm({
            title: `Push ${saved.name} to the terminals?`,
            body: [
                'Saved. Pushing now changes this on each terminal:',
                ...deviceIds.map((id) => describePush(editing, saved, id, list)),
                ...(moving
                    ? ['Changing a user ID copies their record, card and fingerprints off the terminal, re-writes it under the new ID and checks everything; if a check fails, the original is put back. Punches under the old ID still count for them.']
                    : []),
                ...(card == null
                    ? ['Fingerprints and card are not touched. Until you push, the terminals keep the old details.']
                    : [
                        card === ''
                            ? 'The card is removed from each of these terminals, then read back.'
                            : `Card ${card} is written to each of these terminals, then read back.`,
                        'Fingerprints are not touched. Until you push, the terminals keep the old details.',
                    ]),
                'Afterwards their fingerprints and card are read back from each terminal.',
            ],
            confirmLabel: 'Push now',
            cancelLabel: 'Not now',
        }));

        if (!push) {
            if (removals.length > 0) {
                if (deviceIds?.length) toast.info(`${saved.name} saved. The other terminals were not updated.`);
                await load(search, filters);
                return { employee: saved, result: { ...empty, removals } };
            }
            setEditing(null);
            if (deviceIds?.length) {
                toast.info(card == null
                    ? `${saved.name} saved. The terminals were not updated.`
                    : `${saved.name} saved. The terminals were not updated, so the card was not written.`);
            }
            await load(search, filters);
            return null;
        }
        /*
         * THE USER ID RIDES "PUSH NOW" (2026-09-24). It used to be a second question,
         * gated on the page's cached capabilities, and a stale page skipped it silently.
         * The Push prompt above now states per terminal whether the ID moves, and the
         * server decides per terminal from its own capability (EmployeeDevicePropagator).
         */
        lastToCode.current = toCode;

        const result = { ...(await putOnTerminals(saved, deviceIds, toCode, card)), removals };
        // The push changes what the terminals hold, which the roster reports. Refetch
        // rather than guessing — the commands run on a worker and land shortly after.
        await load(search, filters);
        return { employee: saved, result };
    }

    // "On a terminal" from the form opens the existing enrollment dialog. There is no
    // USB-reader enrollment in this app, so that button says so rather than pretending.
    function enrollFromForm(employee, source) {
        if (source === 'usb') {
            toast.info('USB reader enrollment is not available yet.');
            return;
        }
        setEditing(null);
        setEnrolling(employee);
    }

    /**
     * Deletes the employee from THIS APP only. Their record on the terminal is
     * untouched, so their fingerprints keep working and they can still clock in —
     * which is exactly the kind of thing a bare "Remove X?" prompt hides. The
     * confirmation says so, and points at the fix.
     */
    async function deleteEmployee(employee) {
        const onDevice = employee.enrollment_synced_at != null;

        const ok = await confirm({
            title: `Delete ${employee.name} from the payroll app?`,
            body: [
                `This removes their record and their ${(employee.attendance_logs_count ?? 0).toLocaleString()} attendance punches from this app.`,
                onDevice
                    ? 'It does NOT remove them from the biometric terminal — their fingerprints will keep working '
                      + 'and punches will keep arriving. Remove them from the terminal first if that is what you want.'
                    : 'They are not on a terminal, so nothing else needs doing.',
            ],
            confirmLabel: 'Delete employee',
            danger: true,
        });
        if (!ok) return;

        try {
            await api.delete(`/employees/${employee.id}`);
            setRows((prev) => prev.filter((r) => r.id !== employee.id));
            setTotal((t) => (t == null ? t : Math.max(0, t - 1)));
            toast.success(`${employee.name} deleted.`);
        } catch (err) {
            // Previously unhandled: a 403 or a failed request vanished silently
            // and the row stayed put with no explanation.
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${employee.name}.`);
        }
    }

    /*
     * "Push fingerprints to terminals" (2026-09-24): writes this person's stored
     * fingerprints to the terminals they are on that can take templates and lack
     * those fingers — how a finger enrolled on the F18 reaches Glass Door and
     * Entrance, which cannot capture one. A device write, so it asks first; add-only,
     * so the confirmation is not danger-toned.
     */
    async function pushFingerprints(employee, deviceIds = null, { ask = true } = {}) {
        const liveOn = new Set((employee.enrollments ?? []).filter((m) => m.is_live !== false).map((m) => m.device_id));
        const targets = devices.filter(
            (d) => d.is_active && canDevice(d, 'template.upload') && liveOn.has(d.id) && (deviceIds === null || deviceIds.includes(d.id)),
        );
        const stored = (employee.stored_fingerprint_slots ?? []).length;

        if (stored === 0) {
            if (ask) toast.info(`${employee.name} has no fingerprint stored in the system. Enroll one, or read them from a terminal first.`);
            return null;
        }
        if (targets.length === 0) {
            if (ask) toast.info(`None of ${employee.name}'s terminals can take fingerprints from here. Push the person to a door panel first.`);
            return null;
        }
        if (ask) {
            const ok = await confirm({
                title: `Copy ${employee.name}'s fingerprints?`,
                body: [
                    `Writes their ${stored} stored fingerprint${stored === 1 ? '' : 's'} to ${targets.map((d) => d.name).join(', ')}, wherever that finger is missing.`,
                    'A finger a terminal already holds is never replaced. Each terminal is read back afterwards.',
                ],
                confirmLabel: 'Copy fingerprints',
            });
            if (!ok) return null;
        }

        const notice = toast.info(`Writing ${employee.name}'s fingerprints to ${targets.length} terminal${targets.length === 1 ? '' : 's'}…`, { duration: 180000 });
        try {
            const res = await api.post(`/employees/${employee.id}/fingerprints/push`, { device_ids: targets.map((d) => d.id) });
            toast.dismiss(notice);
            const failed = (res.data?.terminals ?? []).filter((t) => t.status === 'failed');
            if (failed.length > 0) {
                toast.error(`${res.message} ${failed.map((t) => `${t.device_name}: ${t.message}`).join('; ')}`);
            } else {
                toast.success(res.message);
            }
            await load(search, filters);
            return res.data;
        } catch (err) {
            toast.dismiss(notice);
            toast.error(err instanceof ApiError ? err.message : 'Could not write the fingerprints.');
            return null;
        }
    }

    /**
     * Refreshes the whole roster's fingerprint and card counts from a terminal in
     * ONE device conversation. Reading per row would open a connection per
     * employee, which the hardware would not survive on a 100-row page.
     */
    async function readFromDevice() {
        const target = devices.find((d) => d.protocol === 'binary' && d.is_active);
        if (!target) {
            toast.error('No active Binary/TCP-IP terminal to read from.');
            return;
        }

        setReadingDevice(true);
        try {
            const res = await api.post(`/devices/${target.id}/sync-enrollment`);
            // fingerprints_known false means the card numbers are fresh but the
            // counts are not — the API message already says so; keep its wording.
            if (res.data?.fingerprints_known) toast.success(res.message);
            else toast.info(res.message);
            await load(search, filters);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not read from the terminal.');
        } finally {
            setReadingDevice(false);
        }
    }

    // Which export is being produced ('pdf' | 'excel'), so only that menu item spins.
    const [exporting, setExporting] = useState(null);

    async function exportRoster(format) {
        setExporting(format);
        try {
            const query = rosterParams(search.trim(), filters).toString();
            const path = format === 'excel' ? '/exports/employees/excel' : '/exports/employees';
            const name = await api.download(`${path}${query ? `?${query}` : ''}`, format === 'excel' ? 'employees.xlsx' : 'employees.pdf');
            toast.success(`Downloaded ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not produce the file.');
        } finally {
            setExporting(null);
        }
    }

    const presentationProps = {
        rows,
        loading,
        search,
        onSearch,
        filters,
        onFilters: setFilters,
        departments,
        unassignedCount,
        departmentsLoaded,
        devices,
        canCreate: can('employees.create'),
        canUpdate: can('employees.update'),
        canDelete: can('employees.delete'),
        canEnroll: can('employees.push'),
        canReadDevice: can('devices.sync') && devices.some((d) => d.protocol === 'binary' && d.is_active),
        readingDevice,
        total,
        loading,
        pagination,
        onPage: goToPage,
        onAdd: () => setFormOpen(true),
        onDelete: deleteEmployee,
        onEnroll: (employee) => setEnrolling(employee),
        onEdit: (employee) => {
            setEditing(employee);
            // What each terminal can do may have changed since the page loaded.
            api.get('/devices').then((r) => setDevices(r.data ?? [])).catch(() => {});
        },
        onPush: (employee) => setPushing(employee),
        onMerge: (employee) => setMerging(employee),
        // Opened from the toolbar (the whole roster) or from a row (that person
        // only) — same screen, different question.
        onScan: (target) => setScanning(target ?? 'all'),
        onReadFingerprints: readFingerprintsToSystem,
        onPushFingerprints: pushFingerprints,
        autoPush,
        savingAutoPush,
        canManageSettings: can('settings.manage'),
        onToggleAutoPush: toggleAutoPush,
        onView: (employee) => setViewing(employee),
        onReport: (employee) => navigate(`/employees/${employee.id}/report`),
        onReadFromDevice: readFromDevice,
        // The list as filtered — all employees, or the company / department picked in
        // the tree — as a PDF or an Excel workbook.
        exportItems: [
            { key: 'export-pdf', label: 'PDF', description: 'The list as filtered, ready to print', onSelect: () => exportRoster('pdf'), loading: exporting === 'pdf', disabled: !!exporting },
            { key: 'export-excel', label: 'Excel', description: 'The same list, a column per terminal', onSelect: () => exportRoster('excel'), loading: exporting === 'excel', disabled: !!exporting },
        ],
    };

    const Presentation = deviceClass === 'desktop' ? EmployeesDesktop : EmployeesMobile;

    return (
        <>
            {/*
              * The skeleton is for the FIRST load only. Testing `rows.length === 0`
              * here instead meant a search matching nobody unmounted the whole page
              * — search box included — and stole the caret mid-word. After the first
              * response the presentation always stays mounted, so an empty result
              * renders the list's own empty state and the box keeps focus.
              */}
            {!loadedOnce && loading ? (
                <SkeletonTable rows={8} cols={8} />
            ) : (
                <Presentation {...presentationProps} />
            )}
            {formOpen && (
                <EmployeeFormModal
                    devices={devices}
                    onSubmit={createEmployee}
                    onRetry={retryPropagate}
                    onClose={() => setFormOpen(false)}
                    onDone={() => { setFormOpen(false); load(search, filters); }}
                    departments={departments}
                    onCreateDepartment={can('departments.manage') ? createDepartment : null}
                />
            )}
            {pushing && (
                <PushModal
                    employee={rows.find((r) => r.id === pushing.id) ?? pushing}
                    devices={devices}
                    onSubmit={async (deviceIds, { fingerprints } = {}) => {
                        // Always ask for the Personnel ID; the server changes it only on
                        // terminals that support it and says per terminal what it did.
                        const result = await propagate(pushing, deviceIds, true);
                        // A terminal the operator can fix keeps the dialog open on it
                        // (TerminalFixes, 2026-10-11); otherwise it closes as before.
                        const needsFix = (result?.failed ?? []).some((f) => f.action);
                        if (!needsFix) setPushing(null);
                        const employee = pushing;
                        const followUp = async () => {
                            if (fingerprints) {
                                // Only where the person now IS: the terminals the push wrote.
                                const written = (result?.written ?? []).map((w) => w.device_id);
                                const fresh = await api.get(`/employees/${employee.id}`).then((r) => r.data).catch(() => null);
                                if (fresh && written.length) await pushFingerprints(fresh, written, { ask: false });
                            }
                            // The push changes what the terminals hold, which the roster
                            // reports. Refetch rather than guess — the commands run on a
                            // worker and land shortly after.
                            await load(search, filters);
                        };
                        /*
                         * A terminal that needs the operator's go-ahead gets its button
                         * NOW, with the toast — not after the fingerprint copy and the
                         * reload, which took ~15 s on 2026-10-11 and hid the buttons
                         * behind a toast that had already said "FAILED".
                         */
                        if (needsFix) {
                            followUp();
                            return result;
                        }
                        await followUp();
                        return result;
                    }}
                    onRetry={async (ids) => {
                        const again = await propagate(pushing, ids, true);
                        await load(search, filters);
                        return again;
                    }}
                    onClose={() => setPushing(null)}
                />
            )}
            {merging && (
                <MergeModal
                    employee={rows.find((r) => r.id === merging.id) ?? merging}
                    onMerged={async (message) => {
                        toast.success(message);
                        setMerging(null);
                        // Rows disappeared and one absorbed their history — nothing
                        // local can be patched up to match. Refetch.
                        await load(search, filters);
                    }}
                    onClose={() => setMerging(null)}
                />
            )}
            {scanning && (
                <DuplicateScanModal
                    employee={scanning === 'all' ? null : (rows.find((r) => r.id === scanning.id) ?? scanning)}
                    onMerged={async (message) => {
                        toast.success(message);
                        setScanning(null);
                        // Same reason as the merge above: the rows and their
                        // history changed underneath the screen.
                        await load(search, filters);
                    }}
                    onClose={() => setScanning(null)}
                />
            )}
            {editing && (
                <EmployeeFormModal
                    // The row from the last fetch, so `enrollments` is the current
                    // one rather than whatever was on screen when Edit was pressed.
                    employee={rows.find((r) => r.id === editing.id) ?? editing}
                    devices={devices}
                    onSubmit={updateEmployee}
                    onRetry={retryPropagate}
                    onClose={() => setEditing(null)}
                    onDone={() => setEditing(null)}
                    departments={departments}
                    onCreateDepartment={can('departments.manage') ? createDepartment : null}
                    onEnrollFingerprint={can('employees.push') ? enrollFromForm : null}
                    onReadFingerprints={can('employees.push') ? readFingerprintsToSystem : null}
                    autoPush={autoPush}
                />
            )}
            {viewing && !enrolling && (
                <EmployeeDetailModal
                    employee={rows.find((r) => r.id === viewing.id) ?? viewing}
                    devices={devices}
                    canEnroll={can('employees.push')}
                    onClose={() => setViewing(null)}
                    onEnroll={(employee) => setEnrolling(employee)}
                    // An on-demand device read is persisted server-side, so pull
                    // the roster back in step with it.
                    onRefreshed={() => load(search, filters)}
                />
            )}
            {enrolling && (
                <EnrollModal
                    employee={rows.find((r) => r.id === enrolling.id) ?? enrolling}
                    devices={devices}
                    onClose={() => setEnrolling(null)}
                    // A capture changes fingerprint_count / has_card server-side,
                    // so refetch rather than guessing the new values locally.
                    onEnrolled={() => load(search, filters)}
                />
            )}
        </>
    );
}

export { ApiError };
