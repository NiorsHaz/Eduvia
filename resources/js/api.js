const BASE = '/api/v1';

let token = localStorage.getItem('school_token') || null;

export function setToken(value) {
    token = value;
    if (value) localStorage.setItem('school_token', value);
    else localStorage.removeItem('school_token');
}

export function getToken() {
    return token;
}

async function request(path, { method = 'GET', body, params } = {}) {
    let url = BASE + path;
    if (params) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') query.append(key, value);
        });
        const qs = query.toString();
        if (qs) url += (url.includes('?') ? '&' : '?') + qs;
    }

    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;

    const isForm = body instanceof FormData;
    if (body && !isForm) headers['Content-Type'] = 'application/json';

    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            body: isForm ? body : body ? JSON.stringify(body) : undefined,
        });
    } catch (error) {
        throw new Error('Le serveur est injoignable. Vérifiez votre connexion.');
    }

    if (response.status === 401) {
        setToken(null);
        window.dispatchEvent(new CustomEvent('auth:expired'));
    }

    if (response.status === 204) return null;

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(payload.message || 'Une erreur est survenue.');
        error.status = response.status;
        error.errors = payload.errors || {};
        throw error;
    }

    return payload;
}

export const api = {
    get: (path, params) => request(path, { params }),
    post: (path, body) => request(path, { method: 'POST', body }),
    put: (path, body) => request(path, { method: 'PUT', body }),
    delete: (path) => request(path, { method: 'DELETE' }),
    upload: (path, formData) => request(path, { method: 'POST', body: formData }),
};

export function firstError(error) {
    if (error?.errors) {
        const key = Object.keys(error.errors)[0];
        if (key) return error.errors[key][0];
    }
    return error?.message || 'Une erreur est survenue.';
}
