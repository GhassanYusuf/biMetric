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

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, { body }),
    put: (path, body) => request('PUT', path, { body }),
    patch: (path, body) => request('PATCH', path, { body }),
    delete: (path) => request('DELETE', path),
    raw: request,
};
