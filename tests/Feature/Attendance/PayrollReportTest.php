<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceWaiver;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Reports\PayrollReport;
use App\Support\Tenancy;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the payroll report computes, and how much work it does computing it.
 *
 * The numbers here were pinned by running this exact scenario through BOTH the
 * pre-batching implementation and the current one on 2026-09-15 and asserting they
 * agreed, row for row and day for day, across every branch the summary has: early
 * arrival, late arrival, a missed punch-out, plain absence, a holiday and a waiver.
 * The old code is gone; these figures are what survived it, and any change to them
 * is a change to somebody's pay.
 *
 * The query-count assertion at the end is not a micro-optimisation. This report
 * summarised 357 employees by issuing three queries EACH — 1,072 queries, 694 ms —
 * and nothing in the test suite noticed, because an N+1 is invisible to a test that
 * only checks the answer. It is here so the loop cannot quietly come back.
 */
class PayrollReportTest extends TestCase
{
    use RefreshDatabase;

    /** A fortnight containing work days, weekends, a holiday and a waiver. */
    private const FROM = '2026-08-03';

    private const TO = '2026-08-16';

    /** The five days everyone who punches at all, punched on. */
    private const WORKED = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-09'];

    /** @var array<string, Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $device = Device::create([
            'tenant_id' => $tenant->id, 'name' => 'Main Gate', 'ip_address' => '192.0.2.1',
            'port' => 4370, 'is_active' => true,
        ]);

        foreach (['Early Bird', 'Late Riser', 'Half Day', 'Ghost', 'Excused'] as $i => $name) {
            $this->people[$name] = Employee::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'employee_code' => 'E'.(100 + $i),
                'is_active' => true,
            ]);
        }

        $shiftStart = config('attendance.shift_start');   // 09:00
        $shiftEnd = config('attendance.shift_end');       // 18:00

        $punch = function (Employee $e, string $day, string $time) use ($device, $tenant) {
            AttendanceLog::create([
                'tenant_id' => $tenant->id,
                'device_id' => $device->id,
                'device_user_id' => (string) $e->id,
                'employee_id' => $e->id,
                // Stored UTC, as every punch in this system is; the summary reads it
                // back in the office timezone. A test that wrote local time here
                // would pass while the app was three hours wrong.
                'punched_at' => Carbon::parse("{$day} {$time}", config('attendance.timezone'))->utc(),
                'verify_method' => 1,
                'status' => 0,
            ]);
        };

        foreach (self::WORKED as $day) {
            // In an hour early, out an hour late: counts as early + leave_late, and
            // payroll is CLIPPED to the shift, so it is 9 hours and not 11.
            $punch($this->people['Early Bird'], $day, Carbon::parse($shiftStart)->subHour()->format('H:i:s'));
            $punch($this->people['Early Bird'], $day, Carbon::parse($shiftEnd)->addHour()->format('H:i:s'));

            // Two hours late, an hour early away: come_late + leave_on_time, 6 hours.
            $punch($this->people['Late Riser'], $day, Carbon::parse($shiftStart)->addHours(2)->format('H:i:s'));
            $punch($this->people['Late Riser'], $day, Carbon::parse($shiftEnd)->subHour()->format('H:i:s'));

            // One punch and no second: present, but no payable span can be derived.
            $punch($this->people['Half Day'], $day, Carbon::parse($shiftStart)->format('H:i:s'));
        }

        // 'Ghost' never punches. Both they and 'Excused' get the holiday; only
        // 'Excused' gets the waiver.
        Holiday::create(['tenant_id' => $tenant->id, 'date' => '2026-08-05', 'name' => 'National Day']);
        AttendanceWaiver::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $this->people['Excused']->id,
            'date' => '2026-08-06',
            'reason' => 'Medical',
        ]);
    }

    /** @return array<string, array<string, mixed>> rows keyed by employee name */
    private function rows(): array
    {
        return collect(app(PayrollReport::class)->build(self::FROM, self::TO)['rows'])
            ->keyBy('name')
            ->all();
    }

