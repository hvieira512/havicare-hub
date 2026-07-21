import {requestJson} from './http.js';

export const getProtocols = () => requestJson('/api/protocols');
