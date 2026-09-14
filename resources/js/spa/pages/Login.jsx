import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../shared/auth';
import { ApiError } from '../shared/api';
import { Button, Card, Spinner, TextField } from '../shared/ui';

// Shared across mobile/desktop — a single centered auth card is identical on
// both, so no split is warranted here (the standards only require separate
// files when the UX differs materially).
export default function Login() {
    const { user, login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);

    if (user) return <Navigate to="/dashboard" replace />;

    // Seeded owner account (database/seeders/DatabaseSeeder.php). Shown only in a
    // dev build — `import.meta.env.DEV` is false for `vite build`, so Vite strips
    // this branch and the credentials never reach a production bundle.
    const seeded = import.meta.env.DEV
        ? { email: 'g.yusuf@promoseven.com', password: 'Gha$$an1983' }
        : null;

    async function onSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setMessage('');
        try {
            await login(email, password);
            navigate('/dashboard', { replace: true });
        } catch (err) {
            if (err instanceof ApiError) {
                setErrors(err.errors || {});
                setMessage(err.message);
            } else {
                setMessage('Something went wrong. Please try again.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="relative flex min-h-full items-center justify-center overflow-hidden bg-[#f6f7fb] px-4 py-12">
            <div className="pointer-events-none absolute -top-40 left-1/2 h-96 w-[40rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-violet-300/40 via-sky-200/30 to-transparent blur-3xl" />
            <Card className="relative w-full max-w-sm p-7">
                <div className="mb-6 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-600 text-lg font-bold text-white shadow-lg shadow-violet-600/30">b</div>
                    <h1 className="text-lg font-semibold text-slate-900">Welcome back</h1>
                    <p className="text-sm text-slate-500">biMetric · Payroll &amp; Attendance</p>
                </div>

                {message && (
                    <div className="mb-4 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{message}</div>
                )}

                <form onSubmit={onSubmit} className="space-y-4">
                    <TextField
                        label="Email"
                        type="email"
                        autoComplete="username"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        error={errors.email?.[0]}
                        required
                    />
                    <TextField
                        label="Password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={errors.password?.[0]}
                        required
                    />
                    <Button type="submit" className="w-full" disabled={submitting}>
                        {submitting ? <Spinner /> : 'Sign in'}
                    </Button>
                </form>

                {seeded && (
                    <div className="mt-6 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5">
                        <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                            Development build — seeded account
                        </p>
                        <dl className="mt-1.5 space-y-0.5 text-sm text-slate-700">
                            <div className="flex gap-2">
                                <dt className="w-20 shrink-0 text-slate-500">Email</dt>
                                <dd className="font-mono break-all">{seeded.email}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="w-20 shrink-0 text-slate-500">Password</dt>
                                <dd className="font-mono break-all">{seeded.password}</dd>
                            </div>
                        </dl>
                        <Button
                            type="button"
                            variant="secondary"
                            className="mt-2.5 w-full"
                            onClick={() => {
                                setEmail(seeded.email);
                                setPassword(seeded.password);
                            }}
                        >
                            Fill these in
                        </Button>
                    </div>
                )}
            </Card>
        </div>
    );
}
