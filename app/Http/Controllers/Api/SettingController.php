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

        $data = $request->validate([
            'employees.auto_push_on_save' => ['sometimes', 'boolean'],
        ]);

        foreach (\Illuminate\Support\Arr::dot($data) as $key => $value) {
            $settings->set($key, $value);
        }

        return ApiResponse::success($settings->all(), 'Settings saved.');
    }
}
