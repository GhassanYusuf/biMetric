import { api, ApiError } from '../../shared/api';
import { Button } from '../../shared/ui';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';

/*
| The fix a red terminal row names (2026-10-11, casebook DC-014). The push answers a
| failure with an `action` when the operator can resolve it here; every write below is
| theirs to approve:
|
|   release_number          the employee's code is held on the terminal by a record
|                           linked to no employee. Two answers, because only the
|                           operator knows which it is:
|                             "same"    — it IS this employee (Ali's "ali-it"): its
|                                         fingers and punches become theirs, then it is
|                                         removed and they get one record;
|                             "remove"  — it is not them: saved, removed, number freed.
|   test_fingerprint_write  the terminal's model never took a fingerprint from this app:
|                           add ONE finger the system holds to someone missing it there.
|
| Shared by the edit form's result rows and the Push dialog, so the two behave the
| same (rules 5 and 13). The caller retries the push for that terminal afterwards.
*/

/** @returns {(employee, failure, mode?) => Promise<boolean>} true when the fix went through */
export function useTerminalFix() {
    const confirm = useConfirm();
    const toast = useToast();

    return async function fix(employee, failure, mode = 'remove') {
        const action = failure.action;
        const releasing = action?.type === 'release_number';
        const same = releasing && mode === 'same';
        const holder = `"${action?.holder ?? 'a record'}"`;

        const ok = await confirm(same ? {
            title: `${holder} on ${failure.device_name} is ${employee.name}?`,
            body: [
                `Its fingerprints are stored as ${employee.name}'s, the punches under user ID ${action.device_user_id} are credited to them, and the record is saved in the system.`,
                `Then the duplicate is removed and ${employee.name} gets one record on ${failure.device_name}: their name and user ID from the system, their card, all their fingerprints.`,
            ],
            confirmLabel: 'Same person — merge',
        } : releasing ? {
            title: `Free user ID ${action.device_user_id} on ${failure.device_name}?`,
            body: [
                `It is held by ${holder}, which is not linked to any employee.`,
                'Its record and fingerprints are saved in the system first, then it is removed from the terminal and the number is given to '
                    + `${employee.name}. Punches already recorded under the number are left as they are.`,
            ],
            confirmLabel: 'Free the number',
            danger: true,
        } : {
            title: `Test fingerprint writing on ${failure.device_name}?`,
            body: [
                'This terminal has never received a fingerprint from this app.',
                'The test adds ONE fingerprint the system already holds to a person on this terminal who is missing it there, '
                    + 'then reads it back. Nobody is removed and no fingerprint is replaced. If it passes, '
                    + `${employee.name}'s ID can be moved on this terminal.`,
            ],
            confirmLabel: 'Run the test',
        });
        if (!ok) return false;

        try {
            const res = releasing
                ? await api.post(`/employees/${employee.id}/devices/${failure.device_id}/release-number`, {
                    device_user_id: action.device_user_id,
                    same_person: same,
                })
                : await api.post(`/devices/${failure.device_id}/fingerprint-write-test`);
            toast.success(res.message);
            return true;
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${failure.device_name}.`);
            return false;
        }
    };
}

/** The button(s) for one failure. `onClick(mode)`; a held number offers both answers. */
export function FixButton({ failure, busy, disabled, onClick }) {
    const cls = 'min-h-[30px] shrink-0 px-2.5 text-xs';
    if (failure.action.type === 'release_number') {
        return (
            <span className="flex shrink-0 gap-1.5">
                <Button type="button" variant="secondary" className={cls} onClick={() => onClick('same')} loading={busy} disabled={disabled}>
                    Same person
                </Button>
                <Button type="button" variant="secondary" className={cls} onClick={() => onClick('remove')} disabled={disabled || busy}>
                    Remove it
                </Button>
            </span>
        );
    }

    return (
        <Button type="button" variant="secondary" className={cls} onClick={() => onClick()} loading={busy} disabled={disabled}>
            Test fingerprints
        </Button>
    );
}
