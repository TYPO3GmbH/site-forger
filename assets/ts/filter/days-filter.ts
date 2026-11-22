import { html, type TemplateResult } from 'lit';
import { customElement, property, query } from 'lit/decorators.js';
import '../activity/days';
import type { PickedDays } from '../activity/days';
import { dayKey } from '../activity/format';
import { LightElement } from '../activity/light-element';

/**
 * The days of a filter of dates, like "Created" of the issues: the calendar of the activity, see
 * assets/ts/activity/days.ts. "Show" sends the form with the first day as <name>[from] and the last day as
 * <name>[to], see App\Report\MonthOverview::days(). An end left empty stays open.
 *
 * The calendar takes its icons and the first year from templates/stream/_definitions.html.twig.
 */
@customElement('forger-days-filter')
export class DaysFilter extends LightElement {
    /** The name of the filter, like "filters[created]" */
    @property() name = '';
    /** The days of the filter, as YYYY-MM-DD, empty for an open end */
    @property() from = '';
    @property() to = '';

    @query('input[data-end="from"]') private first!: HTMLInputElement;
    @query('input[data-end="to"]') private last!: HTMLInputElement;

    protected override render(): TemplateResult {
        return html`
            <input type="hidden" name="${this.name}[from]" data-end="from" .value=${this.from}>
            <input type="hidden" name="${this.name}[to]" data-end="to" .value=${this.to}>
            <forger-activity-days
                from=${this.from}
                to=${this.to}
                max=${dayKey(new Date())}
                @days-picked=${this.send}
            ></forger-activity-days>
        `;
    }

    // The form data is read right after requestSubmit(), before Lit renders again: so the values are set by hand
    private send(event: CustomEvent<PickedDays>): void {
        this.first.value = event.detail.from;
        this.last.value = event.detail.to;
        this.closest('form')?.requestSubmit();
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-days-filter': DaysFilter;
    }
}
