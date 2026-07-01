import {requestJson, withQuery} from './http.js';

export const getSuppliers = (params = {}) => requestJson(withQuery('/api/suppliers', params));
export const createSupplier = name => requestJson('/api/suppliers', {method: 'POST', body: JSON.stringify({name})});
export const updateSupplier = (id, body) => requestJson(`/api/suppliers/${id}`, {method: 'PUT', body: JSON.stringify(body)});
export const deleteSupplier = id => requestJson(`/api/suppliers/${id}`, {method: 'DELETE'});
