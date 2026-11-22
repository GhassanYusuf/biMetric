<?php

namespace App\Http\Requests\Api\Holiday;

/** Same rules as adding one; the holiday being edited does not clash with itself. */
class UpdateHolidayRequest extends StoreHolidayRequest
{
    protected function ignoredId(): ?int
    {
        return (int) $this->route('holiday')->id;
    }
}
