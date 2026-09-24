import { Badge } from '../../shared/ui';

/*
| The name cell for a punch linked to NO employee — shared by desktop and mobile
| (rule 5).
|
| When the terminal's roster has a name for the PIN it is shown, because "ali-test"
| tells an operator far more than "Unmatched". But it is the DEVICE's label, not an
| employee, so it is muted and badged (rule 10): "Not linked" when nobody has
| decided who it is, "Ignored" when it was deliberately set aside at adoption.
| No roster name at all falls back to the old "Unmatched".
*/
export default function UnmatchedName({ log }) {
    const name = log.terminal_user_name;

    if (!name) {
        return (
            <span className="font-normal text-slate-500" title="This punch is not linked to an employee record">
                Unmatched
            </span>
        );
    }

    const ignored = log.terminal_user_status === 'ignored';

    return (
        <span
            className="inline-flex min-w-0 items-center gap-2 font-normal"
            title={ignored
                ? 'Name as stored on the terminal. This PIN was ignored at adoption, so its punches count for nobody.'
                : 'Name as stored on the terminal. This PIN is not linked to an employee yet.'}
        >
            <span className="truncate text-slate-600">{name}</span>
            <Badge tone={ignored ? 'slate' : 'amber'}>{ignored ? 'Ignored' : 'Not linked'}</Badge>
        </span>
    );
}
