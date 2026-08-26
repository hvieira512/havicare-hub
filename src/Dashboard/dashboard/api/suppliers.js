import {requestJson, withQuery} from './http.js';

// Coleccao so de leitura: os fornecedores vivem no codigo e nada aqui os escreve.
export const getSuppliers = (params = {}) => requestJson(withQuery('/api/suppliers', params));
