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

const authFetch = (url, options = {}) => fetch(
    url,
    Object.assign({}, options, {
        headers: Object.assign({'Content-Type': 'application/json'}, authHeaders(), options.headers || {}),
    })
);

const authTokenQuery = () => {
    const token = window.hubDashboardApiToken?.access_token || '';
    return token === '' ? '' : `token=${encodeURIComponent(token)}`;
};

function readNdjsonStream(response, onMessage, onError, signal) {
    if (!response.ok) {
        onError(new Error(`Stream request failed with HTTP ${response.status}`));
        return;
    }
    if (!response.body?.getReader) {
        onError(new Error('Streaming is not supported by this browser'));
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    const readNext = async () => {
        try {
            while (true) {
                if (signal?.aborted) {
                    await reader.cancel();
                    return;
                }
                const {done, value} = await reader.read();
                if (done) {
                    return;
                }
                buffer += decoder.decode(value, {stream: true});
                let newlineIndex = buffer.indexOf('\n');
                while (newlineIndex !== -1) {
                    const line = buffer.slice(0, newlineIndex).trim();
                    buffer = buffer.slice(newlineIndex + 1);
                    if (line !== '') {
                        try {
                            onMessage(JSON.parse(line));
                        } catch (error) {
                            onError(error instanceof Error ? error : new Error(String(error)));
                        }
                    }
                    newlineIndex = buffer.indexOf('\n');
                }
            }
        } catch (error) {
            if (!signal?.aborted) {
                onError(error instanceof Error ? error : new Error(String(error)));
            }
        }
    };

    void readNext();
}

export const api = {
    devices: (params = {}) => requestJson(withQuery('/api/devices', params)),
    device: imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`),
    deviceLive: (imei, handlers = {}) => {
        const controller = new AbortController();
        authFetch(`/api/devices/${encodeURIComponent(imei)}/live`, {
            method: 'GET',
            signal: controller.signal,
            headers: Object.assign({
                Accept: 'application/x-ndjson',
            }, authHeaders()),
        }).then(response => {
            readNdjsonStream(
                response,
                handlers.onMessage || (() => {}),
                handlers.onError || (() => {}),
                controller.signal
            );
        }).catch(error => {
            if (!controller.signal.aborted) {
                (handlers.onError || (() => {}))(error instanceof Error ? error : new Error(String(error)));
            }
        });

        return {
            close: () => controller.abort(),
        };
    },
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
