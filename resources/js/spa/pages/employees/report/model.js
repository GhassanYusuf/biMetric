import { formatClock } from '../../../shared/clockFormat';
import { BRAND, STATUS_COLORS } from '../../../shared/theme';
import { verifyLabel } from '../../attendance/parts';

/*
| Turns the server's employee report into what each chart draws.
|
| NOTHING HERE DECIDES A DAY. Late, left early, absent, excused and the payroll hours
| all arrive already judged (EmployeeReportService, on AttendanceSummaryService), so
| this screen and the payroll report cannot disagree. This file only groups, slices
| and formats — and keeps "no data" as null all the way to the screen (rule 3).
|
| Colours are the dashboard's own attendance colours (STATUS_COLORS in shared/theme.js),
| so a late day here is the same orange as on the dashboard donut. Owner, 2026-09-27:
| "use the dashboard colors styling". Identity never rests on colour alone — every
| chart is labelled, and the legend names each colour.
*/
export const OUTCOMES = [
    { key: 'on_time', label: 'On time', color: STATUS_COLORS.present },
    { key: 'late', label: 'Late arrival', color: STATUS_COLORS.late },
    { key: 'excused', label: 'Excused', color: STATUS_COLORS.excused },
    { key: 'left_early', label: 'Left early', color: STATUS_COLORS.leave_on_time },
    { key: 'missed_punch_out', label: 'No punch-out', color: STATUS_COLORS.missed_punch_out },
    { key: 'absent', label: 'Absent', color: STATUS_COLORS.absent },
];
export const OUTCOME = Object.fromEntries(OUTCOMES.map((o) => [o.key, o]));
export const ACCENT = BRAND.primary;
export const STATUS = { critical: '#e11d48', serious: '#ea580c', warning: '#d97706' };

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export const PERIODS = [
    { days: 30, label: '30 days' },
    { days: 90, label: 'Quarter' },
    { days: 365, label: 'Year' },
];

// 12-hour unless the viewer switched the page to 24 (shared/clockFormat.js).
export function clock(minutes) {
    return formatClock(minutes);
}

// Dates are local calendar days ('YYYY-MM-DD'); parse them as UTC so no browser
// timezone can move a day across midnight.
export function parseDay(key) {
    const [y, m, d] = key.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}
export function dayKey(date) {
    return date.toISOString().slice(0, 10);
}
export function dayLabel(key) {
    const d = parseDay(key);
    return `${WEEKDAYS[d.getUTCDay()]} ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`;
}
export function rangeLabel(from, to) {
    const a = parseDay(from);
    const b = parseDay(to);
    return `${a.getUTCDate()} ${MONTHS[a.getUTCMonth()]} – ${b.getUTCDate()} ${MONTHS[b.getUTCMonth()]} ${b.getUTCFullYear()}`;
}

const pct = (v) => (v == null ? '—' : `${(v * 100).toFixed(1)}%`);

/** What happened on one day, in words — used by every hover readout and the log. */
export function describeDay(day, shift) {
    if (!day) return { headline: '', detail: '' };
    switch (day.outcome) {
        case 'untracked':
            return { headline: 'Not tracked', detail: 'Before this person was in the system' };
        case 'absent':
            return { headline: 'Absent', detail: 'No punches on any terminal, no holiday or waiver' };
        case 'excused':
            return { headline: 'Excused', detail: day.excused_reason || 'Holiday or waiver' };
        case 'missed_punch_out':
            return { headline: `${clock(day.first_minutes)} → ?`, detail: 'No punch-out · hours not counted' };
        default: {
            const late = day.outcome === 'late' ? ` by ${Math.round(day.first_minutes - shift.start_minutes)} min` : '';
            return {
                headline: `${clock(day.first_minutes)} → ${clock(day.last_minutes)}`,
                detail: `${day.hours?.toFixed(1) ?? '—'} h · ${OUTCOME[day.outcome].label}${late}`,
            };
        }
    }
}

