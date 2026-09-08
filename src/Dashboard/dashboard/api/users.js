import { requestJson } from "./http.js";

export const getApiUsers = (params = {}) => requestJson("/api/users", { query: params });
export const saveApiUser = (id, body) => requestJson(id ? `/api/users/${encodeURIComponent(id)}` : "/api/users", {
    method: id ? "PUT" : "POST",
    body: JSON.stringify(body),
});
export const deleteApiUser = (id) => requestJson(`/api/users/${encodeURIComponent(id)}`, { method: "DELETE" });
