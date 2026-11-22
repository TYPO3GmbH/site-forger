import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { formatNumber } from './format';
import { handledByPage, LightElement } from './light-element';
import { withTooltips } from './tooltip';
import { pageParameters, withQuery, type ActivityState } from './state';
import type { ActivityCalendar as Calendar } from './types';

withTooltips();

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * The weekdays the side names: every other one, as the rows are small.
 */
const NAMED_WEEKDAYS = [0, 2, 4];

/**
 * The steps of the ramp above "none": "--stream-heat-1" to "--stream-heat-7" of assets/css/module/_stream.scss.
 */
const LEVELS = 7;

/**
 * The columns of the calendar: the most weeks a year touches. Every year gets as many, so a day is as large in a
 * year of 53 weeks as in the current one, which ends today, and the card keeps its height.
 */
const WEEKS = 54;

const DAY_MS = 86_400_000;

const utcFormat = (options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat => new Intl.DateTimeFormat(
    'en-US',
    { ...options, timeZone: 'UTC' },
);
const monthName = utcFormat({ month: 'short' });
const monthAndYear = utcFormat({ month: 'long', year: 'numeric' });
const fullDay = utcFormat({ weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

interface Day {
    date: string;
    count: number;
    week: number;
    weekday: number;
}

/**
 * The activity per day of a year as a calendar: a column per week, Monday on top, the months above the week they
 * begin in. The weeks share the width, each day is a square. It shows when in the year people work. A day opens
 * the stream of that day.
 *
 * The days are those of the time zone of the reader, the API counted them so: they are read and formatted in UTC, so
 * nothing shifts. A screen reader reads the months of the table below it.
 */
@customElement('forger-activity-calendar')
export class ActivityCalendarElement extends LightElement {
    @property({ attribute: false }) calendar!: Calendar;
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'stream-url' }) streamUrl = '';
    /**
     * The months as a table in place of the calendar, see the head of "Over the year".
     */
    @property({ type: Boolean }) table = false;

    protected override render(): TemplateResult {
        const days = daysOf(this.calendar);
        const max = Math.max(0, ...this.calendar.counts);
        const total = this.calendar.counts.reduce((sum, count) => sum + count, 0);
        const active = this.calendar.counts.filter(count => count > 0).length;
        const busiest = days.reduce<Day | null>(
            (best, day) => best === null || day.count > best.count ? day : best,
            null,
        );

        return html`
            <p class="stream-calendar-summary">
                <strong>${formatNumber(total)}</strong> events on <strong>${formatNumber(active)}</strong> days
                ${busiest === null || busiest.count === 0 ? nothing : html`
                    · busiest ${fullDay.format(utc(busiest.date))} with <strong>${formatNumber(busiest.count)}</strong>
                `}
            </p>
            ${this.table ? this.monthTable(days) : this.calendarGrid(days, max)}
        `;
    }

    private calendarGrid(days: Day[], max: number): TemplateResult {
        return html`
            <div class="stream-calendar-scroll">
                <div class="stream-calendar" style="--weeks: ${WEEKS}" aria-hidden="true">
                    ${monthsOf(days).map(month => html`
                        <span class="stream-calendar-month" style="grid-column: ${month.week + 2}">${month.name}</span>
                    `)}
                    ${NAMED_WEEKDAYS.map(weekday => html`
                        <span class="stream-calendar-weekday" style="grid-row: ${weekday + 2}">
                            ${WEEKDAYS[weekday]}
                        </span>
                    `)}
                    ${days.map(day => this.cell(day, max))}
                </div>
            </div>
            <div class="stream-calendar-foot">
                <p class="stream-heatmap-legend" aria-hidden="true">
                    Fewer
                    ${Array.from({ length: LEVELS + 1 }, (_, step) => html`
                        <span class="stream-heatmap-swatch stream-heat-${step}"></span>
                    `)}
                    More
                </p>
            </div>
        `;
    }

    /**
     * On a phone the calendar scrolls: it starts at the end, the last weeks.
     */
    protected override updated(changed: PropertyValues<this>): void {
        if (changed.has('calendar')) {
            const scroll = this.querySelector('.stream-calendar-scroll');
            scroll?.scrollTo({ left: scroll.scrollWidth });
        }
    }

    private cell(day: Day, max: number): TemplateResult {
        // On the square root: a few busy days do not leave all others in the lightest step
        const level = day.count === 0 || max === 0
            ? 0
            : Math.max(1, Math.min(LEVELS, Math.ceil(Math.sqrt(day.count / max) * LEVELS)));
        const change = { view: 'stream', from: day.date, to: day.date };
        const href = withQuery(
            this.streamUrl,
            pageParameters({ ...this.state, from: day.date, to: day.date, view: 'stream' }),
        );

        return html`
            <a
                class="stream-calendar-day stream-heat-${level}"
                style="grid-row: ${day.weekday + 2}; grid-column: ${day.week + 2}"
                href=${href}
                tabindex="-1"
                data-tip="${fullDay.format(utc(day.date))}: ${formatNumber(day.count)}"
                @click=${(event: MouseEvent) => handledByPage(event, this, change)}
            ></a>
        `;
    }

    private monthTable(days: Day[]): TemplateResult {
        const months = new Map<string, number>();
        days.forEach(day => months.set(day.date.slice(0, 7), (months.get(day.date.slice(0, 7)) ?? 0) + day.count));

        return html`
            <div class="stream-table-scroll" tabindex="0">
                <table class="overview-data-table">
                    <thead><tr><th scope="col">Month</th><th scope="col">Events</th></tr></thead>
                    <tbody>
                        ${Array.from(months.entries()).reverse().map(([month, count]) => html`
                            <tr>
                                <th scope="row">${monthAndYear.format(utc(month + '-01'))}</th>
                                <td>${formatNumber(count)}</td>
                            </tr>
                        `)}
                    </tbody>
                </table>
            </div>
        `;
    }
}

function utc(date: string): Date {
    return new Date(date + 'T00:00:00Z');
}

/**
 * The days with their week, counted from the week of the first day, and their weekday, Monday first.
 */
function daysOf(calendar: Calendar): Day[] {
    const first = utc(calendar.firstDay);
    const offset = (first.getUTCDay() + 6) % 7;

    return calendar.counts.map((count, index) => {
        const date = new Date(first.getTime() + index * DAY_MS);

        return {
            date: date.toISOString().slice(0, 10),
            count,
            week: Math.floor((offset + index) / 7),
            weekday: (date.getUTCDay() + 6) % 7,
        };
    });
}

/**
 * The months above the week they begin in. A month that begins too close to the next has no room for its name.
 */
function monthsOf(days: Day[]): { name: string; week: number }[] {
    const last = days[days.length - 1]?.week ?? 0;
    const months = days
        .filter((day, index) => index === 0 || day.date.endsWith('-01'))
        .map(day => ({ name: monthName.format(utc(day.date)), week: day.week }));

    return months.filter((month, index) => {
        const next = months[index + 1]?.week ?? last + 3;

        return next - month.week >= 2;
    });
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-calendar': ActivityCalendarElement;
    }
}
