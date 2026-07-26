import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from './shared/auth';
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

export default function App() {
    return (
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
                <Route path="/holidays" element={<Holidays />} />
                <Route path="/notifications" element={<Notifications />} />
            </Route>
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
    );
}
