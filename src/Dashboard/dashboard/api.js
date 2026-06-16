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
    sendCommand: (imei, command) => requestJson(`/api/devices/${encodeURIComponent(imei)}/commands`, {
        method: 'POST',
        body: JSON.stringify({command}),
    }),
    saveDevice: (imei, supplier, model, originalImei = '') => requestJson(
        originalImei ? `/api/devices/${encodeURIComponent(originalImei)}` : '/api/devices',
        {
            method: originalImei ? 'PUT' : 'POST',
            body: JSON.stringify({imei, supplier, model}),
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
