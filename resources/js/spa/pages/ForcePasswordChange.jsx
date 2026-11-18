import { useState } from 'react';
import { useAuth } from '../shared/auth';
import { api, ApiError } from '../shared/api';
import { Button, Card, Spinner, TextField } from '../shared/ui';
import { useToast } from '../shared/toast';

/*
| "Choose your new password" (owner, 2026-11-18: "give option when creating a user to be forced to
| change at first login"). Shown instead of the whole app while the signed-in user must change their
| password; the server refuses everything else meanwhile (RequirePasswordChange), so this screen is
| the only way forward. Styled like the sign-in page it follows.
*/
export default function ForcePasswordChange() {
    const { user, logout, setCurrentUser } = useAuth();
    const toast = useToast();
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        const found = {};
        if (password.length < 8) found.password = ['At least 8 characters.'];
        else if (password !== confirmation) found.password_confirmation = ['The two passwords are not the same.'];
        if (Object.keys(found).length) {
            setErrors(found);
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            const res = await api.put('/auth/password', {
                current_password: current,
                password,
                password_confirmation: confirmation,
            });
            toast.success('Password changed. Welcome in.');
            setCurrentUser(res.data);
        } catch (err) {
            if (err instanceof ApiError && err.errors && Object.keys(err.errors).length) setErrors(err.errors);
            else toast.error(err instanceof ApiError ? err.message : 'Could not change the password.');
            setSubmitting(false);
        }
    }

    return (
        <div className="relative flex min-h-full items-center justify-center overflow-hidden bg-[#f6f7fb] px-4 py-12">
            <div className="pointer-events-none absolute -top-40 left-1/2 h-96 w-[40rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-violet-300/40 via-sky-200/30 to-transparent blur-3xl" />
            <Card className="relative w-full max-w-sm p-7">
                <div className="mb-6 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-600 text-lg font-bold text-white shadow-lg shadow-violet-600/30">b</div>
                    <h1 className="text-lg font-semibold text-slate-900">Choose your new password</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        {user?.name ? `${user.name}, you` : 'You'} signed in with a password someone gave you. Choose your own to continue.
                    </p>
                </div>

                <form onSubmit={onSubmit} className="space-y-4" noValidate>
                    <TextField
                        label="Current password"
                        type="password"
                        autoComplete="current-password"
                        value={current}
                        onChange={(e) => setCurrent(e.target.value)}
                        error={errors.current_password?.[0]}
                        hint="The one you just signed in with."
                        required
                    />
                    <TextField
                        label="New password"
                        type="password"
                        autoComplete="new-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={errors.password?.[0]}
                        hint="At least 8 characters."
                        required
                    />
                    <TextField
                        label="New password again"
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        error={errors.password_confirmation?.[0]}
                        required
                    />
                    <Button type="submit" className="w-full" disabled={submitting}>
                        {submitting ? <Spinner /> : 'Save and continue'}
                    </Button>
                </form>

                <button
                    type="button"
                    onClick={logout}
                    className="mt-4 w-full rounded-lg py-1 text-center text-sm font-medium text-slate-600 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"
                >
                    Sign out
                </button>
            </Card>
        </div>
    );
}
