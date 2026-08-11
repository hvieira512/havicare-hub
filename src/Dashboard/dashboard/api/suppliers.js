import {requestJson, withQuery} from './http.js';

export const getSuppliers = (params = {}) => requestJson(withQuery('/api/suppliers', params));
export const updateSupplier = (id, body) => requestJson(`/api/suppliers/${id}`, {method: 'PUT', body: JSON.stringify(body)});
