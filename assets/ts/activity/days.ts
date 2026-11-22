import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { firstYear, icon } from './definitions';
import { dayKey } from './format';
import { LightElement } from './light-element';

/**
 * What the event "days-picked" of a picked window carries: its first and its last day as YYYY-MM-DD, empty for
 * an end left open.
 */
export interface PickedDays {
    from: string;
    to: string;
}

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

/**
 * Six weeks, whatever the month: the calendar keeps its height.
 */
const DAYS = 42;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const monthName = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' });
const shortDay = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
const fullDay = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

/**
 * A calendar to pick the days of a window: a month, Monday first. Above it the first and the last day: a day
 * clicked sets the one of them that is active, so a reader moves one end alone or both. After the first day the
 * last one is active. An end may stay empty: the window then starts with the first day of Forge, or ends now.
 * "Today" sets the active end to today, "Whole month" and "Whole year" both ends. The window shows only when the
 * reader says so, with the button.
 *
 * While the pointer moves, the window so far shows. The arrow keys move through the days, "Page up" and "Page
 * down" through the months. The name of the month opens the months of its year, the year there opens the years: a
 * far day takes three clicks. See ".stream-daypicker" in assets/css/module/_stream.scss.
 */
@customElement('forger-activity-days')
export class ActivityDays extends LightElement {
    /**
     * The window picked, as YYYY-MM-DD, empty for an end left open.
     */
    @property() from = '';
    @property() to = '';
    /**
     * The last day to pick.
     */
    @property() max = '';

    /**
     * The first day of the month shown.
     */
    @state() private month = new Date();
    /**
     * What the calendar shows: the days of a month, the months of a year to pick one, or the years.
     */
    @state() private view: 'days' | 'months' | 'years' = 'days';
    /**
     * The window the reader builds, as YYYY-MM-DD: it starts as the window picked.
     */
    @state() private first = '';
    @state() private last = '';
    /**
     * The end the next day clicked sets.
     */
    @state() private end: 'first' | 'last' = 'first';
    @state() private hover: string | null = null;
    /**
     * The day the keys start from: the only one in the order of the tab key.
     */
    @state() private cursor = '';

