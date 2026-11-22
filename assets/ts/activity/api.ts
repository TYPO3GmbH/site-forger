import { filterParameters, withQuery, type ActivityState } from './state';
import type {
    ActivityCalendar,
    ActivityClosedYears,
    ActivityNewcomers,
    ActivityPage,
    ActivityStatistics,
    ActivityWeekHours,
    Interval,
    Split,
} from './types';

/**
 * The time zone of the browser: the API counts the days and hours in it.
 */
export function timeZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const message = data && typeof data.error === 'string' ? data.error : 'The request failed with status '
            + response.status + '.';
        throw new Error(message);
    }

    return data as T;
}

function parameters(state: ActivityState): URLSearchParams {
    const parameters = filterParameters(state);
    // The API knows no user: the page sends the people followed as "people", see ActivityStream
    parameters.delete('following');
    parameters.set('timeZone', timeZone());

    return parameters;
}

export function fetchPage(
    apiUrl: string,
    state: ActivityState,
    limit: number,
    offset: number,
    signal?: AbortSignal,
    people = '',
): Promise<ActivityPage> {
    const query = parameters(state);
    query.set('limit', String(limit));
    query.set('offset', String(offset));
    if (people !== '') {
        query.set('people', people);
    }

    return getJson<ActivityPage>(withQuery(apiUrl, query), signal);
}

/**
 * The statistics of the filter. A chart of its own asks with its split or its interval, see StatisticsCard.
 */
export function fetchStatistics(
    apiUrl: string,
    state: ActivityState,
    split: Split = 'total',
    interval: Interval | null = null,
    signal?: AbortSignal,
): Promise<ActivityStatistics> {
    const query = parameters(state);
    if (split !== 'total') {
        query.set('split', split);
    }
    if (interval !== null) {
        query.set('interval', interval);
    }

    return getJson<ActivityStatistics>(withQuery(apiUrl, query), signal);
}

/**
 * The people who pushed their first change in the window of the page.
 */
export function fetchNewcomers(apiUrl: string, state: ActivityState, signal?: AbortSignal): Promise<ActivityNewcomers> {
    return getJson<ActivityNewcomers>(withQuery(apiUrl, parameters(state)), signal);
}

/**
 * The events per weekday and hour of the filter, in the window "hours" of the state.
 */
export function fetchWeekHours(apiUrl: string, state: ActivityState, signal?: AbortSignal): Promise<ActivityWeekHours> {
    const query = parameters({ ...state, range: state.hours, from: null, to: null });

    return getJson<ActivityWeekHours>(withQuery(apiUrl, query), signal);
}

/**
 * The events per day of the year "year" of the state, of the last 12 months without one.
 */
export function fetchDays(apiUrl: string, state: ActivityState, signal?: AbortSignal): Promise<ActivityCalendar> {
    const query = parameters({ ...state, from: null, to: null });
    query.delete('range');
    if (state.year !== null) {
        query.set('year', String(state.year));
    }

    return getJson<ActivityCalendar>(withQuery(apiUrl, query), signal);
}

/**
 * The issues and changes closed per year. It takes no filter.
 */
export function fetchClosedYears(apiUrl: string, signal?: AbortSignal): Promise<ActivityClosedYears> {
    return getJson<ActivityClosedYears>(apiUrl, signal);
}
