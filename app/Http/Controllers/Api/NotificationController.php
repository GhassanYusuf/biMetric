<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every user reads and manages ONLY their own notifications. The query is
 * pinned to the authenticated user's id (on top of the tenant scope), so there
 * is no cross-user or cross-tenant exposure.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->unread())
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            NotificationResource::collection($notifications),
            'Notifications retrieved.',
            ['unread_count' => $this->unreadCountFor($request)],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread_count' => $this->unreadCountFor($request)], 'OK');
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        // Ownership check: tenant scope already applied; also enforce user match.
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->markAsRead();

        return ApiResponse::success(new NotificationResource($notification), 'Marked as read.');
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return ApiResponse::success(['unread_count' => 0], 'All notifications marked as read.');
    }

    private function unreadCountFor(Request $request): int
    {
        return Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->count();
    }
}
