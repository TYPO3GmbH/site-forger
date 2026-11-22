import type { ReactiveController, ReactiveControllerHost } from 'lit';

/**
 * Loads the data of an element with the API: one request at a time. A new request aborts the one before, so an old
 * answer never replaces a newer one, and the element leaves no request behind when it leaves the page. The element
 * renders again when the data, the error or "loading" change.
 */
export class Loader<T> implements ReactiveController {
    data: T | null = null;
    error: string | null = null;
    loading = false;

    private request: AbortController | null = null;

    constructor(private readonly host: ReactiveControllerHost) {
        host.addController(this);
    }

    hostDisconnected(): void {
        this.request?.abort();
    }

    /**
     * Drops the data and the request that runs.
     */
    reset(): void {
        this.request?.abort();
        this.request = null;
        this.loading = false;
        this.data = null;
    }

    /**
     * Keeps the data it has when the request fails, and says why in "error".
     *
     * @returns false when a newer request took its place
     */
    async load(fetch: (signal: AbortSignal) => Promise<T>): Promise<boolean> {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        this.loading = true;
        this.host.requestUpdate();
        try {
            this.data = await fetch(request.signal);
            this.error = null;
        } catch (error) {
            if (!request.signal.aborted) {
                this.error = error instanceof Error ? error.message : String(error);
            }
        }
        if (this.request !== request) {
            return false;
        }
        this.request = null;
        this.loading = false;
        this.host.requestUpdate();

        return true;
    }
}
