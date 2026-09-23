<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Illuminate\Support\Collection;

/**
 * Finds employment records that are the same human, and says how sure we are.
 *
 * WHY THIS IS NEEDED AT ALL: importing a terminal's roster creates one employee per
 * terminal. Somebody enrolled on four devices becomes four employees, their attendance
 * splits four ways, and no report about them is true.
 *
 * WHY IT IS NOT A NAME MATCH. Nine men in this estate are called Mohammed and seven
 * are called Ahmed. A name is a 24-byte device field, frequently abbreviated, and it
 * is not an identifier. Matching on it fuses unrelated people and pools their hours —
 * the same class of error as matching on a PIN across devices (rule 10 applied to
 * humans rather than hardware).
 *
 * WHAT IS ACTUALLY USED:
 *
 *   1. The candidate key is name + THE SAME PIN on more than one device. A shared name
 *      alone never groups anybody.
 *   2. The verdict comes from the CARD NUMBER read off each terminal, because a card is
 *      a physical object issued to one person:
 *
 *      CONFIRMED  every record carries the same card  -> hardware evidence
 *      UNCONFIRMED no usable card                     -> nothing contradicts, nothing confirms
 *      CONTRADICTED cards disagree                    -> do not merge
 *
 *   3. A card number held by more than one PIN is not an identifier and is discarded as
 *      evidence. Four such cards exist on this estate, so this is not hypothetical.
 *
 * FINGERPRINTS ARE NOT USABLE HERE, and the reason is worth stating so nobody looks for
 * them again: the template bytes are never downloaded (TemplateService::downloadTemplate()
 * refuses — the blob format and its matching upload command are unverified), so all we
 * hold is which slots are filled. The C3 panels do not decode their template table at
 * all. And two enrolments of one finger produce different templates anyway, so only a
 * byte-identical copy would ever prove anything.
 */
class DuplicateEmployeeFinder
{
    /**
     * A fingerprint template with identical bytes on more than one terminal.
     *
     * The strongest evidence available. A vendor tool that enrols once and pushes to
     * the other devices leaves the same blob behind, so a digest match is the device's
     * own record of one enrolment in two places. Confirmed 2026-09-15 across two
     * different models: PIN 261 slot 3, the same 1,054 bytes on an F18 and an F22.
     */
    public const PROVEN = 'proven';

    /**
     * Every terminal involved is managed by one vendor tool, which pushes a single
     * employee list to all of them — so the PIN itself identifies the person.
     *
     * Ranked with PROVEN because it rests on the vendor's own database rather than on
     * a guess. It is only ever true inside a device group an operator has explicitly
     * declared, with the evidence recorded (see device_groups.shared_pin_namespace).
     */
    public const NAMESPACED = 'namespaced';

    /** The same card on every terminal holding this PIN. */
    public const CONFIRMED = 'confirmed';

    /** Name and PIN agree; no card, or a card another PIN also uses. */
    public const UNCONFIRMED = 'unconfirmed';

    /** Two different cards under one PIN. Merging would be a guess against the evidence. */
    public const CONTRADICTED = 'contradicted';

