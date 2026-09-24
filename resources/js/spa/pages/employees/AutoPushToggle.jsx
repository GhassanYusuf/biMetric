import { Spinner } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| "Push to terminals automatically on save" — the company setting
| employees.auto_push_on_save (2026-09-24). One component for desktop and mobile
| (rule 5). Shown only to users who can manage settings; the page confirms before
| switching it ON, because that turns every save into immediate terminal writes.
|
| A button with role="switch", so Enter and Space work without extra handling.
*/
export default function AutoPushToggle({ on, busy = false, onToggle }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            disabled={busy}
            onClick={() => onToggle(!on)}
            className={`inline-flex items-center gap-2.5 rounded-lg px-1 py-1 text-sm text-slate-700 disabled:opacity-60 ${FOCUS}`}
        >
            <span
                aria-hidden="true"
                className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${on ? 'bg-violet-600' : 'bg-slate-300'}`}
            >
                <span className={`inline-block h-4 w-4 rounded-full bg-white transition-transform ${on ? 'translate-x-[18px]' : 'translate-x-0.5'}`} />
            </span>
            <span>Push to terminals automatically on save</span>
            {busy && <Spinner className="h-3.5 w-3.5" />}
        </button>
    );
}
