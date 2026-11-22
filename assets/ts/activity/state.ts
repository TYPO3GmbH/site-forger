import type { Interval, Kind, Range, Source, Split } from './types';

/**
 * What the activity shows: the view and the filter. The address of the page and the parameters of the API have the
 * same names, see App\Api\ActivityFilters.
 */
export interface ActivityState {
    view: 'stream' | 'statistics';
    range: Range;
    from: string | null;
    to: string | null;
    source: Source | null;
    kinds: Kind[];
    branch: string | null;
    person: string | null;
    /**
     * The events of the people the logged-in user follows, in place of one person, see FollowController.
     */
    following: boolean;
    /**
     * The keys of the accounts of the board of one user, separated by commas, see
     * App\Controller\MyWorkController::statistics(). Set by the page, never by the address.
     */
    own: string | null;
    bots: boolean;
    split: Split;
    /**
     * The length of a bar of "Activity over time", null for the one by the length of the window.
     */
    timelineInterval: Interval | null;
    /**
     * The length of a bucket of "In and out", the same for the issues and the changes so they compare.
     */
    flowInterval: Interval | null;
    /**
     * The window of "When people are active", apart from the one of the page.
     */
    hours: Range;
    /**
     * The year of "Over the year", null for the last 12 months.
     */
    year: number | null;
}

export type ActivityChange = Partial<ActivityState>;

export const DEFAULT_RANGE: Range = '7d';
export const DEFAULT_HOURS: Range = '1y';

const RANGES: Range[] = ['7d', '30d', '6m', '1y', 'all'];
const SOURCES: Source[] = ['forge', 'gerrit'];
const KINDS: Kind[] = ['created', 'commented', 'changed', 'uploaded', 'voted', 'merged', 'resolved', 'closed'];
const SPLITS: Split[] = ['total', 'source', 'kind'];
const INTERVALS: Interval[] = ['hour', 'day', 'week', 'month'];

function oneOf<T extends string>(value: string | null, allowed: T[]): T | null {
    return allowed.includes(value as T) ? (value as T) : null;
}

export function defaultState(view: ActivityState['view'] = 'stream'): ActivityState {
    return {
        view,
        range: DEFAULT_RANGE,
        from: null,
        to: null,
        source: null,
        kinds: [],
        branch: null,
        person: null,
        following: false,
        own: null,
        bots: false,
        split: 'total',
        timelineInterval: null,
        flowInterval: null,
        hours: DEFAULT_HOURS,
        year: null,
    };
}

export function stateFromParameters(parameters: URLSearchParams, view: ActivityState['view']): ActivityState {
    const kinds = (parameters.get('kind') ?? '')
        .split(',')
        .filter((kind): kind is Kind => KINDS.includes(kind as Kind));
    // The statistics are one board of all the activity: only its time window changes, so its layout stays the same
    const filtered = view === 'stream';

    return {
        view,
        range: oneOf(parameters.get('range'), RANGES) ?? DEFAULT_RANGE,
        from: parameters.get('from') || null,
        to: parameters.get('to') || null,
        source: filtered ? oneOf(parameters.get('source'), SOURCES) : null,
        kinds: filtered ? Array.from(new Set(kinds)) : [],
        branch: filtered ? parameters.get('branch') || null : null,
        person: filtered ? parameters.get('person') || null : null,
        following: filtered && parameters.get('person') === null && parameters.get('following') === '1',
        own: null,
        bots: filtered && ['1', 'true'].includes(parameters.get('bots') ?? ''),
        split: oneOf(parameters.get('split'), SPLITS) ?? 'total',
        timelineInterval: oneOf(parameters.get('timeline'), INTERVALS),
        flowInterval: oneOf(parameters.get('flow'), INTERVALS),
        hours: oneOf(parameters.get('hours'), RANGES) ?? DEFAULT_HOURS,
        year: /^\d{4}$/.test(parameters.get('year') ?? '') ? Number(parameters.get('year')) : null,
    };
}

/**
 * A change of the time window replaces the window before: days or a range. A new window starts with the
 * intervals by its length.
 */
export function applyChange(state: ActivityState, change: ActivityChange): ActivityState {
    const next = { ...state, ...change };
    if ('range' in change || 'from' in change || 'to' in change) {
        next.timelineInterval = change.timelineInterval ?? null;
        next.flowInterval = change.flowInterval ?? null;
    }
    if ('range' in change) {
        next.from = null;
        next.to = null;
    }
    // One person or the people followed, not both
    if (change.person) {
        next.following = false;
    }
    if (change.following) {
        next.person = null;
    }

    return next;
}

/**
 * The parameters of the filter, without the defaults. The address of the page has them, the API too.
 */
export function filterParameters(state: ActivityState): URLSearchParams {
    const parameters = new URLSearchParams();
    // An end left out is open: from the first day of Forge, or up to now
    if (state.from !== null || state.to !== null) {
        if (state.from !== null) {
            parameters.set('from', state.from);
        }
        if (state.to !== null) {
            parameters.set('to', state.to);
        }
    } else if (state.range !== DEFAULT_RANGE) {
        parameters.set('range', state.range);
    }
    if (state.source !== null) {
        parameters.set('source', state.source);
    }
    if (state.kinds.length > 0) {
        parameters.set('kind', state.kinds.join(','));
    }
    if (state.branch !== null) {
        parameters.set('branch', state.branch);
    }
    if (state.person !== null) {
        parameters.set('person', state.person);
    }
    if (state.following) {
        parameters.set('following', '1');
    }
    if (state.bots) {
        parameters.set('bots', '1');
    }

    return parameters;
}

export function pageParameters(state: ActivityState): URLSearchParams {
    const parameters = filterParameters(state);
    // The board of one user has its person in the page, not in the address
    if (state.view === 'statistics' && state.own !== null) {
        parameters.delete('person');
    }
    if (state.view === 'statistics' && state.split !== 'total') {
        parameters.set('split', state.split);
    }
    if (state.view === 'statistics' && state.timelineInterval !== null) {
        parameters.set('timeline', state.timelineInterval);
    }
    if (state.view === 'statistics' && state.flowInterval !== null) {
        parameters.set('flow', state.flowInterval);
    }
    if (state.view === 'statistics' && state.hours !== DEFAULT_HOURS) {
        parameters.set('hours', state.hours);
    }
    if (state.view === 'statistics' && state.year !== null) {
        parameters.set('year', String(state.year));
    }

    return parameters;
}

export function withQuery(url: string, parameters: URLSearchParams): string {
    const query = parameters.toString();
    return query === '' ? url : url + (url.includes('?') ? '&' : '?') + query;
}
