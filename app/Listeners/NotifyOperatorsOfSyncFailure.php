<?php

namespace App\Listeners;

use App\Events\DeviceSyncFailed;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy;

/**
 * Turns a DeviceSyncFailed event into an alert for the tenant's operators.
 * Auto-discovered by Laravel via the type-hinted handle() argument.
 */
class NotifyOperatorsOfSyncFailure
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(DeviceSyncFailed $event): void
    {
        $device = $event->device;

        // The device carries its tenant; make sure role lookups are scoped to it
        // (the event may be handled outside the original request's tenant context).
        if ($device->tenant_id !== null) {
            Tenancy::set($device->tenant_id);
        }

        $this->notifications->notifyMany(
            User::role('operator')->get(),
            [
                'type' => 'device.sync_failed',
                'level' => 'urgent',
                'title' => "Device sync failed: {$device->name}",
                'body' => $event->reason,
                'data' => ['device_id' => $device->id],
            ],
        );
    }
}
