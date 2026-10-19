<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Reports\AttendanceReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The company attendance report (2026-10-18): what it counts, and what it refuses to
 * count.
 *
 * The scenario is the shape the real roster had when the report was built. In
 * September 2026, 73 of 132 tracked people had no punch at all, because their terminal
 * was not syncing or they were not enrolled. Pooled in, they turned a 78.6% attendance
 * rate into 35.1%. So the tests pin that a no-show is named and kept out of the rates,
 * that a never-tracked person is not counted at all, and that a person's line is
 * exactly their own Employee Report for the same days.
 */
class AttendanceReportTest extends TestCase
{
    use AttendanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScenario();
    }


    private function build(): array
    {
        return app(AttendanceReport::class)->build(self::FROM, self::TO, $this->lab->id);
    }

    /** @return list<string> */
    private static function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function test_a_no_show_is_named_and_kept_out_of_the_rates(): void
    {
        $r = $this->build();

        $this->assertSame(4, $r['headcount']);
        $this->assertSame(3, $r['punching']);
        $this->assertSame(['No Show'], self::names($r['attention']['no_show']));

        // Punctual 10 + Patchy 10 + New Starter 5 expected; present 10 + 8 + 5.
        $this->assertSame(25, $r['stats']['expected_days']);
        $this->assertSame(23, $r['stats']['present_days']);
        $this->assertSame(0.92, $r['stats']['attendance_rate']);
        $this->assertSame(2, $r['stats']['outcomes']['absent']);

        // The all-in figure still exists, and is printed beside the headline.
        $this->assertSame(35, $r['all_in_stats']['expected_days']);
        $this->assertSame(12, $r['all_in_stats']['outcomes']['absent']);
    }

    public function test_a_person_never_tracked_is_not_counted_at_all(): void
    {
        $r = $this->build();

        $this->assertSame(['Never Tracked'], self::names($r['never_tracked']));
        $this->assertNotContains('Never Tracked', self::names($r['people']));
        $this->assertNotContains('Never Tracked', self::names($r['attention']['no_show']));
    }

    public function test_days_before_a_hire_date_count_nowhere(): void
    {
        $starter = collect($this->build()['people'])->firstWhere('name', 'New Starter');

        $this->assertSame(5, $starter['stats']['expected_days']);
        $this->assertSame(0, $starter['stats']['outcomes']['absent']);
        $this->assertSame(1.0, $starter['stats']['attendance_rate']);
    }

    /**
     * A person's line here is their own Employee Report over the same days, figure for
     * figure. If these ever differ, a manager and the employee are holding two
     * different accounts of the same fortnight.
     */
    public function test_each_line_is_that_persons_own_employee_report(): void
    {
        $r = $this->build();
        $days = Carbon::parse(self::FROM)->diffInDays(Carbon::parse(self::TO)) + 1;

        foreach ($r['people'] as $row) {
            $own = app(EmployeeReportService::class)->report(Employee::find($row['id']), (int) $days, self::TO);

            $this->assertSame(self::FROM, $own['period']['from']);
            $this->assertEquals($own['stats'], $row['stats'], "{$row['name']} disagrees with their own report");
        }
    }

    public function test_the_short_lists_name_only_people_it_happened_to(): void
    {
        $a = $this->build()['attention'];

        $this->assertSame(['Patchy'], self::names($a['late']));
        $this->assertSame(['Patchy'], self::names($a['absent']));
        $this->assertSame(['Patchy'], self::names($a['missed']));
        $this->assertEqualsCanonicalizing(['Punctual', 'New Starter'], self::names($a['perfect']));
    }

    public function test_a_department_includes_the_departments_beneath_it(): void
    {
        $r = $this->build();

        $this->assertContains('Patchy', self::names($r['people']));
        $this->assertSame('Lab', $r['scope']);
        $this->assertEqualsCanonicalizing(['Lab', 'Lab › Bench'], array_column($r['departments'], 'name'));
    }

    public function test_the_comparison_is_the_same_length_immediately_before(): void
    {
        $r = $this->build();

        $this->assertSame(['from' => '2026-07-21', 'to' => '2026-08-01'], $r['previous']);
        // Nobody punched then, so there is nothing to compare — not a 0% rate.
        $this->assertNull($r['previous_stats']['attendance_rate']);
    }

    public function test_the_daily_columns_count_everybody_tracked_each_day(): void
    {
        $timeline = $this->build()['timeline'];

        $this->assertSame('day', $timeline['grain']);
        $this->assertCount(10, $timeline['buckets']);

        $byDay = collect($timeline['buckets'])->keyBy('key');
        $this->assertSame(2, $byDay['2026-08-04']['tracked']);      // before New Starter
        $this->assertSame(1, $byDay['2026-08-04']['outcomes']['absent']);
        $this->assertSame(3, $byDay['2026-08-10']['tracked']);
    }
}
