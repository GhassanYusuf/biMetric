<?php

namespace App\Listeners;

use App\Events\PunchRecorded;
use App\Services\Integration\PunchWebhookRelay;

/**
 * A live punch is handed to webhook subscribers straight away instead of waiting for the
 * once-a-minute `webhooks:relay-punches` sweep. The sweep still runs: it is what
 * delivers backfilled punches, which never raise this event, and what recovers anything
 * this missed. Auto-discovered from the handle() type-hint, like PublishPunchToMqtt.
 */
class RelayPunchWebhooks
{
    public function __construct(private readonly PunchWebhookRelay $relay) {}

    public function handle(PunchRecorded $event): void
    {
        try {
            $this->relay->relay($event->log->tenant_id);
        } catch (\Throwable $e) {
            // A webhook problem must never fail recording the punch itself.
            report($e);
        }
    }
}
