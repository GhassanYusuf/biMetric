// The single HTTP client for the SPA. It speaks the standard API envelope
// ({ success, message, data, meta, errors }), attaches the Sanctum bearer
// token, and normalises failures into a thrown ApiError the UI can inspect.

const config = window.__APP_CONFIG__ || { apiBase: '/api' };
const TOKEN_KEY = 'payroll.token';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
}

export class ApiError extends Error {
    constructor(message, { status, errors } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors || {};
    }
}

async function request(method, path, { body, headers } = {}) {
    const opts = {
        method,
        headers: {
            Accept: 'application/json',
            ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...headers,
        },
    };

    const token = getToken();
    if (token) opts.headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) opts.body = body instanceof FormData ? body : JSON.stringify(body);

    const res = await fetch(`${config.apiBase}${path}`, opts);

    // 204/empty bodies are valid successes.
    let payload = null;
    try {
        payload = await res.json();
    } catch {
        payload = null;
    }

    if (!res.ok || (payload && payload.success === false)) {
        // A 401 anywhere means the session is gone — drop the token so the
        // AuthProvider can bounce the user to login.
        if (res.status === 401) setToken(null);
        throw new ApiError(payload?.message || `Request failed (${res.status})`, {
            status: res.status,
            errors: payload?.errors,
        });
    }

    // Return the whole envelope so callers can read meta (pagination, counts).
    return payload ?? { success: true, data: null, meta: {} };
}

/*
| A file download that carries the bearer token.
|
| A plain <a href> cannot: the API authenticates with a header, not a cookie, so
| the browser's own navigation would arrive unauthenticated and bounce to login
| with no explanation. Fetching the bytes and handing them to an object URL keeps
| the one auth path the rest of the client uses.
|
| Failures come back as the JSON envelope rather than a file, so they are
| unwrapped into the same ApiError every other call throws.
*/
async function download(path, fallbackName) {
    const token = getToken();

    const res = await fetch(`${config.apiBase}${path}`, {
        headers: {
            Accept: 'application/pdf',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (!res.ok) {
        if (res.status === 401) setToken(null);

        let payload = null;
        try {
            payload = await res.json();
        } catch {
            payload = null;
        }

        throw new ApiError(payload?.message || `Could not produce the file (${res.status}).`, {
            status: res.status,
            errors: payload?.errors,
        });
    }

    const name = filenameFrom(res.headers.get('Content-Disposition')) || fallbackName;
    const url = URL.createObjectURL(await res.blob());

    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Revoking in the same tick cancels the save in some browsers; the object
    // URL is dropped a moment later instead so the blob is not held for the
    // life of the page.
    setTimeout(() => URL.revokeObjectURL(url), 10000);

    return name;
}

/** Pulls the server's filename out of `attachment; filename="…"`. */
function filenameFrom(header) {
    if (!header) return null;
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
    return match ? decodeURIComponent(match[1]) : null;
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, { body }),
    put: (path, body) => request('PUT', path, { body }),
    patch: (path, body) => request('PATCH', path, { body }),
    delete: (path) => request('DELETE', path),
    download,
    raw: request,
};
