<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A named set of terminals, so an operation can target several at once. */
class DeviceGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'description'];

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_group_members');
    }

    /** Only the members this app can address directly over the binary protocol. */
    public function addressableDevices(): BelongsToMany
    {
        return $this->devices()->where('protocol', Device::PROTOCOL_BINARY)->where('is_active', true);
    }
}