    private focusCursor = false;

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (changed.has('from') || changed.has('to') || changed.has('max')) {
            this.first = this.from;
            this.last = this.to;
            this.end = 'first';
            this.hover = null;
            this.view = 'days';
            this.show(this.first || this.last || this.today());
        }
    }

    protected override updated(): void {
        if (this.focusCursor) {
            this.focusCursor = false;
            this.querySelector<HTMLElement>(`[data-day="${this.cursor}"]`)?.focus();
        }
    }

    protected override render(): TemplateResult {
        const year = this.month.getFullYear();
        const months = this.view === 'days' ? 1 : 12;
        const title = this.view === 'days' ? monthName.format(this.month) : String(year);
        const lastYear = Number((this.max || dayKey(new Date())).slice(0, 4));
        // No step before the first year of Forge or past the last day
        const first = this.view === 'days' ? dayKey(this.month) <= this.min() : year <= firstYear();
        const last = this.view === 'days'
            ? this.max !== '' && dayKey(this.following(1)) > this.max
            : year >= lastYear;

        return html`
            <div class="stream-daypicker" @keydown=${this.onKey} @pointerleave=${() => { this.hover = null; }}>
                <div class="stream-daypicker-ends" role="group" aria-label="The day the calendar sets">
                    ${this.endField('first', 'From', this.first, 'The first day')}
                    ${this.endField('last', 'To', this.last, 'Now')}
                </div>
                <div class="stream-daypicker-head">
                    <button
                        type="button"
                        class="stream-daypicker-turn"
                        title=${this.view === 'days' ? 'The month before' : 'The year before'}
                        ?disabled=${this.view === 'years' || first}
                        @click=${() => this.turn(-months)}
                    >${icon('actions-chevron-start')}</button>
                    <button
                        type="button"
                        class="stream-daypicker-title"
                        title=${this.view === 'days' ? 'Pick a month' : 'Pick a year'}
                        ?disabled=${this.view === 'years'}
                        aria-live="polite"
                        @click=${() => { this.view = this.view === 'days' ? 'months' : 'years'; }}
                    >${this.view === 'years' ? 'Pick a year' : html`${title} ${icon('actions-chevron-down')}`}</button>
                    <button
                        type="button"
                        class="stream-daypicker-turn"
                        title=${this.view === 'days' ? 'The next month' : 'The next year'}
                        ?disabled=${this.view === 'years' || last}
                        @click=${() => this.turn(months)}
                    >${icon('actions-chevron-end')}</button>
                </div>
                ${this.view === 'days' ? this.days() : this.view === 'months' ? this.months() : this.years()}
                <div class="stream-daypicker-foot">
                    <button type="button" class="stream-daypicker-quick" @click=${() => this.pick(this.today())}>
                        Today
                    </button>
                    <button
                        type="button"
                        class="stream-daypicker-quick"
                        title="From the first to the last day of ${title}"
                        ?disabled=${this.view === 'years'}
                        @click=${this.pickAll}
                    >${this.view === 'days' ? 'Whole month' : 'Whole year'}</button>
                    <button type="button" class="stream-daypicker-submit" @click=${this.submit}>Show</button>
                </div>
            </div>
        `;
    }

    /**
     * An end of the window: a click makes it the one the calendar sets, and shows its month. The cross empties it.
     */
    private endField(end: 'first' | 'last', label: string, key: string, open: string): TemplateResult {
        const other = end === 'first' ? this.last : this.first;

        return html`
            <div class="stream-daypicker-end${this.end === end ? ' is-active' : ''}">
                <button
                    type="button"
                    aria-pressed=${this.end === end ? 'true' : 'false'}
                    @click=${() => {
                        this.end = end;
                        this.view = 'days';
                        this.show(key || other || this.today());
                    }}
                >
                    <span>${label}</span>
                    ${key === '' ? html`<em>${open}</em>` : shortDay.format(dateOf(key))}
                </button>
                ${key === '' ? nothing : html`
                    <button
                        type="button"
                        class="stream-daypicker-clear"
                        title="Empty: ${open.toLowerCase()}"
                        @click=${() => this.clear(end)}
                    >${icon('actions-close')}</button>
                `}
            </div>
        `;
    }

    private clear(end: 'first' | 'last'): void {
        if (end === 'first') {
            this.first = '';
        } else {
            this.last = '';
        }
        this.end = end;
        this.hover = null;
    }

    /**
     * The first day to pick: the first of the first year with activity.
     */
    private min(): string {
        return `${firstYear()}-01-01`;
    }

    private today(): string {
        return this.max || dayKey(new Date());
    }

    private days(): TemplateResult {
        const start = new Date(this.month.getFullYear(), this.month.getMonth(), 1);
        // Back to the Monday of the week the month starts in
        start.setDate(1 - ((start.getDay() + 6) % 7));
        const days = Array.from({ length: DAYS }, (_, index) => {
            return new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
        });

        return html`
            <div class="stream-daypicker-grid" role="group" aria-label="Days of ${monthName.format(this.month)}">
                ${WEEKDAYS.map(name => html`<span class="stream-daypicker-weekday" aria-hidden="true">${name}</span>`)}
                ${days.map(day => this.day(day))}
            </div>
        `;
    }

    private months(): TemplateResult {
        const year = this.month.getFullYear();

        return html`
            <div class="stream-daypicker-choices" role="group" aria-label="Months of ${year}">
                ${MONTHS.map((name, month) => html`
                    <button
                        type="button"
                        class=${month === this.month.getMonth() ? 'is-shown' : ''}
                        ?disabled=${this.max !== '' && dayKey(new Date(year, month, 1)) > this.max}
                        @click=${() => this.open(year, month)}
                    >${name}</button>
                `)}
            </div>
        `;
    }

    private years(): TemplateResult {
        const last = Number((this.max || dayKey(new Date())).slice(0, 4));
        const years = Array.from({ length: last - firstYear() + 1 }, (_, index) => last - index);

        return html`
            <div class="stream-daypicker-choices is-years" role="group" aria-label="Years">
                ${years.map(year => html`
                    <button
                        type="button"
                        class=${year === this.month.getFullYear() ? 'is-shown' : ''}
                        @click=${() => this.open(year, null)}
                    >${year}</button>
                `)}
            </div>
        `;
    }

    /**
     * A year picked opens its months, a month picked its days.
     */
    private open(year: number, month: number | null): void {
        this.month = new Date(year, month ?? this.month.getMonth(), 1);
        // The keys go on from the first day of the month shown
        this.cursor = dayKey(this.month);
        this.view = month === null ? 'months' : 'days';
    }

    private day(date: Date): TemplateResult {
        const key = dayKey(date);
        const [from, to] = this.shown();
        // An open end reaches as far as the calendar goes
        const between = (from !== '' || to !== '') && key > from && (to === '' || key < to);
        const classes = [
            'stream-daypicker-day',
            date.getMonth() === this.month.getMonth() ? '' : 'is-outside',
            key === from || key === to ? 'is-end' : '',
            between ? 'is-between' : '',
            key === dayKey(new Date()) ? 'is-today' : '',
        ];

        return html`
            <button
                type="button"
                class=${classes.filter(name => name !== '').join(' ')}
                data-day=${key}
                tabindex=${key === this.cursor ? 0 : -1}
                aria-label=${fullDay.format(date)}
                aria-pressed=${key === from || key === to ? 'true' : 'false'}
                ?disabled=${key < this.min() || (this.max !== '' && key > this.max)}
                @click=${() => this.pick(key)}
                @pointerenter=${() => { this.hover = key; }}
            >${date.getDate()}</button>
        `;
    }

    /**
     * The window the calendar marks: the one the reader builds, with the day under the pointer as the active end.
     */
    private shown(): [string, string] {
        return this.hover === null ? [this.first, this.last] : this.with(this.hover);
    }

    /**
     * The window with a day as its active end. A first day after the last one, or a last day before the first one,
     * is both ends: the window is that day.
     */
    private with(key: string): [string, string] {
        if (this.end === 'first') {
            return [key, this.last !== '' && key > this.last ? key : this.last];
        }

        return [this.first !== '' && key < this.first ? key : this.first, key];
    }

    private pick(key: string): void {
        [this.first, this.last] = this.with(key);
        this.show(key);
        this.view = 'days';
        this.hover = null;
        // After the first day comes the last one
        this.end = 'last';
    }

    /**
     * The whole month shown, or its whole year where the months show: both ends at once. Not past the last day.
     */
    private pickAll(): void {
        const year = this.month.getFullYear();
        const [from, to] = this.view === 'days'
            ? [this.month, new Date(year, this.month.getMonth() + 1, 0)]
            : [new Date(year, 0, 1), new Date(year, 11, 31)];
        const last = dayKey(to);
        this.first = dayKey(from);
        this.last = this.max !== '' && last > this.max ? this.max : last;
        this.end = 'last';
        this.hover = null;
    }

    private submit(): void {
        const detail: PickedDays = { from: this.first, to: this.last };
        this.dispatchEvent(new CustomEvent<PickedDays>('days-picked', { detail, bubbles: true }));
    }

    /**
     * The first day of a month before or after the one shown.
     */
    private following(months: number): Date {
        return new Date(this.month.getFullYear(), this.month.getMonth() + months, 1);
    }

    private turn(months: number): void {
        this.month = this.following(months);
    }

    /**
     * Shows the month of a day and puts the keys on it.
     */
    private show(key: string): void {
        const day = dateOf(key);
        this.month = new Date(day.getFullYear(), day.getMonth(), 1);
        this.cursor = key;
    }

    private onKey(event: KeyboardEvent): void {
        const steps: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        const months: Record<string, number> = { PageUp: -1, PageDown: 1 };
        if (this.view !== 'days' || (!(event.key in steps) && !(event.key in months))) {
            return;
        }
        event.preventDefault();
        const from = dateOf(this.cursor);
        const next = new Date(
            from.getFullYear(),
            from.getMonth() + (months[event.key] ?? 0),
            from.getDate() + (steps[event.key] ?? 0),
        );
        const key = dayKey(next);
        this.show(this.max !== '' && key > this.max ? this.max : key);
        this.hover = this.cursor;
        this.focusCursor = true;
    }
}

/**
 * The day of a key YYYY-MM-DD in the time zone of the browser.
 */
function dateOf(key: string): Date {
    const [year, month, day] = key.split('-').map(Number);

    return new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1);
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-days': ActivityDays;
    }
}
