import {requestJson} from './http.js';

export const previewCapabilityDiscovery = (body) => requestJson('/api/capability-discovery', {
    method: 'POST',
    body: JSON.stringify(body),
});
export const applyCapabilityDiscoveryRun = (id) => requestJson(`/api/capability-discovery/${encodeURIComponent(id)}/apply`, {
    method: 'POST',
});
