import {requestJson} from './http.js';

export const listCapabilityDiscoveryRuns = () => requestJson('/api/capability-discovery');
export const previewCapabilityDiscovery = (body) => requestJson('/api/capability-discovery', {
    method: 'POST',
    body: JSON.stringify(body),
});
export const getCapabilityDiscoveryRun = (id) => requestJson(`/api/capability-discovery/${encodeURIComponent(id)}`);
export const applyCapabilityDiscoveryRun = (id) => requestJson(`/api/capability-discovery/${encodeURIComponent(id)}/apply`, {
    method: 'POST',
});
