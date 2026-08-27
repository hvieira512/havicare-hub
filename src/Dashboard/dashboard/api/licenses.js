import { requestJson, withQuery } from "./http.js";

export const getLicenses = (params = {}) => requestJson(withQuery("/api/licenses", params));
export const saveLicense = (id, body) => requestJson(id ? `/api/licenses/${encodeURIComponent(id)}` : "/api/licenses", {
    method: id ? "PUT" : "POST",
    body: JSON.stringify(body),
});
export const deleteLicense = (id) => requestJson(`/api/licenses/${encodeURIComponent(id)}`, { method: "DELETE" });
