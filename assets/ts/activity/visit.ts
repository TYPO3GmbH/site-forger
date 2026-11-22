/**
 * The visit of the activity before this one, kept in the browser: the stream marks what came since and offers it as
 * a time window. It stays the same for the whole session, so a reload does not move it.
 */
const LAST_VISIT_KEY = 'forger.activity.lastVisit';
const PREVIOUS_VISIT_KEY = 'forger.activity.previousVisit';

let previous: string | null | undefined;

export function previousVisit(): string | null {
    if (previous !== undefined) {
        return previous;
    }
    try {
        let visit = window.sessionStorage.getItem(PREVIOUS_VISIT_KEY);
        if (visit === null) {
            visit = window.localStorage.getItem(LAST_VISIT_KEY) ?? '';
            window.sessionStorage.setItem(PREVIOUS_VISIT_KEY, visit);
        }
        window.localStorage.setItem(LAST_VISIT_KEY, new Date().toISOString());
        previous = visit === '' ? null : visit;
    } catch {
        previous = null;
    }

    return previous;
}