export function deriveReport(report) {
    const { stats: S, previous_stats: P, shift, period } = report;
    const tracked = report.days.filter((d) => d.outcome !== 'untracked');
    const inPeriod = report.days.filter((d) => d.date >= period.from);

    // ── KPIs ────────────────────────────────────────────────────────────────
    const delta = (a, b, fmt) => (a == null || b == null ? 'Nothing earlier to compare with' : fmt(a - b));
    const kpis = [
        {
            label: 'Attendance',
            value: pct(S.attendance_rate),
            note: delta(S.attendance_rate, P.attendance_rate, (d) => `${d >= 0 ? '+' : '−'}${Math.abs(d * 100).toFixed(1)} pts vs previous ${period.days} days`),
        },
        {
            label: 'On-time arrivals',
            value: pct(S.on_time_rate),
            note: S.late_arrivals ? `${S.late_arrivals} late · avg ${Math.round(S.average_minutes_late)} min after ${clock(shift.start_minutes)}` : S.present_days ? 'No late arrivals' : 'No days present',
        },
        {
            label: 'Average arrival',
            value: clock(S.average_arrival_minutes),
            note: delta(S.average_arrival_minutes, P.average_arrival_minutes, (d) => (Math.round(d) === 0 ? 'Same as the previous period' : `${Math.abs(Math.round(d))} min ${d < 0 ? 'earlier' : 'later'} than the previous period`)),
        },
        {
            label: 'Average departure',
            value: clock(S.average_departure_minutes),
            note: `Shift ends ${clock(shift.end_minutes)}`,
        },
        {
            label: 'Missed punch-outs',
            value: S.present_days ? String(S.missed_punch_outs) : '—',
            note: S.missed_punch_outs ? 'Hours for those days are not counted' : S.present_days ? 'Every day closed' : 'No days present',
        },
    ];

    // ── Orbit + drift: the period's tracked work days, most recent 31 at most ──
    const periodTracked = inPeriod.filter((d) => d.outcome !== 'untracked');
    const orbitDays = periodTracked.slice(-31);

    // ── Split ───────────────────────────────────────────────────────────────
    const split = OUTCOMES.map((o) => ({ ...o, count: S.outcomes[o.key] ?? 0 })).filter((o) => o.count > 0);

    // ── Year calendar: every day of the last 365, work day or not ─────────────
    const byDate = new Map(report.days.map((d) => [d.date, d]));
    const end = parseDay(report.span.to);
    const calendar = [];
    for (let i = 364; i >= 0; i--) {
        const date = new Date(end.getTime() - i * 864e5);
        const key = dayKey(date);
        calendar.push({ key, weekday: date.getUTCDay(), dom: date.getUTCDate(), month: date.getUTCMonth(), day: byDate.get(key) ?? null });
    }

    // ── Weekday lateness and monthly punctuality (last 365 days) ─────────────
    const yearStart = calendar[0].key;
    const year = tracked.filter((d) => d.date >= yearStart);
    const weekday = shift.work_days.map((w) => ({
        label: WEEKDAYS[w],
        value: year.filter((d) => parseDay(d.date).getUTCDay() === w && d.first_minutes != null && d.first_minutes > shift.late_after_minutes).length,
    }));
    const months = [];
    for (let i = 11; i >= 0; i--) {
        const ref = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth() - i, 1));
        const prefix = ref.toISOString().slice(0, 7);
        const present = year.filter((d) => d.date.startsWith(prefix) && d.first_minutes != null);
        const late = present.filter((d) => d.first_minutes > shift.late_after_minutes).length;
        months.push({
            label: MONTHS[ref.getUTCMonth()],
            full: `${MONTHS[ref.getUTCMonth()]} ${ref.getUTCFullYear()}`,
            value: present.length ? (present.length - late) / present.length : null,
            days: present.length,
        });
    }

    // ── Where and how ───────────────────────────────────────────────────────
    const total = report.punches.total;
    const where = {
        total,
        devices: report.punches.by_device.map((d) => ({ label: d.device, count: d.count, share: total ? d.count / total : 0 })),
        methods: report.punches.by_verify_method.map((m) => ({ label: verifyLabel(m.verify_method), count: m.count, share: total ? m.count / total : 0 })),
    };

    // ── Mission log: the last ten tracked work days of the period ────────────
    const log = periodTracked.slice(-10).reverse().map((d) => {
        const where = [d.device, d.verify_method != null ? verifyLabel(d.verify_method) : null].filter(Boolean).join(' · ');
        let title;
        let detail;
        switch (d.outcome) {
            case 'late': title = `Late by ${Math.round(d.first_minutes - shift.start_minutes)} min`; detail = `In ${clock(d.first_minutes)} · out ${clock(d.last_minutes)}${where ? ` · ${where}` : ''}`; break;
            case 'left_early': title = `Left early at ${clock(d.last_minutes)}`; detail = `In ${clock(d.first_minutes)} · ${d.hours?.toFixed(1)} h`; break;
            case 'missed_punch_out': title = 'No punch-out'; detail = `In ${clock(d.first_minutes)}${d.device ? ` at ${d.device}` : ''} — hours not counted until corrected`; break;
            case 'absent': title = 'Absent'; detail = 'No punches on any terminal, no holiday or waiver'; break;
            case 'excused': title = 'Excused'; detail = d.excused_reason || 'Holiday or waiver'; break;
            default: title = `In ${clock(d.first_minutes)} · out ${clock(d.last_minutes)}`; detail = `${d.hours?.toFixed(1)} h${where ? ` · ${where}` : ''}`;
        }
        return { key: d.date, date: dayLabel(d.date), color: OUTCOME[d.outcome].color, title, detail };
    });

    // ── Needs attention ─────────────────────────────────────────────────────
    const alerts = [];
    const missed = periodTracked.filter((d) => d.outcome === 'missed_punch_out');
    if (missed.length) {
        const last = missed[missed.length - 1];
        alerts.push({ level: 'Serious', color: STATUS.serious, title: `${missed.length} missed punch-out${missed.length === 1 ? '' : 's'}`, detail: `Latest ${dayLabel(last.date)}, in ${clock(last.first_minutes)}. Those days are not in the payroll hours.` });
    }
    const absent = periodTracked.filter((d) => d.outcome === 'absent');
    if (absent.length) {
        alerts.push({ level: 'Critical', color: STATUS.critical, title: `${absent.length} unexplained absence${absent.length === 1 ? '' : 's'}`, detail: `Latest ${dayLabel(absent[absent.length - 1].date)} — no punches and no waiver on file.` });
    }
    if (S.late_arrivals && P.late_arrivals != null && S.late_arrivals > P.late_arrivals) {
        alerts.push({ level: 'Warning', color: STATUS.warning, title: `Lateness is up: ${S.late_arrivals} late arrivals`, detail: `${S.late_arrivals - P.late_arrivals} more than the previous ${period.days} days.` });
    }
    report.enrollment.filter((e) => e.state === 'unknown').forEach((e) => alerts.push({
        level: 'Warning', color: STATUS.warning, title: `${e.device}: fingerprints unknown`, detail: 'The template table would not decode — this is unknown, not zero fingerprints.',
    }));

    // ── The latest day, for the identity card ───────────────────────────────
    const lastSeen = [...tracked].reverse().find((d) => d.first_minutes != null) ?? null;

    return { kpis, orbitDays, split, calendar, weekday, months, where, log, alerts, lastSeen, periodTracked };
}

