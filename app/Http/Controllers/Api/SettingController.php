<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings\TenantSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company settings (allow-listed in TenantSettings::DEFAULTS).
 * Reading needs settings.view; changing needs settings.manage.
 */
class SettingController extends Controller
{
    public function index(Request $request, TenantSettings $settings): JsonResponse
    {
        abort_unless($request->user()->can('settings.view'), 403);

        return ApiResponse::success($settings->all(), 'Settings retrieved.');
    }

    public function update(Request $request, TenantSettings $settings): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        /*
         | Only the keys that are simple scalars are validated here. `system.timezone`
         | and `attendance.sync_schedule` are NOT: their real rules live in
         | TenantSettings::cast() (a zone must be one PHP knows, a schedule must be a
         | cron expression the scheduler accepts), so validating a weaker version here
         | would let through values the cast then rejects with an opaque error. They
         | are deliberately absent below and reach the cast unchecked.
         */
        $data = $request->validate([
            'employees.auto_push_on_save' => ['sometimes', 'boolean'],
            'system.timezone' => ['sometimes', 'string', 'max:64'],
            'attendance.sync_enabled' => ['sometimes', 'boolean'],
            'attendance.sync_schedule' => ['sometimes', 'string', 'max:64'],
            'devices.clock_sync_enabled' => ['sometimes', 'boolean'],
            'devices.clock_sync_schedule' => ['sometimes', 'string', 'max:64'],
            'devices.daily_read_enabled' => ['sometimes', 'boolean'],
        ]);

        /*
         | TenantSettings::cast() REFUSES a value it cannot use — an unknown time zone,
         | a cron expression the scheduler would reject — by throwing
         | InvalidArgumentException with a message written for a person. Without this
         | catch that is an unhandled 500 on a settings form: the operator types
         | 'Mars/Olympus', the screen shows a stack trace, and nothing is saved.
         |
         | It becomes a 422 on the setting's own key, so the message lands next to the
         | field that caused it. Keyed by the dotted setting name because the settings
         | table's keys ARE dotted, and that is the name the allow-list uses.
         */
        foreach (\Illuminate\Support\Arr::dot($data) as $key => $value) {
            try {
                $settings->set($key, $value);
            } catch (\InvalidArgumentException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    str_replace('.', '->', $key) => [$e->getMessage()],
                ]);
            }
        }

        return ApiResponse::success($settings->all(), 'Settings saved.');
    }
}
