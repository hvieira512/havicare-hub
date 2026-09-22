// O token da API vive aqui, no dono das escritas, e não num global no `window`: todos os
// leitores são módulos e importam o getter. O `set`/`clear` avisam a app pelo evento.
let apiToken = null;
export const getDashboardApiToken = () => apiToken;

export const authHeaders = () => {
    const token = apiToken?.access_token || "";
    return token === "" ? {} : { Authorization: `Bearer ${token}` };
};

let tokenRefreshTimer = null;
let tokenRefreshInFlight = null;
const TOKEN_REFRESH_SKEW_MS = 60_000;
const TOKEN_REFRESH_RETRY_MS = 15_000;

const emitTokenUpdated = () => {
    window.dispatchEvent(new Event("hub-dashboard-api-token-updated"));
};

const emitAuthRequired = () => {
    window.dispatchEvent(new Event("hub-dashboard-auth-required"));
};

export const setDashboardApiToken = (token) => {
    apiToken = token;
    emitTokenUpdated();
    scheduleTokenRefresh();
};

export const clearDashboardApiToken = () => {
    if (tokenRefreshTimer !== null) {
        window.clearTimeout(tokenRefreshTimer);
        tokenRefreshTimer = null;
    }
    apiToken = null;
    emitTokenUpdated();
};

const scheduleTokenRefresh = (delayOverrideMs = null) => {
    if (tokenRefreshTimer !== null) {
        window.clearTimeout(tokenRefreshTimer);
        tokenRefreshTimer = null;
    }

    const expiresAt = apiToken?.expires_at;
    const token = apiToken?.access_token || "";
    if (token === "" || typeof expiresAt !== "string" || expiresAt === "") {
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

// A frase do browser é inglesa -- «Failed to fetch» no Chrome -- e esta é a mensagem que
// mais aparece na dashboard, que é toda em português. O texto original fica no `detail`.
const networkError = (error) => ({
    error: {
        code: "network_error",
        message: "Não foi possível falar com o servidor. Verifique a ligação.",
        detail: error instanceof Error ? error.message : String(error),
    },
    _httpStatus: 0,
});

const parseJsonResponse = async (response) => {
    const raw = await response.text();
    if (raw.trim() === "") {
        // Quem chama decide por `if (result?.error)`. Sem a chave, um 500 sem corpo lia-se
        // como sucesso, e as caches de licenças e de capacidades guardavam a lista vazia.
        if (response.ok) {
            return { _httpStatus: response.status };
        }
        return {
            error: {
                code: "empty_body",
                message: `O servidor respondeu ${response.status} sem corpo.`,
            },
            _httpStatus: response.status,
        };
    }

    try {
        const body = JSON.parse(raw);
        if (body && typeof body === "object" && !Array.isArray(body)) {
            return Object.assign({}, body, { _httpStatus: response.status });
        }
        return body;
    } catch (error) {
        return {
            error: {
                code: "invalid_json",
                message: error instanceof Error ? error.message : "Invalid JSON response",
            },
            _httpStatus: response.status,
            _rawBody: raw,
        };
    }
};

const handleAuthExpiry = (response) => {
    return response.status === 401;
};

const buildFetchOptions = (options = {}) => Object.assign({}, options, {
    headers: Object.assign({}, authHeaders(), options.headers || {}),
});

const requestWithAuthRetry = async (url, options = {}) => {
    const response = await fetch(url, buildFetchOptions(options));

    if (handleAuthExpiry(response)) {
        if (await refreshAccessToken()) {
            const retried = await fetch(url, buildFetchOptions(options));
            // Um 401 com o token novo já não é um token velho: é a sessão a acabar. Sem
            // isto, a dashboard mostrava um erro genérico e ficava sem pedir autenticação.
            if (handleAuthExpiry(retried)) {
                emitAuthRequired();
            }
            return retried;
        }
        emitAuthRequired();
    }

    return response;
};

export const refreshAccessToken = async () => {
    if (tokenRefreshInFlight !== null) {
        return tokenRefreshInFlight;
    }

    const refreshToken = apiToken?.refresh_token || "";
    if (refreshToken === "") {
        return null;
    }

    tokenRefreshInFlight = (async () => {
        try {
            const response = await fetch("/api/auth/login", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ refresh_token: refreshToken }),
            });
            const payload = await parseJsonResponse(response);
            const nextToken = payload?.token?.access_token || "";
            if (response.ok && nextToken !== "") {
                setDashboardApiToken(payload.token);
                return payload.token;
            }
        } catch {
            // Repete abaixo enquanto o token de acesso actual continuar válido.
        }

        const expiresAt = apiToken?.expires_at;
        const expiresAtMs = typeof expiresAt === "string" ? Date.parse(expiresAt) : Number.NaN;
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

export const requestJson = (url, { query, ...options } = {}) => requestWithAuthRetry(query ? withQuery(url, query) : url, Object.assign({}, options, {
    headers: Object.assign({ "Content-Type": "application/json" }, options.headers || {}),
}))
    .then(parseJsonResponse)
    .catch(networkError);

export const formRequest = (url, formData, options = {}) => requestWithAuthRetry(url, Object.assign({ method: "POST", body: formData }, options))
    .then(parseJsonResponse)
    .catch(networkError);

scheduleTokenRefresh();

// Interno ao http.js: os query params entram pelo `requestJson(url, { query })`, e não como
// uma composição repetida em cada chamada.
const withQuery = (url, params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === "") return;
        // Uma lista sai como `chave[]=a&chave[]=b`, que é o que o `parse_str` do servidor lê
        // como array. Uma lista vazia não sai: é a ausência do filtro, e não um filtro por
        // nada.
        if (Array.isArray(value)) {
            value
                .filter((entry) => entry !== undefined && entry !== null && entry !== "")
                .forEach((entry) => query.append(`${key}[]`, String(entry)));
            return;
        }
        query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `${url}?${encoded}` : url;
};