    public function test_it_reports_each_pattern_of_attendance_correctly(): void
    {
        $rows = $this->rows();

        // Clipped to the shift: eleven hours on site, nine payable, five days.
        $this->assertSame(45.0, (float) $rows['Early Bird']['total_payroll_hours']);
        $this->assertSame(5, $rows['Early Bird']['present_days']);
        $this->assertSame(0, $rows['Early Bird']['late_days']);

        // Six payable hours a day, and every one of those days is a late arrival.
        $this->assertSame(30.0, (float) $rows['Late Riser']['total_payroll_hours']);
        $this->assertSame(5, $rows['Late Riser']['late_days']);

        /*
         * PRESENT, BUT NOT PAID. A single punch cannot bound a working day, so the
         * day counts as attendance and contributes no hours — it must never be
         * guessed at, and it must never silently read as absent either.
         */
        $this->assertSame(5, $rows['Half Day']['present_days']);
        $this->assertSame(0.0, (float) $rows['Half Day']['total_payroll_hours']);
        $this->assertSame(5, $rows['Half Day']['total_punches']);

        // Never came in, but the public holiday is still credited at full shift, so
        // payroll is not reduced by a day nobody was meant to work.
        $this->assertSame(0, $rows['Ghost']['present_days']);
        $this->assertSame(1, $rows['Ghost']['excused_days']);
        $this->assertSame(9, $rows['Ghost']['absent_days']);
        $this->assertSame(9.0, (float) $rows['Ghost']['total_payroll_hours']);

        // The same, plus a waiver: two excused days, eighteen credited hours.
        $this->assertSame(2, $rows['Excused']['excused_days']);
        $this->assertSame(8, $rows['Excused']['absent_days']);
        $this->assertSame(18.0, (float) $rows['Excused']['total_payroll_hours']);
    }

    public function test_the_totals_are_the_sum_of_the_rows(): void
    {
        $report = app(PayrollReport::class)->build(self::FROM, self::TO);

        $this->assertSame(5, $report['totals']['employees']);
        $this->assertSame(102.0, (float) $report['totals']['payroll_hours']);
        $this->assertSame(
            round(collect($report['rows'])->sum('total_payroll_hours'), 1),
            round($report['totals']['payroll_hours'], 1),
        );
    }

    /**
     * The report and the employee screen must agree, because one is the other
     * summed. They share AttendanceSummaryService for exactly this reason.
     */
    public function test_a_row_reconciles_with_that_employees_own_daily_breakdown(): void
    {
        $summary = app(AttendanceSummaryService::class)
            ->summary($this->people['Late Riser'], self::FROM, self::TO);

        $row = $this->rows()['Late Riser'];

        $this->assertSame($row['total_payroll_hours'], $summary['total_payroll_hours']);
        $this->assertSame($row['total_punches'], $summary['total_punches']);
        $this->assertSame($row['late_days'], $summary['categories']['come_late']);

        $worked = collect($summary['daily'])->firstWhere('date', self::WORKED[0]);
        $this->assertSame('present', $worked['status']);
        $this->assertSame('come_late', $worked['arrival']);
        $this->assertSame('leave_on_time', $worked['departure']);
        $this->assertSame(6.0, (float) $worked['payroll_hours']);
    }

    /**
     * THE GUARD. Five employees must cost the same handful of queries as five
     * hundred: one for the roster, and one each for punches, holidays and waivers.
     *
     * Asserted as a bound rather than an exact number so an unrelated eager load
     * does not fail the build, but tight enough that a per-employee query cannot
     * hide under it.
     */
    public function test_the_report_reads_the_whole_roster_in_a_fixed_number_of_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(PayrollReport::class)->build(self::FROM, self::TO);

        $count = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(
            8,
            $count,
            "The payroll report issued {$count} queries for 5 employees. It used to issue three PER employee — "
            .'see SummaryBatch. Pass the batch down rather than letting summary() read for itself.',
        );
    }
}
