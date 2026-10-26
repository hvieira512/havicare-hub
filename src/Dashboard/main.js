import { initializeDashboardSession } from "./dashboard/auth/session.js";
import { initializeTheme } from "./dashboard/core/theme.js";
import { installErrorReporting } from "./dashboard/core/observability.js";

installErrorReporting();

/** O grafo da dashboard carrega-se depois de autenticar, uma vez; uma falha trata-a o `session.js`. */
let dashboardApp = null;
const loadDashboardApp = () => (dashboardApp ??= import("./dashboard/app.js"));

document.addEventListener("DOMContentLoaded", () => {
    // O `<head>` já pintou o tema; falta ligar o botão, que existe também no ecrã de entrada.
    initializeTheme();

    // A carga arranca no clique, em paralelo com o pedido de autenticação.
    document
        .getElementById("dashboardLoginForm")
        ?.addEventListener("submit", () => void loadDashboardApp(), { once: true });

    void initializeDashboardSession(async () => {
        const { startDashboard } = await loadDashboardApp();
        await startDashboard();
    });
});
