/**
 * The objects of the activity endpoints of the API, see App\Api\Model\Activity*.
 */

export type Source = 'forge' | 'gerrit';
export type Kind = 'created' | 'commented' | 'changed' | 'uploaded' | 'voted' | 'merged' | 'resolved' | 'closed';
export type Range = '7d' | '30d' | '6m' | '1y';
export type Split = 'total' | 'source' | 'kind';
export type Interval = 'hour' | 'day' | 'week' | 'month';
/**
 * Which way a number of the statistics gets better, see App\Enum\StreamBetterEnum.
 */
export type Better = 'more' | 'less' | 'neither';

export interface ActivityPerson {
    name: string;
    forgeId: number | null;
    gerritId: number | null;
    key: string;
    avatar: string;
}

export interface ActivityEvent {
    id: string;
    source: Source;
    kind: Kind;
    number: number;
    title: string;
    date: string | null;
    author: ActivityPerson | null;
    bot: boolean;
    summary: string[];
    patchSet: number | null;
    branch: string | null;
    url: string;
    forgerUrl: string;
}

export interface ActivityWindow {
    from: string;
    until: string;
    open: boolean;
    days: number;
    timeZone: string;
    interval: Interval;
    intervals: Interval[];
}

export interface ActivityCount {
    key: string;
    label: string;
    count: number;
}

export interface ActivitySeries {
    key: string;
    label: string;
    values: number[];
}

export interface ActivityPage {
    total: number;
    offset: number;
    limit: number;
    window: ActivityWindow;
    person: ActivityPerson | null;
    branches: ActivityCount[];
    items: ActivityEvent[];
}

export interface ActivityFigure {
    key: string;
    label: string;
    description: string;
    count: number;
    previous: number;
    values: number[];
    source: Source;
    kinds: Kind[];
    better: Better;
    openDelta: -1 | 0 | 1;
}

export interface ActivityTrend {
    count: number;
    previous: number;
    values: number[];
    better: Better;
}

export interface ActivityDuration {
    key: string;
    label: string;
    description: string;
    count: number;
    median: number | null;
    previous: number | null;
    bands: ActivityWaitBand[];
    source: Source;
    better: Better;
}

/**
 * How many waits took as long as the band, see App\Enum\StreamWaitBandEnum.
 */
export interface ActivityWaitBand {
    key: string;
    label: string;
    count: number;
    kinds: ActivityCount[];
}

export interface ActivityCrew {
    key: string;
    label: string;
    description: string;
    people: number;
    carriers: number;
    previousCarriers: number;
    carrierShares: number[];
    better: Better;
}

/**
 * A first of somebody, see App\Enum\StreamMilestoneEnum.
 */
export type Milestone = 'first-issue' | 'first-change' | 'first-merge';

export interface ActivityNewcomer {
    person: ActivityPerson;
    milestone: Milestone;
    source: Source;
    number: number;
    title: string;
    date: string;
    branch: string | null;
    state: string;
    stateLabel: string;
    needsHelp: boolean;
    url: string;
    forgerUrl: string;
    mergedBy: ActivityPerson | null;
}

export interface ActivityNewcomers {
    window: ActivityWindow;
    count: number;
    previous: number;
    better: Better;
    milestones: ActivityCount[];
    people: ActivityNewcomer[];
}

export interface ActivityCalendar {
    window: ActivityWindow;
    firstDay: string;
    counts: number[];
}

export interface ActivityWeekHours {
    window: ActivityWindow;
    weekHours: number[][];
}

/**
 * What closed in a year up to the end of each month, null for the months to come.
 */
export interface ActivityClosedYear {
    year: number;
    closed: (number | null)[];
}

export interface ActivityClosedYears {
    issues: ActivityClosedYear[];
    changes: ActivityClosedYear[];
}

export interface ActivityStatistics {
    window: ActivityWindow;
    person: ActivityPerson | null;
    events: number;
    people: ActivityTrend;
    activeDays: ActivityTrend;
    figures: ActivityFigure[];
    crews: ActivityCrew[];
    openIssues: ActivityTrend;
    openChanges: ActivityTrend;
    durations: ActivityDuration[];
    reviewQueue: ActivityCount[];
    sources: ActivityCount[];
    kinds: ActivityCount[];
    buckets: string[];
    series: ActivitySeries[];
}
