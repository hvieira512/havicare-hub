import { requestJson } from "./http.js";

export const getCapabilities = (params = {}) => requestJson("/api/capabilities", { query: params });
