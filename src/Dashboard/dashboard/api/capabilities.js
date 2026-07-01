import {requestJson, withQuery} from './http.js';

export const getCapabilities = (params = {}) => requestJson(withQuery('/api/capabilities', params));
export const getCapability = id => requestJson(`/api/capabilities/${encodeURIComponent(id)}`);