    /**
     * @return Collection<int,array{
     *   name:string, pin:string, verdict:string, reason:string, card:?int,
     *   keep:int, merge:list<int>, punches:int
     * }>
     */
    public function find(): Collection
    {
        $maps = EmployeeDeviceMap::query()
            ->live()
            ->join('employees as e', 'e.id', '=', 'employee_device_maps.employee_id')
            ->get([
                'employee_device_maps.employee_id as employee_id',
                'employee_device_maps.device_id as device_id',
                'employee_device_maps.device_user_id as pin',
                'employee_device_maps.card_number as card',
                'employee_device_maps.template_digests as digests',
                'e.name as name',
            ]);

        $punches = \DB::table('attendance_logs')
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, count(*) c')
            ->groupBy('employee_id')
            ->pluck('c', 'employee_id')
            ->all();

        $ambiguousCards = $this->cardsHeldByMoreThanOnePin($maps);
        $namespaces = $this->sharedPinNamespaces();

        $grouped = $maps->groupBy(fn ($m) => $this->normalise($m->name).'|'.$m->pin);

        return $grouped
            ->filter(fn (Collection $g) => $g->pluck('employee_id')->unique()->count() > 1
                && $g->pluck('device_id')->unique()->count() > 1)
            ->flatMap(fn (Collection $g) => $this->splitByNamespace($g, $namespaces))
            ->map(function (Collection $g) use ($punches, $ambiguousCards, $namespaces) {
                $cards = $g->pluck('card')->filter(fn ($c) => $c > 0)->unique()->values();
                $usable = $cards->reject(fn ($c) => in_array($c, $ambiguousCards, true))->values();

                if ($this->withinOneNamespace($g, $namespaces)) {
                    /*
                     * The strongest statement available, and the only one that scales:
                     * a single vendor install maintains one employee list across these
                     * terminals, so the same badge number on two of them is the same
                     * person by construction, not by resemblance.
                     */
                    $verdict = self::NAMESPACED;
                    $reason = 'one vendor tool manages these terminals; the PIN is the person';
                } elseif ($this->templatesMatch($g)) {
                    /*
                     * A shared template outranks everything, INCLUDING disagreeing
                     * cards: a card is reissued when one is lost, a fingerprint is not.
                     * This is what settled "Ragesh" PIN 261, whose cards disagreed
                     * across sites while two of his templates matched byte for byte.
                     */
                    $verdict = self::PROVEN;
                    $reason = 'identical fingerprint template on more than one terminal';
                } elseif ($cards->count() > 1) {
                    $verdict = self::CONTRADICTED;
                    $reason = 'cards disagree';
                } elseif ($usable->count() === 1 && $g->pluck('card')->filter(fn ($c) => $c > 0)->count() >= 2) {
                    $verdict = self::CONFIRMED;
                    $reason = 'same card on every terminal';
                } elseif ($cards->isNotEmpty()) {
                    $verdict = self::UNCONFIRMED;
                    $reason = 'card is shared by another PIN';
                } else {
                    $verdict = self::UNCONFIRMED;
                    $reason = 'no card to corroborate';
                }

                // The record holding the most history is kept, so a merge moves the least.
                $ids = $g->pluck('employee_id')->unique()
                    ->sortByDesc(fn ($id) => [$punches[$id] ?? 0, -$id])
                    ->values();

                return [
                    'name' => $g->first()->name,
                    'pin' => (string) $g->first()->pin,
                    'verdict' => $verdict,
                    'reason' => $reason,
                    'card' => $usable->first(),
                    'keep' => $ids->first(),
                    'merge' => $ids->slice(1)->values()->all(),
                    'punches' => $ids->sum(fn ($id) => $punches[$id] ?? 0),
                ];
            })
            ->values()
            ->sortByDesc('punches')
            ->values();
    }

