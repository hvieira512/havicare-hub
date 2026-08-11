import {requestJson, withQuery} from './http.js';

export const getCapabilities = (params = {}) => requestJson(withQuery('/api/capabilities', params));
