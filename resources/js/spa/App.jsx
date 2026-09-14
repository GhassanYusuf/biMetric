import { useEffect } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './shared/auth';
import { ErrorBoundary, installGlobalErrorHandlers } from './shared/errors';
import { useToast } from './shared/toast';
import { Spinner } from './shared/ui';
import AppLayout from './layouts/AppLayout';
import Login from './pages/Login';
import Dashboard from './pages/dashboard/Dashboard';
import Employees from './pages/employees/Employees';
import Devices from './pages/devices/Devices';
import DeviceDetail from './pages/devices/DeviceDetail';
import DeviceGroups from './pages/devices/DeviceGroups';
import SyncRuns from './pages/devices/SyncRuns';
import EnrollmentSessions from './pages/devices/EnrollmentSessions';
import Reconciliation from './pages/devices/Reconciliation';
import Attendance from './pages/attendance/Attendance';
import Reports from './pages/reports/Reports';
import Holidays from './pages/holidays/Holidays';
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

export default function App() {
    // Re-keying the boundary on the path clears a crash when the user navigates
    // away, so one broken screen does not wedge the whole app until a reload.
    const location = useLocation();

    return (
        <>
            <GlobalErrorHandlers />

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
                        <Route path="/notifications" element={<Notifications />} />
                    </Route>
                    <Route path="*" element={<Navigate to="/dashboard" replace />} />
                </Routes>
            </ErrorBoundary>
        </>
    );
}
