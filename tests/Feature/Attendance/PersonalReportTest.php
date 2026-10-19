<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Reports\PersonalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One person's report for any period (2026-10-18) — the page HR hands out, alone or
 * inside the everybody-for-September file.
 */
class PersonalReportTest extends TestCase
{
    use AttendanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScenario();
    }

    private function one(string $name): array
    {
        return app(PersonalReport::class)->build([$this->people[$name]], self::FROM, self::TO)[0];
    }

    /** The PDF and the Employee Report screen give the same figures for the same days. */
    public function test_the_figures_are_the_persons_own_employee_report(): void
    {
        foreach (['Punctual', 'Patchy', 'New Starter', 'No Show'] as $name) {
            $own = app(EmployeeReportService::class)->report($this->people[$name], 12, self::TO);

            $this->assertEquals($own['stats'], $this->one($name)['stats'], "{$name} disagrees with their own report");
        }
    }

    /**
     * The bulk export renders people a chunk at a time. Whoever else is in the chunk,
     * a person's report must be the one they would get alone.
     */
    public function test_a_report_built_with_others_is_the_report_built_alone(): void
    {
        $together = app(PersonalReport::class)->build(array_values($this->people), self::FROM, self::TO);

        foreach ($together as $report) {
            $alone = app(PersonalReport::class)->build([Employee::find($report['employee']['id'])], self::FROM, self::TO)[0];
            $this->assertEquals($alone, $report, "{$report['employee']['name']} changes with company");
        }
    }

    public function test_every_punch_is_listed_with_its_terminal_and_method(): void
    {
        $days = collect($this->one('Patchy')['days'])->keyBy('date');

        $this->assertSame(
            [['minutes' => 9 * 60 + 40, 'device' => 'Main Gate', 'method' => \App\Support\VerifyMethod::label(1)],
             ['minutes' => 18 * 60 + 1, 'device' => 'Main Gate', 'method' => \App\Support\VerifyMethod::label(1)]],
            $days['2026-08-03']['punches'],
        );
        // One punch: listed, and the day has no departure — never a copy of the arrival.
        $this->assertCount(1, $days['2026-08-06']['punches']);
        $this->assertSame('missed_punch_out', $days['2026-08-06']['outcome']);
        $this->assertNull($days['2026-08-06']['hours']);
        $this->assertSame([], $days['2026-08-04']['punches']);
        // 7 closed days of two punches, and the 6th's one.
        $this->assertSame(15, $this->one('Patchy')['where']['total']);
    }

    public function test_days_before_the_hire_date_are_not_in_the_report(): void
    {
        $report = $this->one('New Starter');

        $this->assertSame('2026-08-09', $report['days'][0]['date']);
        $this->assertSame('untracked', $report['calendar']['2026-08-05']);
        $this->assertSame(5, $report['stats']['expected_days']);
    }

    public function test_the_period_is_the_one_asked_for_and_compared_with_the_one_before(): void
    {
        $report = $this->one('Punctual');

        $this->assertSame(['from' => self::FROM, 'to' => self::TO, 'days' => 12], $report['period']);
        $this->assertSame(['from' => '2026-07-21', 'to' => '2026-08-01'], $report['previous']);
    }
}
