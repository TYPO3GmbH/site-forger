import { html, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { formatNumber } from './format';
import { LightElement } from './light-element';
import { withTooltips } from './tooltip';

withTooltips();

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

/**
 * The steps of the ramp above "none": the custom properties "--stream-heat-1" to "--stream-heat-7", see
 * assets/css/module/_stream.scss.
 */
const LEVELS = 7;

/**
 * The events per weekday and hour in the time zone of the reader: when people are active. A table, so a screen
 * reader reads the numbers; the colors show them at a glance.
 */
@customElement('forger-activity-heatmap')
export class ActivityHeatmap extends LightElement {
    @property({ attribute: false }) weekHours: number[][] = [];
    @property({ attribute: 'time-zone' }) timeZone = 'UTC';

    protected override render(): TemplateResult {
        const max = Math.max(0, ...this.weekHours.flat());
        const level = (count: number): number => count === 0 || max === 0
            ? 0
            : Math.max(1, Math.min(LEVELS, Math.ceil((count / max) * LEVELS)));

        return html`
            <div class="stream-table-scroll">
                <table class="stream-heatmap">
                    <caption class="sr-only">Events per weekday and hour in ${this.timeZone}</caption>
                    <thead>
                        <tr>
                            <td></td>
                            ${HOURS.map(hour => html`
                                <th scope="col"><span class=${hour % 6 === 0 ? '' : 'sr-only'}>${hour}</span></th>
                            `)}
                        </tr>
                    </thead>
                    <tbody>
                        ${WEEKDAYS.map((name, weekday) => html`
                            <tr>
                                <th scope="row">${name}</th>
                                ${HOURS.map(hour => {
                                    const count = this.weekHours[weekday]?.[hour] ?? 0;
                                    return html`
                                        <td
                                            class="stream-heat-${level(count)}"
                                            data-tip="${name} ${hour}:00–${hour + 1}:00: ${formatNumber(count)}"
                                        ><span class="sr-only">${count}</span></td>
                                    `;
                                })}
                            </tr>
                        `)}
                    </tbody>
                </table>
            </div>
            <p class="stream-heatmap-legend" aria-hidden="true">
                Fewer
                ${Array.from({ length: LEVELS + 1 }, (_, step) => html`
                    <span class="stream-heatmap-swatch stream-heat-${step}"></span>
                `)}
                More
            </p>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-heatmap': ActivityHeatmap;
    }
}
