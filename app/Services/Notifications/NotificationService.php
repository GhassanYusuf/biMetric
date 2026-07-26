<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Services\Realtime\MqttPublisher;
use App\Services\Realtime\TopicBuilder;

/**
 * Creates in-app notifications. Order matters and is enforced here: the
 * notification is PERSISTED to the database first (so it survives and carries
 * unread/read state regardless of connectivity), then delivered live over
 * MQTT best-effort. This is the standards' "MQTT enhances, never replaces
 * persistent records" rule, in one reusable place.
 */
class NotificationService
{
    public function __construct(private readonly MqttPublisher $mqtt)
    {
    }

    /**
     * Notify a single user.
     *
     * @param  array{type:string,title:string,body?:string,level?:string,action_url?:string,data?:array}  $attributes
     */
    public function notify(User $user, array $attributes): Notification
    {
        $notification = Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $attributes['type'],
            'level' => $attributes['level'] ?? 'info',
            'title' => $attributes['title'],
            'body' => $attributes['body'] ?? null,
            'action_url' => $attributes['action_url'] ?? null,
            'data' => $attributes['data'] ?? null,
        ]);

        if ($user->tenant_id !== null) {
            $this->mqtt->publish(
                TopicBuilder::userNotifications($user->tenant_id, $user->id),
                $this->wire($notification),
            );
        }

        return $notification;
    }

    /** Notify many users with the same payload (e.g. all operators). */
    public function notifyMany(iterable $users, array $attributes): void
    {
        foreach ($users as $user) {
            $this->notify($user, $attributes);
        }
    }

    /** The compact shape the React notification client consumes. */
    private function wire(Notification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'level' => $n->level,
            'title' => $n->title,
            'body' => $n->body,
            'action_url' => $n->action_url,
            'data' => $n->data,
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
