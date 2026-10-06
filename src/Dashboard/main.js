import { initializeDashboardSession } from "./dashboard/auth/session.js";
import { initializeTheme } from "./dashboard/theme.js";
import { installErrorReporting } from "./dashboard/observability.js";

// Antes de tudo, para apanhar também o que rebentar no arranque.
installErrorReporting();

/**
 * O grafo da dashboard carrega-se só depois de autenticar, e uma vez só. Uma carga falhada não
 * se recupera aqui: o browser guarda a falha, e quem a trata é o `session.js`.
 */
let dashboardApp = null;
const loadDashboardApp = () => (dashboardApp ??= import("./dashboard/app.js"));

document.addEventListener("DOMContentLoaded", () => {
    // O tema antes da sessão: o `<head>` já pintou a página na cor certa, e o que falta aqui
    // é ligar o botão -- que existe no ecrã de entrada tanto como no da aplicação.
    initializeTheme();

    // A carga arranca no clique, em paralelo com o pedido de autenticação: é espera que já
    // se estava a gastar de qualquer maneira.
    document
        .getElementById("dashboardLoginForm")
        ?.addEventListener("submit", () => void loadDashboardApp(), { once: true });

    void initializeDashboardSession(async () => {
        const { startDashboard } = await loadDashboardApp();
        await startDashboard();
    });
});
