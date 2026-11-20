/**
 * Requests in the background, with the same error handling everywhere.
 *
 * A response counts only if its status is OK, it was not redirected and it has the expected type.
 * A redirect or an HTML page where JSON was expected means that the session ran out and the login page came back.
 */
export class RequestError extends Error {
    /**
     * @param {number} status
     * @param {?string} detail the message of the server, if it sent one as a JSON string
     */
    constructor(status, detail = null) {
        super(detail ?? 'The request failed with status ' + status + '.');
        this.name = 'RequestError';
        this.status = status;
        this.detail = detail;
    }
}

async function request(url, type, options) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: type === 'json' ? 'application/json' : 'text/html',
            ...options.headers,
        },
    });
    const contentType = response.headers.get('Content-Type') || '';
    if (!response.ok || response.redirected || !contentType.includes(type)) {
        const detail = contentType.includes('json') ? await response.json().catch(() => null) : null;
        throw new RequestError(response.status, typeof detail === 'string' ? detail : null);
    }

    return type === 'json' ? response.json() : response.text();
}

/**
 * @param {string|URL} url
 * @param {RequestInit} options
 * @returns {Promise<*>} the decoded JSON
 */
export function fetchJson(url, options = {}) {
    return request(url, 'json', options);
}

/**
 * @param {string|URL} url
 * @param {RequestInit} options
 * @returns {Promise<string>} the HTML
 */
export function fetchHtml(url, options = {}) {
    return request(url, 'html', options);
}
