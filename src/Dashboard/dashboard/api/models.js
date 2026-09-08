import { formRequest, requestJson } from "./http.js";

export const getModels = (params = {}) => requestJson("/api/models", { query: params });
export const getModelFilters = () => requestJson("/api/device-types/suppliers");
export const getDeviceTypeSuppliersModels = () => requestJson("/api/device-types/suppliers/models");
export const getModelTemplate = (params) => requestJson("/api/models/template", { query: params });
export const getModel = (id) => requestJson(`/api/models/${encodeURIComponent(id)}`);
export const saveModel = (id, body) => formRequest(id ? `/api/models/${encodeURIComponent(id)}` : "/api/models", body, {
    method: id ? "PUT" : "POST",
});
export const deleteModel = (id) => requestJson(`/api/models/${id}`, { method: "DELETE" });
