import type { ActivityWindow, Interval } from './types';

/**
 * Dates as the reader reads them, in the time zone of the browser. The page is in English, the hours have 24.
 */
const LOCALE = 'en-US';

const time = new Intl.DateTimeFormat(LOCALE, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const dayOfYear = new Intl.DateTimeFormat(LOCALE, { weekday: 'long', month: 'long', day: 'numeric' });
const fullDay = new Intl.DateTimeFormat(LOCALE, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
const full = new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short', hourCycle: 'h23' });
const shortDay = new Intl.DateTimeFormat(LOCALE, { month: 'short', day: 'numeric' });
const numbers = new Intl.NumberFormat(LOCALE);

export function formatNumber(value: number): string {
    return numbers.format(value);
}

export function formatTime(date: Date): string {
    return time.format(date);
}

export function formatFull(date: Date): string {
    return full.format(date);
}

/**
 * A day in short, like "Nov 20".
 */
export function formatDay(date: Date): string {
    return shortDay.format(date);
}

/**
 * The day of the date in the time zone of the browser, "2026-11-21": the key of a group of the stream.
 */
export function dayKey(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * The title of the group of a day: "Today", "Yesterday", the weekday and date, the year when it is another one.
 */
export function dayTitle(date: Date, now: Date = new Date()): string {
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (dayKey(date) === dayKey(now)) {
        return 'Today';
    }
    if (dayKey(date) === dayKey(yesterday)) {
        return 'Yesterday';
    }

    return date.getFullYear() === now.getFullYear() ? dayOfYear.format(date) : fullDay.format(date);
}

/**
 * A key of a bucket of the statistics as a date: "2026-11-16T14" or "2026-11-16". It names a time of the time
 * zone, so it is read in UTC and formatted in UTC: no shift.
 */
function bucketDate(key: string): Date {
    const [day, hour] = key.split('T');
    const [year, month, date] = (day ?? '').split('-').map(Number);

    return new Date(Date.UTC(year ?? 1970, (month ?? 1) - 1, date ?? 1, Number(hour ?? 0)));
}

const utc = (options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(LOCALE, { ...options, timeZone: 'UTC' });
const bucketHour = utc({ weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const bucketDay = utc({ weekday: 'short', month: 'short', day: 'numeric' });
const bucketShort = utc({ month: 'short', day: 'numeric' });
const bucketHourShort = utc({ hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const bucketMonth = utc({ month: 'long', year: 'numeric' });
const bucketMonthShort = utc({ month: 'short', year: '2-digit' });

/**
 * The bucket as the tooltip and the table name it: "Mon 14:00", "Mon, Nov 16", "Week of Nov 16", "November 2026".
 */
export function bucketLabel(key: string, interval: Interval): string {
    const date = bucketDate(key);
    switch (interval) {
        case 'hour':
            return bucketHour.format(date);
        case 'week':
            return 'Week of ' + bucketShort.format(date);
        case 'month':
            return bucketMonth.format(date);
        default:
            return bucketDay.format(date);
    }
}

/**
 * The short name of the bucket on the axis: "14:00", "Nov 16", "Nov 26".
 */
export function bucketTick(key: string, interval: Interval): string {
    const date = bucketDate(key);
    switch (interval) {
        case 'hour':
            return bucketHourShort.format(date);
        case 'month':
            return bucketMonthShort.format(date);
        default:
            return bucketShort.format(date);
    }
}

/**
 * The days of a bucket for the filter: one day, Monday to Sunday of a week, or the days of a month.
 */
export function bucketDays(key: string, interval: Interval): { from: string; to: string } {
    if (interval === 'month') {
        const first = bucketDate(key);
        const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0));

        return { from: first.toISOString().slice(0, 10), to: last.toISOString().slice(0, 10) };
    }
    const from = key.slice(0, 10);
    if (interval !== 'week') {
        return { from, to: from };
    }
    const last = bucketDate(from);
    last.setUTCDate(last.getUTCDate() + 6);

    return { from, to: last.toISOString().slice(0, 10) };
}

/**
 * Whether the bucket is the last one of a window that ends now: its time is not over, it counts what came so far.
 */
export function isSoFar(window: ActivityWindow, bucket: number, buckets: number): boolean {
    return window.open && bucket === buckets - 1;
}

/**
 * The tooltip of a bucket, with a note on its first line when its time is not over.
 */
export function soFarTip(tip: string, soFar: boolean): string {
    if (!soFar) {
        return tip;
    }
    const [first = '', ...rest] = tip.split('\n');

    return [`${first}, so far`, ...rest].join('\n');
}
