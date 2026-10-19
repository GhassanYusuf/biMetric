import { dayKey } from './model';

/*
| A SAMPLE employee report, for /employees/sample/report (2026-09-27).
|
| It exists so the report can be seen before any real attendance is in the system
| (the database was emptied on 2026-09-27). It has exactly the shape
| GET /api/employees/{id}/report returns, so the page renders it with the same code,
| and the page says "Sample data" on screen for as long as it is showing it.
|
| Deterministic (seeded) so it looks the same on every visit. Never used for a real
| employee — the real route always asks the server.
*/
export function sampleReport(periodDays = 30) {
    let seed = 20260927;
    const rnd = () => {
        seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    const shift = { start_minutes: 540, end_minutes: 1080, late_after_minutes: 555, grace_minutes: 15, work_days: [0, 1, 2, 3, 4], timezone: '+03:00' };
    const now = new Date();
    const end = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
    const span = Math.max(2 * periodDays, 365);
    const devices = ['Entrance', 'IT Room', 'Glass Door'];
    const holidays = { '12-16': 'National Day', '12-17': 'National Day', '01-01': 'New Year' };
    const days = [];

    for (let i = span - 1; i >= 0; i--) {
        const date = new Date(end - i * 864e5);
        if (!shift.work_days.includes(date.getUTCDay())) continue;
        const key = dayKey(date);
        const recent = i < 30;
        let outcome;
        let reason = null;
        if (holidays[key.slice(5)]) { outcome = 'excused'; reason = holidays[key.slice(5)]; }
        else if (recent) {
            outcome = { 2: 'late', 6: 'missed_punch_out', 9: 'late', 12: 'absent', 15: 'excused', 17: 'left_early', 22: 'late', 26: 'late' }[i] ?? 'on_time';
            if (outcome === 'excused') reason = 'Sick leave · approved waiver';
        } else {
            const x = rnd();
            outcome = x < 0.74 ? 'on_time' : x < 0.87 ? 'late' : x < 0.92 ? 'left_early' : x < 0.95 ? 'missed_punch_out' : x < 0.97 ? 'absent' : 'on_time';
        }
        let first = null;
        let last = null;
        let device = null;
        let method = null;
        if (['on_time', 'late', 'left_early', 'missed_punch_out'].includes(outcome)) {
            first = outcome === 'late' ? 560 + Math.floor(rnd() * 44) : 518 + Math.floor(rnd() * 32);
            last = outcome === 'missed_punch_out' ? null : outcome === 'left_early' ? 930 + Math.floor(rnd() * 90) : 1082 + Math.floor(rnd() * 50);
            const y = rnd();
            device = y < 0.62 ? devices[0] : y < 0.92 ? devices[1] : devices[2];
            method = rnd() < 0.88 ? 1 : 2;
        }
        const hours = last == null ? null : Math.round((last - first) / 6) / 10;
        const payroll = outcome === 'excused' ? 9 : last == null ? 0 : Math.round((Math.min(last, 1080) - Math.max(first, 540)) / 6) / 10;
        days.push({ date: key, outcome, excused_reason: reason, first_minutes: first, last_minutes: last, hours, payroll_hours: Math.max(payroll, 0), device, verify_method: method });
    }

    const periodFrom = dayKey(new Date(end - (periodDays - 1) * 864e5));
    const previousFrom = dayKey(new Date(end - (2 * periodDays - 1) * 864e5));
    const stats = (list) => {
        const present = list.filter((d) => d.first_minutes != null);
        const closed = present.filter((d) => d.last_minutes != null);
        const late = present.filter((d) => d.first_minutes > shift.late_after_minutes);
        const count = (o) => list.filter((d) => d.outcome === o).length;
        const expected = list.length - count('excused');
        const avg = (rows, k) => (rows.length ? rows.reduce((s, d) => s + d[k], 0) / rows.length : null);
        return {
            work_days: list.length, expected_days: expected, present_days: present.length,
            outcomes: { on_time: count('on_time'), late: count('late'), left_early: count('left_early'), missed_punch_out: count('missed_punch_out'), excused: count('excused'), absent: count('absent') },
            attendance_rate: expected ? present.length / expected : null,
            on_time_rate: present.length ? (present.length - late.length) / present.length : null,
            late_arrivals: late.length,
            average_minutes_late: late.length ? late.reduce((s, d) => s + d.first_minutes - shift.start_minutes, 0) / late.length : null,
            average_arrival_minutes: avg(present, 'first_minutes'),
            average_departure_minutes: avg(closed, 'last_minutes'),
            payroll_hours: Math.round(list.reduce((s, d) => s + d.payroll_hours, 0) * 10) / 10,
            missed_punch_outs: present.length - closed.length,
        };
    };
    const current = days.filter((d) => d.date >= periodFrom);
    const punchesOf = (d) => (d.first_minutes == null ? 0 : d.last_minutes == null ? 1 : 2);
    const byDevice = devices.map((dev) => ({ device: dev, count: current.filter((d) => d.device === dev).reduce((s, d) => s + punchesOf(d), 0) })).filter((d) => d.count);
    const byMethod = [1, 2].map((m) => ({ verify_method: m, count: current.filter((d) => d.verify_method === m).reduce((s, d) => s + punchesOf(d), 0) })).filter((m) => m.count);

    return {
        employee: { id: 'sample', name: 'Sample Employee', employee_code: '000', department: 'Sample department', job_title: 'Sample role', hired_date: null, privilege: 'user', photo_url: null, is_active: true },
        shift,
        period: { days: periodDays, from: periodFrom, to: dayKey(new Date(end)) },
        previous: { from: previousFrom, to: dayKey(new Date(end - periodDays * 864e5)) },
        span: { from: days[0].date, to: dayKey(new Date(end)) },
        tracking_from: days[0].date,
        stats: stats(current),
        previous_stats: stats(days.filter((d) => d.date >= previousFrom && d.date < periodFrom)),
        punches: { total: byDevice.reduce((s, d) => s + d.count, 0), by_device: byDevice, by_verify_method: byMethod },
        enrollment: [
            { device: 'Entrance', state: 'read', fingerprints: 2, has_card: true, synced_at: new Date(end).toISOString() },
            { device: 'IT Room', state: 'read', fingerprints: 2, has_card: false, synced_at: new Date(end - 864e5).toISOString() },
            { device: 'Glass Door', state: 'unknown', fingerprints: null, has_card: false, synced_at: new Date(end).toISOString() },
            { device: 'Camelot', state: 'never_read', fingerprints: null, has_card: false, synced_at: null },
        ],
        days,
    };
}
