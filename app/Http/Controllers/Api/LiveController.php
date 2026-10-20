<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessCard;
use App\Models\AttendanceLog;
use App\Models\AttendanceSyncRun;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\DeviceRosterEntry;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\EnrollmentSession;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Notification;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * "What has changed?" — the fallback carrier for CLAUDE.md rule 18 (every page is live).
 *
 * The primary carrier is MQTT (MqttPublisher -> useMqtt). When no broker is reachable
 * — the production state on 2026-09-24 — the SPA polls this instead and fires the SAME
 * useMqtt handlers when a module's stamp moves, so pages written to be live stay live.
 *
 * A stamp is an opaque digest of (row count, newest updated_at / id) over the tables
 * a module's screens show, scoped to the caller's company by the models' tenant scope.
 * Count is in it so a DELETE moves the stamp too; nothing else leaves this endpoint.
 * Cheap by design: aggregates on keys and timestamps, no rows.
 */
class LiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'employees' => $this->stamp(Employee::query(), Department::query(), EmployeeDeviceMap::query(), AccessCard::query(), BiometricTemplate::query()),
            'devices' => $this->stamp(Device::query(), DeviceCommand::query(), DeviceGroup::query(), DeviceRosterEntry::query()),
            'attendance' => $this->stamp(AttendanceLog::query(), AttendanceSyncRun::query()),
            'enrollment' => $this->stamp(EnrollmentSession::query()),
            // The attendance rules are settings, edited on the Holidays screen (2026-10-19).
            'holidays' => $this->stamp(Holiday::query(), Setting::query()->where('key', 'like', 'attendance.%')),
            // Background report files (2026-10-18): the Reports screen's export list.
            'reports' => $this->stamp(ReportExport::query()),
            // The Users screen (2026-10-19): users, and their levels (RoleController touches
            // a role when its permissions change, which no column records otherwise).
            'users' => $this->stamp(User::query(), Role::query()->where('team_id', Tenancy::id())),
            'notifications' => $this->stamp(Notification::query()->where('user_id', $user->id)),
        ], 'Live stamps.');
    }

    private function stamp(Builder ...$queries): string
    {
        $parts = [];
        foreach ($queries as $q) {
            $model = $q->getModel();
            $row = (clone $q)->toBase()
                ->selectRaw('count(*) as c, max('.$model->getKeyName().') as k'.($model->usesTimestamps() ? ', max(updated_at) as u' : ''))
                ->first();
            $parts[] = ($row->c ?? 0).'/'.($row->k ?? 0).'/'.($row->u ?? '');
        }

        return md5(implode('|', $parts));
    }
}
