import {requestJson, withQuery} from './http.js';

export const getDevices = (params = {}) => requestJson(withQuery('/api/devices', params));
export const getDevice = imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`);
export const saveConfiguration = (imei, configurations) => requestJson(`/api/devices/${encodeURIComponent(imei)}/configurations`, {
    method: 'PATCH',
    body: JSON.stringify({configurations}),
});
export const requestFeature = (imei, feature) => requestJson(`/api/devices/${encodeURIComponent(imei)}/requests`, {
    method: 'POST',
    body: JSON.stringify({feature}),
});
export const requestCapability = (imei, capability, value) => requestJson(`/api/devices/${encodeURIComponent(imei)}/requests`, {
    method: 'POST',
    body: JSON.stringify({capability, value}),
});
export const saveDevice = (imei, supplier, model, deviceType = 'watch', licenseId = '0', simNumber = '', deviceId = '', originalImei = '', company = 'null') => requestJson(
    originalImei ? `/api/devices/${encodeURIComponent(originalImei)}` : '/api/devices',
    {
        method: originalImei ? 'PUT' : 'POST',
        body: JSON.stringify({
            imei,
            supplier,
            model,
            deviceType,
            licenseId,
            simNumber,
            ...(deviceType === 'watch' ? {} : {deviceId}),
            company,
        }),
    }
);
export const deleteDevice = imei => requestJson(`/api/devices/${encodeURIComponent(imei)}`, {method: 'DELETE'});
