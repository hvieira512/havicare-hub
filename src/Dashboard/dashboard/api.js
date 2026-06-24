const authHeaders = () => {
    const token = window.hubDashboardApiToken?.access_token || '';
    return token === '' ? {} : {Authorization: `Bearer ${token}`};
};

export const requestJson = (url, options = {}) => fetch(
    url,
    Object.assign({}, options, {
        headers: Object.assign({'Content-Type': 'application/json'}, authHeaders(), options.headers || {}),
    })
).then(response => response.json());

export const formRequest = (url, formData, options = {}) => fetch(
    url,
    Object.assign({method: 'POST', body: formData}, options, {
        headers: Object.assign({}, authHeaders(), options.headers || {}),
    })
).then(response => response.json());

const withQuery = (url, params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `${url}?${encoded}` : url;
};

export const api = {
    devices: (params = {}) => requestJson(withQuery('/api/devices', params)),
    device: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`),
    configuration: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration`),
    saveConfiguration: (imei, configs, supplier = '', model = '') => requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration`, {
        method: 'PUT',
        body: JSON.stringify({configs, supplier, model}),
    }),
    applyConfiguration: (imei, key, supplier = '', model = '') => requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration/${encodeURIComponent(key)}/apply`, {
        method: 'POST',
        body: JSON.stringify({supplier, model}),
    }),
    sendCommand: (imei, requestId) => requestJson(`/api/devices/${encodeURIComponent(imei)}/commands`, {
        method: 'POST',
        body: JSON.stringify({requestId}),
    }),
    saveDevice: (imei, supplier, model, deviceType = 'watch', licenseId = '0', simNumber = '', deviceId = '', originalImei = '') => requestJson(
        originalImei ? `/api/devices/${encodeURIComponent(originalImei)}` : '/api/devices',
        {
            method: originalImei ? 'PUT' : 'POST',
            body: JSON.stringify({imei, supplier, model, deviceType, licenseId, simNumber, deviceId}),
        }
    ),
    deleteDevice: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`, {method: 'DELETE'}),
    suppliers: (params = {}) => requestJson(withQuery('/api/suppliers', params)),
    saveSupplier: name => requestJson('/api/suppliers', {method: 'POST', body: JSON.stringify({name})}),
    updateSupplier: (id, enabled) => requestJson(`/api/suppliers/${id}`, {method: 'PUT', body: JSON.stringify({enabled})}),
    deleteSupplier: id => requestJson(`/api/suppliers/${id}`, {method: 'DELETE'}),
    models: (params = {}) => requestJson(withQuery('/api/models', params)),
    saveModel: (id, body) => formRequest(id ? `/api/models/${encodeURIComponent(id)}` : '/api/models', body, {
        method: id ? 'PUT' : 'POST',
    }),
    deleteModel: id => requestJson(`/api/models/${id}`, {method: 'DELETE'}),
    apiUsers: (params = {}) => requestJson(withQuery('/api/api-users', params)),
    saveApiUser: (id, body) => requestJson(id ? `/api/api-users/${encodeURIComponent(id)}` : '/api/api-users', {
        method: id ? 'PUT' : 'POST',
        body: JSON.stringify(body),
    }),
    deleteApiUser: id => requestJson(`/api/api-users/${encodeURIComponent(id)}`, {method: 'DELETE'}),
};
