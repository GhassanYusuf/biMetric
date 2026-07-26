<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('audit.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::success(AuditLogResource::collection($logs), 'Audit log retrieved.');
    }
}
