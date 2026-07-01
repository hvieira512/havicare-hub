export const authHeaders = () => {
    const token = window.hubDashboardApiToken?.access_token || '';
    return token === '' ? {} : {Authorization: `Bearer ${token}`};
};

export const requestJson = (url, options = {}) => fetch(
    url,
    Object.assign({}, options, {
        headers: Object.assign({'Content-Type': 'application/json'}, authHeaders(), options.headers || {}),
    })
).then(async response => {
    const body = await response.json();
    if (body && typeof body === 'object' && !Array.isArray(body)) {
        return Object.assign({}, body, {_httpStatus: response.status});
    }
    return body;
});

export const formRequest = (url, formData, options = {}) => fetch(
    url,
    Object.assign({method: 'POST', body: formData}, options, {
        headers: Object.assign({}, authHeaders(), options.headers || {}),
    })
).then(response => response.json());

export const withQuery = (url, params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `${url}?${encoded}` : url;
};
