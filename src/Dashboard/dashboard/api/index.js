import { formRequest, requestJson } from "./http.js";

/**
 * Os endpoints do hub, um por linha. O transporte -- credencial, erros, query string -- vive
 * no `http.js`, e é o único módulo deste directório com lógica.
 */

export { authHeaders, formRequest, getDashboardApiToken, requestJson } from "./http.js";

const id = encodeURIComponent;

/* ---------- dispositivos ---------- */

export const getDevices = (params = {}) => requestJson("/api/devices", { query: params });
export const getDevice = (imei) => requestJson(`/api/devices/${id(imei)}`);
export const deleteDevice = (imei) => requestJson(`/api/devices/${id(imei)}`, { method: "DELETE" });
export const createDeviceLink = (gatewayImei, linkedImei) =>
    requestJson(`/api/devices/${id(gatewayImei)}/links/${id(linkedImei)}`, { method: "POST" });
export const deleteDeviceLink = (gatewayImei, linkedImei) =>
    requestJson(`/api/devices/${id(gatewayImei)}/links/${id(linkedImei)}`, { method: "DELETE" });
export const saveConfiguration = (imei, payload) =>
    requestJson(`/api/devices/${id(imei)}/configurations`, {
        method: "PATCH",
        body: JSON.stringify(payload),
    });
export const requestFeature = (imei, feature) =>
    requestJson(`/api/devices/${id(imei)}/requests`, {
        method: "POST",
        body: JSON.stringify({ feature }),
    });
export const requestCapability = (imei, capability, value) =>
    requestJson(`/api/devices/${id(imei)}/requests`, {
        method: "POST",
        body: JSON.stringify({ capability, value }),
    });
export const saveDevice = (imei, supplier, model, deviceType = "watch", licenseId = "0", simNumber = "", deviceId = "", originalImei = "", company = "null") =>
    requestJson(originalImei ? `/api/devices/${id(originalImei)}` : "/api/devices", {
        method: originalImei ? "PUT" : "POST",
        body: JSON.stringify({
            imei,
            supplier,
            model,
            deviceType,
            licenseId,
            simNumber,
            ...(deviceType === "watch" ? {} : { deviceId }),
            company,
        }),
    });

/* ---------- catálogo ---------- */

export const getModels = (params = {}) => requestJson("/api/models", { query: params });
export const getModel = (modelId) => requestJson(`/api/models/${id(modelId)}`);
export const getModelFilters = () => requestJson("/api/device-types/suppliers");
export const getDeviceTypeSuppliersModels = () => requestJson("/api/device-types/suppliers/models");
export const getModelTemplate = (params) => requestJson("/api/models/template", { query: params });
export const saveModel = (modelId, body) =>
    formRequest(modelId ? `/api/models/${id(modelId)}` : "/api/models", body, {
        method: modelId ? "PUT" : "POST",
    });
export const deleteModel = (modelId) => requestJson(`/api/models/${id(modelId)}`, { method: "DELETE" });
export const getSuppliers = (params = {}) => requestJson("/api/suppliers", { query: params });
export const getProtocols = () => requestJson("/api/protocols");
export const getCapabilities = (params = {}) => requestJson("/api/capabilities", { query: params });

/* ---------- empresas e licenças ---------- */

export const getCompanies = (params = {}) => requestJson("/api/companies", { query: params });
export const createCompany = (name) =>
    requestJson("/api/companies", { method: "POST", body: JSON.stringify({ name }) });
export const updateCompany = (companyId, name) =>
    requestJson(`/api/companies/${id(companyId)}`, { method: "PUT", body: JSON.stringify({ name }) });
export const deleteCompany = (companyId) =>
    requestJson(`/api/companies/${id(companyId)}`, { method: "DELETE" });
export const getLicenses = (params = {}) => requestJson("/api/licenses", { query: params });
export const saveLicense = (licenseId, body) =>
    requestJson(licenseId ? `/api/licenses/${id(licenseId)}` : "/api/licenses", {
        method: licenseId ? "PUT" : "POST",
        body: JSON.stringify(body),
    });
export const deleteLicense = (licenseId) =>
    requestJson(`/api/licenses/${id(licenseId)}`, { method: "DELETE" });

/* ---------- utilizadores da API ---------- */

export const getApiUsers = (params = {}) => requestJson("/api/users", { query: params });
export const saveApiUser = (userId, body) =>
    requestJson(userId ? `/api/users/${id(userId)}` : "/api/users", {
        method: userId ? "PUT" : "POST",
        body: JSON.stringify(body),
    });
export const deleteApiUser = (userId) => requestJson(`/api/users/${id(userId)}`, { method: "DELETE" });

/* ---------- bloqueados ---------- */

export const getDenylist = () => requestJson("/api/denylist");
export const blockDevice = (identity, protocol = "", note = "") =>
    requestJson("/api/denylist", {
        method: "POST",
        body: JSON.stringify({ identity, protocol, note }),
    });
export const unblockDevice = (identity) =>
    requestJson(`/api/denylist/${id(identity)}`, { method: "DELETE" });

/* ---------- notificações ---------- */

export const getNotifications = (limit = 20) =>
    requestJson("/api/notifications", { query: { limit } });
export const markNotificationsRead = (ids) =>
    requestJson("/api/notifications/read", { method: "PATCH", body: JSON.stringify({ ids }) });
export const deleteNotification = (notificationId) =>
    requestJson(`/api/notifications/${id(notificationId)}`, { method: "DELETE" });
