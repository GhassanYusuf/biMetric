<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * The scan behind the Scan button: every duplicate candidate we can name, with
 * the evidence for each and how much of it we actually believe.
 *
 * Two sources are folded together:
 *
 *   DuplicateEmployeeFinder   the same person on several terminals, proved by
 *                             PIN, card or an identical fingerprint template
 *   RecordDuplicateFinder     the same person in our own records, proved by a
 *                             shared code, card, email or phone
 *
 * They are joined with a union-find rather than listed side by side, because the
 * two overlap in every interesting case: records already grouped by device may
 * also share an email, and the operator should see one suggestion with both
 * reasons rather than two suggestions of the same two people.
 *
 * TIERS, and what each one is worth:
 *
 *   strong       an identifier matched, or hardware evidence backs the match.
 *                Merge is offered immediately.
 *   possible     resemblance only — a shared email or phone, an unconfirmed
 *                device group, or a name. Offered, but the operator has to say
 *                out loud that they recognise both people first.
 *   do_not_merge the cards disagree. Shown so it is not a mystery why it is
 *                missing, and never mergeable from the screen.
 *
 * The tier is not a probability. It is a statement of what the evidence is, in
 * the words the evidence came with (rule 10 — never claim more than the device
 * told you).
 */
class DuplicateScanService
{
    public const STRONG = 'strong';
    public const POSSIBLE = 'possible';
    public const DO_NOT_MERGE = 'do_not_merge';

    /**
     * How many records may share a bare name and still be offered as a
     * suggestion. See the note where it is used.
     */
    private const NAME_GROUP_SIZE = 2;

    public function __construct(
        private readonly DuplicateEmployeeFinder $deviceFinder,
        private readonly RecordDuplicateFinder $recordFinder,
    ) {}

    /**
     * @return array{groups: list<array>, counts: array<string, int>}
     */
    public function scan(?int $onlyEmployeeId = null): array
    {
        $employees = Employee::query()
            ->with(['deviceMaps' => fn ($q) => $q->live(), 'deviceMaps.device:id,name'])
            ->withCount('attendanceLogs')
            ->get()
            ->keyBy('id');

        $counts = [self::STRONG => 0, self::POSSIBLE => 0, self::DO_NOT_MERGE => 0, 'total' => 0];

        if ($employees->isEmpty()) {
            return ['groups' => [], 'counts' => $counts];
        }

        $deviceGroups = $this->deviceFinder->find();
        $signals = $this->recordFinder->find($employees->values());

        [$parent, $find, $union] = $this->disjointSet($employees->keys()->all());

        // Both sources contribute links. An id that is not in $employees (another
        // tenant, or a record deleted since the finder ran) is simply not linked.
        foreach ($deviceGroups as $g) {
            foreach ($g['merge'] as $id) {
                if (isset($parent[$id]) && isset($parent[$g['keep']])) {
                    $union($g['keep'], $id);
                }
            }
        }

        foreach ($signals['identifier'] as $sig) {
            foreach ($sig['ids'] as $id) {
                if (isset($parent[$id]) && isset($parent[$sig['ids'][0]])) {
                    $union($sig['ids'][0], $id);
                }
            }
        }

        // Evidence attached to whichever component the link created.
        $deviceEvidence = [];
        foreach ($deviceGroups as $g) {
            if (! isset($parent[$g['keep']])) {
                continue;
            }
            $deviceEvidence[$find($g['keep'])][] = [
                'verdict' => $g['verdict'],
                'reason' => $g['reason'],
                'pin' => $g['pin'],
            ];
        }

        $signalEvidence = [];
        foreach ($signals['identifier'] as $sig) {
            if (! isset($parent[$sig['ids'][0]])) {
                continue;
            }
            $signalEvidence[$find($sig['ids'][0])][] = $sig;
        }

        $components = [];
        foreach ($employees->keys() as $id) {
            $components[$find($id)][] = $id;
        }
        $components = array_filter($components, fn (array $ids) => count($ids) > 1);

        $claimed = [];
        foreach ($components as $ids) {
            foreach ($ids as $id) {
                $claimed[$id] = true;
            }
        }

        $groups = [];

        foreach ($components as $root => $ids) {
            $groups[] = $this->buildGroup(
                $ids,
                $employees,
                $deviceEvidence[$root] ?? [],
                $signalEvidence[$root] ?? [],
            );
        }

        /*
         * Name matches are handled last and only among records nothing else has
         * claimed. Without this a surname shared by five people chains into one
         * suggestion of twenty — technically correct, practically useless.
         */
        foreach ($signals['name'] as $nameGroup) {
            $roots = array_unique(array_map(fn ($id) => $find($id), $nameGroup['ids']));

            if (count($roots) === 1) {
                // Already a group on other grounds: the shared name is corroboration.
                if (isset($components[reset($roots)])) {
                    continue;
                }
            }

            $unclaimed = array_values(array_filter($nameGroup['ids'], fn ($id) => ! isset($claimed[$id])));

            /*
             * Three or more records sharing a bare name is a COMMON NAME, not a
             * duplicate. Measured on the live roster: "Ahmed" matched seven
             * records with seven different codes and four of them carrying real
             * history — merging those would pool the hours of seven men. Two
             * records sharing a name, on the other hand, is the import artefact
             * as it actually appears here (one row with the history, one with a
             * code from a later import and no punches at all).
             *
             * Suppressed rather than shown-and-disabled, because a suggestion the
             * operator must learn to ignore is worse than no suggestion. They can
             * still merge by hand from the row menu, which is the path for
             * anything the evidence cannot settle.
             */
            if (count($unclaimed) === self::NAME_GROUP_SIZE) {
                $groups[] = $this->buildGroup($unclaimed, $employees, [], [], nameOnly: true);
            }
        }

        $groups = $this->sort($groups);

        if ($onlyEmployeeId !== null) {
            $groups = array_values(array_filter(
                $groups,
                fn (array $g) => in_array($onlyEmployeeId, array_column($g['records'], 'id'), true),
            ));
        }

        foreach ($groups as $g) {
            $counts[$g['tier']]++;
            $counts['total']++;
        }

        return ['groups' => $groups, 'counts' => $counts];
    }

