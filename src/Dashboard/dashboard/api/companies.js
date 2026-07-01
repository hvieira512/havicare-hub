import {requestJson, withQuery} from './http.js';

export const getCompanies = (params = {}) => requestJson(withQuery('/api/companies', params));
export const getCompany = id => requestJson(`/api/companies/${encodeURIComponent(id)}`);
export const createCompany = name => requestJson('/api/companies', {
    method: 'POST',
    body: JSON.stringify({name}),
});
export const updateCompany = (id, name) => requestJson(`/api/companies/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify({name}),
});
export const deleteCompany = id => requestJson(`/api/companies/${encodeURIComponent(id)}`, {method: 'DELETE'});
