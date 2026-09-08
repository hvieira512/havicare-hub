import { requestJson } from "./http.js";

// Colecção só de leitura: os fornecedores vivem no código e nada aqui os escreve.
export const getSuppliers = (params = {}) => requestJson("/api/suppliers", { query: params });
