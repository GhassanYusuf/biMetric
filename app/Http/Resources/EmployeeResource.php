<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'employee_code' => $this->employee_code,
            'external_id' => $this->external_id,
            'email' => $this->email,
            'department' => $this->department,
            // Cast: SQL Server returns it as a string, and screens compare it to ids.
            'department_id' => $this->department_id === null ? null : (int) $this->department_id,
            // `department` is ALSO a free-text column, and Eloquent exposes the
            // attribute before the relation, so the magic accessor would hand the
            // resource a string. Read the loaded relation object directly instead.
            'department_name' => $this->whenLoaded('department', fn () => $this->getRelation('department')?->name),
            /*
             * The company is the top of the department's branch (2026-10-12): the list
             * shows Company and Department as two columns. When `department_id` IS a
             * company, the person has a company and no department yet.
             */
            'company' => $this->whenLoaded('department', function () {
                $company = $this->getRelation('department')?->company();

                return $company ? ['id' => (int) $company->id, 'name' => $company->name] : null;
            }),
            'photo_url' => $this->photo_url,
            'is_active' => (bool) $this->is_active,
            'card_number' => $this->card_number,
            'has_card' => $this->has_card,
            // A ZKTeco user record carries exactly one card field, so this is
            // structurally 0 or 1. Exposed as a count because that is what the
            // roster column shows, alongside the fingerprint count.
            'card_count' => $this->has_card ? 1 : 0,
            'fingerprint_count' => $this->fingerprint_count,
            // Which slots hold a template, e.g. [1, 6], so the roster can draw
            // each person's enrolled fingers. Null = unknown, [] = none.
            'fingerprint_slots' => $this->fingerprint_slots,
            'enrollment_synced_at' => $this->enrollment_synced_at?->toIso8601String(),
            /*
             * Per-terminal enrollment, so a screen can say WHICH device holds
             * these fingerprints and this card.
             *
             * The four fields above are a flat snapshot of whichever terminal was
             * read last; with two or more devices they cannot be attributed. These
             * rows can, and callers should prefer them whenever present. Absent
             * from the payload (rather than empty) when the relation was not
             * loaded, so a consumer can tell "not asked for" from "not enrolled
             * anywhere" — rule 10 again.
             */
            'enrollments' => EmployeeDeviceMapResource::collection(
                $this->whenLoaded('deviceMaps')
            ),
            /*
             * The link to the human across sister companies. Null means this
             * employment is not attached to a Person yet. (The national id that used
             * to hang off it was removed on 2026-09-27.)
             */
            'person_id' => $this->person_id,
            /*
             * Fingers the SYSTEM holds a copy of (2026-09-24, FingerprintVault), from
             * any terminal. Slot numbers only — the bytes never leave the server. Absent
             * (not []) when not loaded, so a screen cannot read "not loaded" as "none".
             */
            'stored_fingerprint_slots' => $this->whenLoaded('biometricTemplates', fn () => $this->biometricTemplates
                ->whereNotNull('template_sha1')
                ->pluck('finger_slot')
                ->map(fn ($s) => (int) $s)
                ->unique()
                ->sort()
                ->values()
                ->all()),
            /*
             * PERSONNEL-DIALOG FIELDS (2026-09-23). Stored in this app only; none of
             * them has been sent to a terminal, so none of them says anything about
             * what a terminal holds.
             *
             * The device password is never returned — only whether one is set. It is
             * a door credential, and the form does not need it to offer "change it".
             * `privilege` null means "not set in this app", NOT "user": nobody's
             * privilege has been read off a terminal.
             */
            'last_name' => $this->last_name,
            'gender' => $this->gender,
            'mobile_phone' => $this->mobile_phone,
            'hired_date' => $this->hired_date?->toDateString(),
            'birthday' => $this->birthday?->toDateString(),
            'has_device_password' => $this->device_password !== null && $this->device_password !== '',
            'privilege' => $this->privilege,
            'job_title' => $this->job_title,
            // Employment (2026-10-12). A label and a date; resigned ⇒ inactive.
            'employment_type' => $this->employment_type ?? 'full_time',
            'resigned_at' => $this->resigned_at?->toDateString(),
            'office' => $this->office,
            'education' => $this->education,
            'country' => $this->country,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'home_phone' => $this->home_phone,
            'political_status' => $this->political_status,
            'race' => $this->race,
            'home_address' => $this->home_address,
            'work_address' => $this->work_address,
            'access_level_ids' => $this->whenLoaded('accessLevels', fn () => $this->accessLevels->pluck('id')->all()),
            'multi_card_group_id' => $this->multi_card_group_id,
            'valid_period_enabled' => (bool) $this->valid_period_enabled,
            'access_valid_from' => $this->access_valid_from?->toDateString(),
            'access_valid_to' => $this->access_valid_to?->toDateString(),
            'attendance_logs_count' => $this->whenCounted('attendanceLogs'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
