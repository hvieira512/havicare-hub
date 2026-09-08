import { startDashboard } from "./dashboard/app.js";
import { initializeDashboardSession } from "./dashboard/auth/session.js";
import { initializeTheme } from "./dashboard/theme.js";
import { installErrorReporting } from "./dashboard/observability.js";

// Antes de tudo, para apanhar também o que rebentar no arranque.
installErrorReporting();

document.addEventListener("DOMContentLoaded", () => {
    // O tema antes da sessão: o `<head>` já pintou a página na cor certa, e o que falta aqui
    // é ligar o botão -- que existe no ecrã de entrada tanto como no da aplicação.
    initializeTheme();
    void initializeDashboardSession(startDashboard);
});
