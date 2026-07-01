export const authHeaders = () => {
    const token = window.hubDashboardApiToken?.access_token || '';
    return token === '' ? {} : {Authorization: `Bearer ${token}`};
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

export const requestJson = (url, options = {}) => fetch(
    url,
    Object.assign({}, options, {
        headers: Object.assign({'Content-Type': 'application/json'}, authHeaders(), options.headers || {}),
    })
).then(parseJsonResponse).catch(networkError);

export const formRequest = (url, formData, options = {}) => fetch(
    url,
    Object.assign({method: 'POST', body: formData}, options, {
        headers: Object.assign({}, authHeaders(), options.headers || {}),
    })
).then(parseJsonResponse).catch(networkError);

export const withQuery = (url, params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `${url}?${encoded}` : url;
};
