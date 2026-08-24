export const authHeaders = () => {
    const token = window.hubDashboardApiToken?.access_token || '';
    return token === '' ? {} : {Authorization: `Bearer ${token}`};
};

let tokenRefreshTimer = null;
let tokenRefreshInFlight = null;
const TOKEN_REFRESH_SKEW_MS = 60_000;
const TOKEN_REFRESH_RETRY_MS = 15_000;

const emitTokenUpdated = () => {
    window.dispatchEvent(new Event('hub-dashboard-api-token-updated'));
};

const emitAuthRequired = () => {
    window.dispatchEvent(new Event('hub-dashboard-auth-required'));
};

export const setDashboardApiToken = token => {
    window.hubDashboardApiToken = token;
    emitTokenUpdated();
    scheduleTokenRefresh();
};

export const clearDashboardApiToken = () => {
    if (tokenRefreshTimer !== null) {
        window.clearTimeout(tokenRefreshTimer);
        tokenRefreshTimer = null;
    }
    window.hubDashboardApiToken = null;
    emitTokenUpdated();
};

const scheduleTokenRefresh = (delayOverrideMs = null) => {
    if (tokenRefreshTimer !== null) {
        window.clearTimeout(tokenRefreshTimer);
        tokenRefreshTimer = null;
    }

    const expiresAt = window.hubDashboardApiToken?.expires_at;
    const token = window.hubDashboardApiToken?.access_token || '';
    if (token === '' || typeof expiresAt !== 'string' || expiresAt === '') {
        return;
    }

    const expiresAtMs = Date.parse(expiresAt);
    if (!Number.isFinite(expiresAtMs)) {
        return;
    }

    const delayMs = delayOverrideMs === null
        ? Math.max(0, expiresAtMs - Date.now() - TOKEN_REFRESH_SKEW_MS)
        : Math.max(0, delayOverrideMs);

    tokenRefreshTimer = window.setTimeout(() => {
        void refreshAccessToken();
    }, delayMs);
};

const networkError = error => ({
    error: {
        code: 'network_error',
        message: error instanceof Error ? error.message : 'Failed to fetch',
    },
    _httpStatus: 0,
});

const parseJsonResponse = async response => {
    const raw = await response.text();
    if (raw.trim() === '') {
        return {_httpStatus: response.status};
    }

    try {
        const body = JSON.parse(raw);
        if (body && typeof body === 'object' && !Array.isArray(body)) {
            return Object.assign({}, body, {_httpStatus: response.status});
        }
        return body;
    } catch (error) {
        return {
            error: {
                code: 'invalid_json',
                message: error instanceof Error ? error.message : 'Invalid JSON response',
            },
            _httpStatus: response.status,
            _rawBody: raw,
        };
    }
};

const handleAuthExpiry = response => {
    return response.status === 401;
};

const buildFetchOptions = (options = {}) => Object.assign({}, options, {
    headers: Object.assign({}, authHeaders(), options.headers || {}),
});

const requestWithAuthRetry = async (url, options = {}) => {
    const response = await fetch(url, buildFetchOptions(options));

    if (handleAuthExpiry(response)) {
        if (await refreshAccessToken()) {
            return fetch(url, buildFetchOptions(options));
        }
        emitAuthRequired();
    }

    return response;
};

export const refreshAccessToken = async () => {
    if (tokenRefreshInFlight !== null) {
        return tokenRefreshInFlight;
    }

    const refreshToken = window.hubDashboardApiToken?.refresh_token || '';
    if (refreshToken === '') {
        return null;
    }

    tokenRefreshInFlight = (async () => {
        try {
            const response = await fetch('/api/auth/login', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({refresh_token: refreshToken}),
            });
            const payload = await parseJsonResponse(response);
            const nextToken = payload?.token?.access_token || '';
            if (response.ok && nextToken !== '') {
                setDashboardApiToken(payload.token);
                return payload.token;
            }
        } catch {
            // Retry below while the current access token remains valid.
        }

        const expiresAt = window.hubDashboardApiToken?.expires_at;
        const expiresAtMs = typeof expiresAt === 'string' ? Date.parse(expiresAt) : Number.NaN;
        if (Number.isFinite(expiresAtMs) && expiresAtMs > Date.now()) {
            const retryDelay = Math.min(TOKEN_REFRESH_RETRY_MS, Math.max(1000, expiresAtMs - Date.now() - 5000));
            scheduleTokenRefresh(retryDelay);
        } else {
            emitAuthRequired();
        }

        return null;
    })().finally(() => {
        tokenRefreshInFlight = null;
    });

    return tokenRefreshInFlight;
};

export const requestJson = (url, options = {}) => requestWithAuthRetry(url, Object.assign({}, options, {
    headers: Object.assign({'Content-Type': 'application/json'}, options.headers || {}),
}))
    .then(parseJsonResponse)
    .catch(networkError);

export const formRequest = (url, formData, options = {}) => requestWithAuthRetry(url, Object.assign({method: 'POST', body: formData}, options))
    .then(parseJsonResponse)
    .catch(networkError);

scheduleTokenRefresh();

export const withQuery = (url, params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        // Uma lista sai como `chave[]=a&chave[]=b`, que e o que o `parse_str` do lado do
        // servidor le como array. Uma lista vazia nao sai -- e a ausencia do filtro, e nao
        // um filtro por nada.
        if (Array.isArray(value)) {
            value
                .filter((entry) => entry !== undefined && entry !== null && entry !== '')
                .forEach((entry) => query.append(`${key}[]`, String(entry)));
            return;
        }
        query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `${url}?${encoded}` : url;
};
