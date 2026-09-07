import { requestJson } from "./http.js";

export const getDenylist = () => requestJson("/api/denylist");

export const blockDevice = (identity, protocol = "", note = "") =>
    requestJson("/api/denylist", {
        method: "POST",
        body: JSON.stringify({ identity, protocol, note }),
    });

export const unblockDevice = (identity) =>
    requestJson(`/api/denylist/${encodeURIComponent(identity)}`, {
        method: "DELETE",
    });