    /**
     * Splits a candidate group along namespace boundaries before it is judged.
     *
     * WHY, AND IT COST FOUR PEOPLE TO NOTICE. A group is "this name at this PIN,
     * across devices". Four people held PIN 225/230/232/233 on Glass Door, Entrance
     * AND Camelot. Glass Door and Entrance are managed by one ZKAccess install, so
     * those two records are provably one human — but Camelot is a separate install
     * where the same PIN may be somebody else entirely, and its presence dragged the
     * WHOLE group down to "unconfirmed". The result was four people left duplicated
     * on the two panels where the evidence was never in doubt.
     *
     * So the group is partitioned first: members sitting wholly inside one declared
     * namespace are judged together and get the strong verdict; everyone else is
     * judged separately on their own evidence. A member outside the namespace can no
     * longer veto a conclusion it is not party to.
     *
     * @param  array<int,list<int>>  $namespaces
     * @return list<Collection>
     */
    private function splitByNamespace(Collection $g, array $namespaces): array
    {
        $byNamespace = [];
        $rest = [];

        foreach ($g as $m) {
            $groups = $namespaces[$m->device_id] ?? [];

            if ($groups === []) {
                $rest[] = $m;

                continue;
            }

            // A device in several declared namespaces is filed under each; the
            // intersection test below is what decides membership.
            foreach ($groups as $ns) {
                $byNamespace[$ns][] = $m;
            }
        }

        $out = [];
        $claimed = [];

        foreach ($byNamespace as $members) {
            $subset = new Collection($members);

            // Worth splitting out only if the subset is itself a duplicate: two
            // records, on two different terminals, inside the one namespace.
            if ($subset->pluck('employee_id')->unique()->count() < 2
                || $subset->pluck('device_id')->unique()->count() < 2) {
                continue;
            }

            $out[] = $subset;
            foreach ($members as $m) {
                $claimed[$m->employee_id] = true;
            }
        }

        if ($out === []) {
            return [$g];
        }

        // Whatever the namespace did not account for is judged on its own merits.
        $leftovers = $g->reject(fn ($m) => isset($claimed[$m->employee_id]))->values();

        if ($leftovers->pluck('employee_id')->unique()->count() > 1
            && $leftovers->pluck('device_id')->unique()->count() > 1) {
            $out[] = $leftovers;
        }

        return $out;
    }

    /**
     * Device id => group ids that declare a shared PIN namespace.
     *
     * @return array<int,list<int>>
     */
    private function sharedPinNamespaces(): array
    {
        $rows = \DB::table('device_group_members as m')
            ->join('device_groups as g', 'g.id', '=', 'm.device_group_id')
            ->where('g.shared_pin_namespace', true)
            ->get(['m.device_id', 'm.device_group_id']);

        $byDevice = [];
        foreach ($rows as $r) {
            $byDevice[$r->device_id][] = $r->device_group_id;
        }

        return $byDevice;
    }

    /**
     * True when EVERY terminal in the group sits inside one declared namespace.
     *
     * Every one, not most: a group spanning a managed terminal and an unmanaged one is
     * exactly the case where the same PIN means two different people, and it must fall
     * through to the weaker evidence rather than inherit the strong claim.
     *
     * @param  array<int,list<int>>  $namespaces
     */
    private function withinOneNamespace(Collection $g, array $namespaces): bool
    {
        $shared = null;

        foreach ($g->pluck('device_id')->unique() as $deviceId) {
            $groups = $namespaces[$deviceId] ?? [];

            if ($groups === []) {
                return false;
            }

            $shared = $shared === null ? $groups : array_intersect($shared, $groups);

            if ($shared === []) {
                return false;
            }
        }

        return $shared !== null;
    }

    /**
     * True when two terminals hold a byte-identical template for this group.
     *
     * Compared per SLOT, because one person's thumb and index finger are different
     * enrolments and a cross-slot match would be meaningless. A digest seen on two
     * different devices is the whole signal.
     *
     * A mismatch is NOT evidence of different people and is never treated as such:
     * presenting the same finger twice produces a different template, and there is no
     * matcher here (rule 10 — never claim more than the device told you).
     */
    private function templatesMatch(Collection $g): bool
    {
        $byDevice = [];

        foreach ($g as $m) {
            $digests = is_string($m->digests) ? json_decode($m->digests, true) : $m->digests;

            foreach (is_array($digests) ? $digests : [] as $d) {
                $byDevice[(string) $d][$m->device_id] = true;
            }
        }

        foreach ($byDevice as $devices) {
            if (count($devices) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Card numbers that identify more than one PIN, and therefore identify nobody.
     *
     * @return list<int>
     */
    private function cardsHeldByMoreThanOnePin(Collection $maps): array
    {
        return $maps
            ->filter(fn ($m) => $m->card > 0)
            ->groupBy('card')
            ->filter(fn (Collection $g) => $g->pluck('pin')->unique()->count() > 1)
            ->keys()
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Case, spacing and punctuation all vary between terminals for one person. */
    private function normalise(?string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name ?? ''))) ?? '';
    }
}
