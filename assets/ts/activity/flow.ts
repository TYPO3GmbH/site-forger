import { html, nothing, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { StatisticsCard } from './card';
import { icon } from './definitions';
import { bucketDays, bucketLabel, bucketTick, formatNumber, isSoFar, soFarTip } from './format';
import { handledByPage } from './light-element';
import { pageParameters, withQuery, type ActivityChange } from './state';
import { withTooltips } from './tooltip';
import type { ActivityFigure, Interval, Source } from './types';

withTooltips();

/**
 * About six names along the axis, so they do not collide on a phone.
 */
const TICKS = 6;

const TITLES: Record<Source, string> = {
    forge: 'Issues in and out',
    gerrit: 'Changes in and out',
};

interface Flow {
    incoming: ActivityFigure[];
    outgoing: ActivityFigure[];
    ins: number[];
    outs: number[];
}

/**
 * What comes in and what goes out of a channel: the issues reported against the ones resolved or closed, the
 * changes pushed against the ones merged or abandoned. On top the sums of the window, below them the net change per
 * bucket: up in red when more came in, down in green when more went out. The API says which figure counts which
 * way, see App\Enum\StreamFigureEnum::openDelta(). A bar opens the stream of its time.
 */
@customElement('forger-activity-flow')
export class ActivityFlow extends StatisticsCard {
    @property() source: Source = 'forge';

    protected override interval(): Interval | null {
        return this.state.flowInterval;
    }

    protected override intervalChange(interval: Interval | null): ActivityChange {
        return { flowInterval: interval };
    }

    protected override render(): TemplateResult {
        const flow = this.flow();
        const totalIn = flow.incoming.reduce((total, figure) => total + figure.count, 0);
        const totalOut = flow.outgoing.reduce((total, figure) => total + figure.count, 0);

        return html`
            <section class="stream-chart" aria-label=${TITLES[this.source]} aria-busy=${this.busy()}>
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">${TITLES[this.source]}</h3>
                        <p class="stream-chart-note">
                            Per ${this.data().window.interval}: up when more came in, down when more went out
                        </p>
                    </div>
                    <div class="stream-chart-controls">
                        ${this.intervalSwitch()}
                        ${this.tableSwitch(TITLES[this.source])}
                    </div>
                </header>
                <dl class="stream-flow-totals">
                    ${this.total('In', totalIn, flow.incoming)}
                    ${this.total('Out', totalOut, flow.outgoing)}
                    <div>
                        <dt>Net</dt>
                        <dd>${netBadge(totalIn - totalOut)}</dd>
                    </div>
                </dl>
                ${this.showTable ? this.table(flow) : this.net(flow)}
            </section>
        `;
    }

    /**
     * Of a person, what the person did. Else all that opened and closed, the tools and the issues opened again
     * included, so the net is what the open ones changed by. What the figures do not count shows as a part of its own.
     */
    private flow(): Flow {
        const { buckets, figures, flows, person } = this.data();
        const ofSource = figures.filter(figure => figure.source === this.source);
        let incoming = ofSource.filter(figure => figure.openDelta === 1);
        let outgoing = ofSource.filter(figure => figure.openDelta === -1);
        const stock = person === null ? flows.find(flow => flow.source === this.source) : undefined;
        if (stock !== undefined) {
            incoming = withRest(incoming, stock.incoming, 'Opened again or by tools');
            outgoing = withRest(outgoing, stock.outgoing, 'Closed by tools');
        }

        return {
            incoming,
            outgoing,
            ins: buckets.map((_, bucket) => sum(incoming, bucket)),
            outs: buckets.map((_, bucket) => sum(outgoing, bucket)),
        };
    }

    private total(label: string, count: number, figures: ActivityFigure[]): TemplateResult {
        const parts = figures.map(figure => `${figure.label} ${formatNumber(figure.count)}`).join(', ');

        return html`
            <div title=${parts}>
                <dt>${label}</dt>
                <dd>${formatNumber(count)}</dd>
            </div>
        `;
    }

    /**
     * The net change per bucket: up in red when more came in, down in green when more went out.
     */
    private net(flow: Flow): TemplateResult {
        const { buckets, window } = this.data();
        const nets = buckets.map((_, bucket) => (flow.ins[bucket] ?? 0) - (flow.outs[bucket] ?? 0));
        const up = Math.max(0, ...nets);
        const down = Math.max(0, ...nets.map(net => -net));
        // The zero line sits where the data puts it: a window that only went down leaves no room above it
        const shares = up + down === 0 ? '--net-up: 1; --net-down: 1' : `--net-up: ${up}; --net-down: ${down}`;

        return html`
            <div class="stream-net-frame" style=${shares}>
                <div class="stream-net-axis" aria-hidden="true">
                    <span class="stream-net-up">${up > 0 ? `+${formatNumber(up)}` : ''}</span>
                    <span class="stream-net-down">${down > 0 ? `−${formatNumber(down)}` : ''}</span>
                </div>
                <div class="stream-net-bars" aria-hidden="true">
                    ${buckets.map((key, bucket) => {
                        const net = nets[bucket] ?? 0;
                        const size = `${(Math.abs(net) / (net > 0 ? up : down || 1)) * 100}%`;
                        const soFar = isSoFar(window, bucket, buckets.length);

                        return html`
                            <a
                                class="stream-net-bar ${soFar ? 'is-so-far' : ''}"
                                href=${this.dayUrl(key)}
                                data-tip=${soFarTip(this.tip(key, bucket, flow), soFar)}
                                tabindex="-1"
                                @click=${(event: MouseEvent) => handledByPage(event, this, this.dayChange(key))}
                            >
                                <span class="stream-net-up">
                                    ${net > 0 ? html`<span class="is-worse" style="height: ${size}"></span>` : nothing}
                                </span>
                                <span class="stream-net-down">
                                    ${net < 0 ? html`<span class="is-better" style="height: ${size}"></span>` : nothing}
                                </span>
                                ${this.tick(key, bucket, window.interval)}
                            </a>
                        `;
                    })}
                </div>
            </div>
        `;
    }

    private tick(key: string, bucket: number, interval: Interval): TemplateResult | typeof nothing {
        const step = Math.max(1, Math.ceil(this.data().buckets.length / TICKS));

        return bucket % step === 0
            ? html`<span class="stream-bar-tick">${bucketTick(key, interval)}</span>`
            : nothing;
    }

    private table(flow: Flow): TemplateResult {
        const { buckets, window } = this.data();
        const figures = [...flow.incoming, ...flow.outgoing];

        return html`
            <div class="stream-table-scroll" tabindex="0">
                <table class="overview-data-table">
                    <thead>
                        <tr>
                            <th scope="col">${window.interval[0]?.toUpperCase()}${window.interval.slice(1)}</th>
                            ${figures.map(figure => html`<th scope="col">${figure.label}</th>`)}
                            <th scope="col">In</th>
                            <th scope="col">Out</th>
                            <th scope="col">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${[...buckets.keys()].reverse().map(bucket => {
                            const net = (flow.ins[bucket] ?? 0) - (flow.outs[bucket] ?? 0);

                            return html`
                                <tr>
                                    <th scope="row">${bucketLabel(buckets[bucket] ?? '', window.interval)}</th>
                                    ${figures.map(figure => html`
                                        <td>${formatNumber(figure.values[bucket] ?? 0)}</td>
                                    `)}
                                    <td>${formatNumber(flow.ins[bucket] ?? 0)}</td>
                                    <td>${formatNumber(flow.outs[bucket] ?? 0)}</td>
                                    <td>${signed(net)}</td>
                                </tr>
                            `;
                        })}
                    </tbody>
                </table>
            </div>
        `;
    }

    private tip(key: string, bucket: number, flow: Flow): string {
        const parts = (figures: ActivityFigure[]): string => figures
            .map(figure => `${figure.label} ${formatNumber(figure.values[bucket] ?? 0)}`)
            .join(', ');
        const net = (flow.ins[bucket] ?? 0) - (flow.outs[bucket] ?? 0);

        return [
            bucketLabel(key, this.data().window.interval),
            `In ${formatNumber(flow.ins[bucket] ?? 0)}: ${parts(flow.incoming)}`,
            `Out ${formatNumber(flow.outs[bucket] ?? 0)}: ${parts(flow.outgoing)}`,
            `Net ${signed(net)}`,
        ].join('\n');
    }

    private dayChange(key: string): ActivityChange {
        return { ...bucketDays(key, this.data().window.interval), source: this.source, view: 'stream' };
    }

    private dayUrl(key: string): string {
        return withQuery(this.streamUrl, pageParameters({ ...this.state, ...this.dayChange(key) }));
    }
}

/**
 * The figures and, as a figure of its own, what the totals count beyond them.
 */
function withRest(figures: ActivityFigure[], totals: number[], label: string): ActivityFigure[] {
    const first = figures[0];
    const values = totals.map((total, bucket) => Math.max(0, total - sum(figures, bucket)));
    const count = values.reduce((all, value) => all + value, 0);
    if (first === undefined || count === 0) {
        return figures;
    }

    return [...figures, { ...first, key: `${first.key}-rest`, label, description: label, count, values }];
}

function sum(figures: ActivityFigure[], bucket: number): number {
    return figures.reduce((total, figure) => total + (figure.values[bucket] ?? 0), 0);
}

function signed(value: number): string {
    return value > 0 ? `+${formatNumber(value)}` : value < 0 ? `−${formatNumber(-value)}` : '0';
}

/**
 * The net change of the window: fewer open ones are better, more are worse.
 */
function netBadge(net: number): TemplateResult {
    const tone = net < 0 ? 'better' : net > 0 ? 'worse' : 'neutral';
    const direction = net > 0 ? 'actions-arrow-up' : net < 0 ? 'actions-arrow-down' : 'actions-minus';
    const title = net < 0 ? `${formatNumber(-net)} fewer open` : net > 0 ? `${formatNumber(net)} more open` : 'As many';

    return html`<span class="stream-figure-delta is-${tone}" title=${title}>${icon(direction)} ${signed(net)}</span>`;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-flow': ActivityFlow;
    }
}