    /**
     * The merge suggestion for one set of records: who to keep, what each record
     * holds, and the evidence that they are the same person.
     *
     * @param  list<int>  $ids
     * @param  list<array>  $deviceEvidence
     * @param  list<array>  $signalEvidence
     */
    private function buildGroup(
        array $ids,
        Collection $employees,
        array $deviceEvidence,
        array $signalEvidence,
        bool $nameOnly = false,
    ): array {
        $ids = array_values(array_unique($ids));

        $records = collect($ids)
            ->map(fn (int $id) => $employees->get($id))
            ->filter()
            ->map(function (Employee $e) {
                $terminals = $e->deviceMaps
                    ->filter(fn ($m) => $m->valid_to === null && $m->device)
                    ->map(fn ($m) => $m->device->name)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_code' => $e->employee_code,
                    'punches' => (int) ($e->attendance_logs_count ?? 0),
                    'terminals' => $terminals,
                ];
            })
            ->values();

        if ($records->count() < 2) {
            return ['id' => '', 'tier' => self::POSSIBLE, 'records' => []];
        }

        // The record with the history is kept, so a merge moves the least — the
        // same choice DuplicateEmployeeFinder makes, for the same reason.
        $sorted = $records->sortByDesc(fn (array $r) => [$r['punches'], -$r['id']])->values();

        $signals = [];
        $verdicts = array_column($deviceEvidence, 'verdict');

        foreach ($deviceEvidence as $d) {
            $signals[] = sprintf('PIN %s across terminals — %s', $d['pin'], $d['reason']);
        }

        foreach ($signalEvidence as $s) {
            $signals[] = $s['label'].' '.$this->presentableValue($s['signal'], $s['value']);
        }

        if ($nameOnly || count(array_unique(array_map(fn (array $r) => $this->normaliseName($r['name']), $sorted->all()))) === 1) {
            $signals[] = 'Same name';
        }

        $signals = array_values(array_unique($signals));

        $tier = $this->tier($deviceEvidence, $signalEvidence);

        return [
            'id' => 'g'.implode('-', $sorted->pluck('id')->sort()->values()->all()),
            'tier' => $tier,
            'source' => $deviceEvidence !== [] && $signalEvidence !== [] ? 'both'
                : ($deviceEvidence !== [] ? 'device' : ($nameOnly ? 'name' : 'record')),
            'signals' => $signals,
            'reason' => $signals[0] ?? 'Same person',
            'name' => $sorted->first()['name'],
            'punches' => $sorted->sum('punches'),
            'keep' => $sorted->first()['id'],
            'records' => $sorted->all(),
        ];
    }

    /**
     * @param  list<array>  $deviceEvidence
     * @param  list<array>  $signalEvidence
     */
    private function tier(array $deviceEvidence, array $signalEvidence): string
    {
        $verdicts = array_column($deviceEvidence, 'verdict');

        // Cards disagreeing is the evidence pointing AWAY from a merge. No other
        // signal is allowed to overrule it from this screen — the CLI refuses
        // this verdict too, and a flag that overrides evidence gets used.
        if (in_array(DuplicateEmployeeFinder::CONTRADICTED, $verdicts, true)) {
            return self::DO_NOT_MERGE;
        }

        foreach ([
            DuplicateEmployeeFinder::NAMESPACED,
            DuplicateEmployeeFinder::PROVEN,
            DuplicateEmployeeFinder::CONFIRMED,
        ] as $strong) {
            if (in_array($strong, $verdicts, true)) {
                return self::STRONG;
            }
        }

        foreach ($signalEvidence as $s) {
            if ($s['strength'] === 'strong') {
                return self::STRONG;
            }
        }

        return self::POSSIBLE;
    }

    /** Strongest first, then by how much history the group carries. */
    private function sort(array $groups): array
    {
        $rank = [self::STRONG => 0, self::POSSIBLE => 1, self::DO_NOT_MERGE => 2];

        usort($groups, function (array $a, array $b) use ($rank) {
            return [$rank[$a['tier']], -$a['punches']] <=> [$rank[$b['tier']], -$b['punches']];
        });

        return $groups;
    }

    /**
     * A disjoint set over the employee ids, so two sources can contribute links
     * to the same suggestion.
     *
     * @param  list<int>  $ids
     * @return array{0: array<int,int>, 1: \Closure, 2: \Closure}
     */
    private function disjointSet(array $ids): array
    {
        $parent = array_fill_keys($ids, 0);
        foreach ($ids as $id) {
            $parent[$id] = $id;
        }

        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };

        $union = function (int $a, int $b) use (&$parent, $find): void {
            if (! isset($parent[$a]) || ! isset($parent[$b])) {
                return;
            }

            $ra = $find($a);
            $rb = $find($b);

            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        return [$parent, $find, $union];
    }

    private function presentableValue(string $signal, string $value): string
    {
        if ($signal === 'email') {
            return $value;
        }

        if (in_array($signal, ['mobile_phone', 'home_phone', 'card_number'], true)) {
            return $value;
        }

        return '#'.$value;
    }

    private function normaliseName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name))) ?? '';
    }
}
