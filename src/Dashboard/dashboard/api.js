export const requestJson = (url, options = {}) => fetch(
    url,
    Object.assign({headers: {'Content-Type': 'application/json'}}, options)
).then(response => response.json());

export const formRequest = (url, formData, options = {}) => fetch(
    url,
    Object.assign({method: 'POST', body: formData}, options)
).then(response => response.json());

export const api = {
    summary: () => requestJson('/api/dashboard/summary'),
    device: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`),
    configuration: (imei, supplier = '', model = '') => {
        const params = new URLSearchParams();
        if (supplier) params.set('supplier', supplier);
        if (model) params.set('model', model);
        const query = params.toString();
        return requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration${query ? `?${query}` : ''}`);
    },
    saveConfiguration: (imei, configs, supplier = '', model = '') => requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration`, {
        method: 'PUT',
        body: JSON.stringify({configs, supplier, model}),
    }),
    applyConfiguration: (imei, key, supplier = '', model = '') => requestJson(`/api/devices/${encodeURIComponent(imei)}/configuration/${encodeURIComponent(key)}/apply`, {
        method: 'POST',
        body: JSON.stringify({supplier, model}),
    }),
    sendCommand: (imei, command) => requestJson(`/api/devices/${encodeURIComponent(imei)}/commands`, {
        method: 'POST',
        body: JSON.stringify({command}),
    }),
    saveDevice: (imei, supplier, model, simNumber = '', deviceId = '', originalImei = '') => requestJson(
        originalImei ? `/api/devices/${encodeURIComponent(originalImei)}` : '/api/devices',
        {
            method: originalImei ? 'PUT' : 'POST',
            body: JSON.stringify({imei, supplier, model, simNumber, deviceId}),
        }
    ),
    deleteDevice: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`, {method: 'DELETE'}),
    suppliers: () => requestJson('/api/suppliers'),
    saveSupplier: name => requestJson('/api/suppliers', {method: 'POST', body: JSON.stringify({name})}),
    updateSupplier: (id, enabled) => requestJson(`/api/suppliers/${id}`, {method: 'PUT', body: JSON.stringify({enabled})}),
    deleteSupplier: id => requestJson(`/api/suppliers/${id}`, {method: 'DELETE'}),
    models: () => requestJson('/api/models'),
    saveModel: (id, body) => formRequest(id ? `/api/models/${encodeURIComponent(id)}` : '/api/models', body, {
        method: id ? 'PUT' : 'POST',
    }),
    deleteModel: id => requestJson(`/api/models/${id}`, {method: 'DELETE'}),
};
