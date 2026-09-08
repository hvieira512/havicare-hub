import { requestJson } from "./http.js";

export const getNotifications = (limit = 20) =>
    requestJson("/api/notifications", { query: { limit } });

export const markNotificationsRead = (ids) =>
    requestJson("/api/notifications/read", {
        method: "PATCH",
        body: JSON.stringify({ ids }),
    });

export const deleteNotification = (id) =>
    requestJson(`/api/notifications/${encodeURIComponent(id)}`, {
        method: "DELETE",
    });
