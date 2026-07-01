import {formRequest, requestJson, withQuery} from './http.js';

export const getModels = (params = {}) => requestJson(withQuery('/api/models', params));
export const getModelFilters = () => requestJson('/api/models/filters');
export const getModel = id => requestJson(`/api/models/${encodeURIComponent(id)}`);
export const saveModel = (id, body) => formRequest(id ? `/api/models/${encodeURIComponent(id)}` : '/api/models', body, {
    method: id ? 'PUT' : 'POST',
});
export const deleteModel = id => requestJson(`/api/models/${id}`, {method: 'DELETE'});
