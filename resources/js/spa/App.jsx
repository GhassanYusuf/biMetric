import { useEffect, useRef } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './shared/auth';
import { ErrorBoundary, installGlobalErrorHandlers } from './shared/errors';
import { useToast } from './shared/toast';
import { Spinner } from './shared/ui';
import { FOCUS } from './shared/theme';
import { useDeployedVersion } from './shared/useDeployedVersion';
import AppLayout from './layouts/AppLayout';
import Login from './pages/Login';
import Dashboard from './pages/dashboard/Dashboard';
import Employees from './pages/employees/Employees';
import EmployeeReport from './pages/employees/report/EmployeeReport';
import Devices from './pages/devices/Devices';
import DeviceDetail from './pages/devices/DeviceDetail';
import DeviceGroups from './pages/devices/DeviceGroups';
import SyncRuns from './pages/devices/SyncRuns';
import EnrollmentSessions from './pages/devices/EnrollmentSessions';
import Reconciliation from './pages/devices/Reconciliation';
import Attendance from './pages/attendance/Attendance';
import Reports from './pages/reports/Reports';
import Holidays from './pages/holidays/Holidays';
import Organisation from './pages/organisation/Organisation';
import LegacyData from './pages/legacy/LegacyData';
import Settings from './pages/settings/Settings';
import Notifications from './pages/notifications/Notifications';

function Protected({ children }) {
    const { user, loading } = useAuth();
    if (loading) {
        return (
            <div className="flex h-full items-center justify-center text-violet-600">
                <Spinner className="h-8 w-8" />
            </div>
        );
    }
    return user ? children : <Navigate to="/login" replace />;
}

/**
 * Installs the window-level handlers once, and routes what they catch to a toast.
 *
 * It sits inside the router and the ToastProvider because a rejected promise needs
 * BOTH: the toast, so the operator is not left staring at a button that did
 * nothing, and the report (sent by installGlobalErrorHandlers) so the failure is
 * on the server rather than only in a console nobody had open.
 */
function GlobalErrorHandlers() {
    const toast = useToast();

    useEffect(() => {
        installGlobalErrorHandlers({
            onError: (error) => {
                // ApiError already carries a message written for operators — api.js
                // built it from the server envelope, so a 502 from a terminal
                // arrives here already readable. Anything else is a bug in our own
                // code and its raw message on screen would be noise.
                const isApiError = error?.name === 'ApiError';

                toast.error(
                    isApiError
                        ? error.message
                        : 'Something went wrong. The problem has been reported.',
                );
            },
        });
        // Once per mount: these are window listeners that must outlive re-renders.
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    return null;
}

/*
| A newer build is on the server. On NAVIGATION the tab reloads onto it (the operator is
| leaving the page anyway, so nothing unsaved is lost); while they stay on a page it
| only offers a reload, and never reloads under an open form.
*/
function DeployedVersionWatcher({ pathname }) {
    const { newer, check } = useDeployedVersion();
    const first = useRef(true);

    useEffect(() => {
        if (first.current) { first.current = false; return; }
        check().then((isNewer) => { if (isNewer) window.location.reload(); });
    }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!newer) return null;

    return (
        <div className="fixed bottom-4 left-4 z-[70] flex items-center gap-3 rounded-xl bg-white px-4 py-2.5 text-sm text-slate-700 shadow-lg ring-1 ring-slate-200/70" role="status">
            A newer version of this app is available.
            <button type="button" onClick={() => window.location.reload()} className={`rounded font-semibold text-violet-700 hover:underline ${FOCUS}`}>
                Reload
            </button>
        </div>
    );
}

export default function App() {
    // Re-keying the boundary on the path clears a crash when the user navigates
    // away, so one broken screen does not wedge the whole app until a reload.
    const location = useLocation();

    return (
        <>
            <GlobalErrorHandlers />
            <DeployedVersionWatcher pathname={location.pathname} />

            <ErrorBoundary resetKey={location.pathname}>
                <Routes>
                    <Route path="/login" element={<Login />} />
                    <Route
                        element={
                            <Protected>
                                <AppLayout />
                            </Protected>
                        }
                    >
                        <Route index element={<Navigate to="/dashboard" replace />} />
                        <Route path="/dashboard" element={<Dashboard />} />
                        <Route path="/employees" element={<Employees />} />
                        <Route path="/employees/:id/report" element={<EmployeeReport />} />
                        <Route path="/devices" element={<Devices />} />
                        {/*
                          | Device sub-screens. `/devices/:id` must come after `/devices` but
                          | the sibling hardware routes are separate paths, not nested under
                          | it — a group or a sync run does not belong to one device.
                        */}
                        <Route path="/devices/:id" element={<DeviceDetail />} />
                        <Route path="/device-groups" element={<DeviceGroups />} />
                        <Route path="/device-sync-runs" element={<SyncRuns />} />
                        <Route path="/enrollment-sessions" element={<EnrollmentSessions />} />
                        <Route path="/reconciliation" element={<Reconciliation />} />
                        <Route path="/attendance" element={<Attendance />} />
                        <Route path="/reports" element={<Reports />} />
                        <Route path="/holidays" element={<Holidays />} />
                        <Route path="/settings" element={<Settings />} />
                        <Route path="/organisation" element={<Organisation />} />
                        <Route path="/notifications" element={<Notifications />} />

                        <Route path="/legacy" element={<LegacyData />} />
                    </Route>
                    <Route path="*" element={<Navigate to="/dashboard" replace />} />
                </Routes>
            </ErrorBoundary>
        </>
    );
}
