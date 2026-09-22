import { initializeDashboardSession } from "./dashboard/auth/session.js";
import { initializeTheme } from "./dashboard/theme.js";
import { installErrorReporting } from "./dashboard/observability.js";

// Antes de tudo, para apanhar também o que rebentar no arranque.
installErrorReporting();

/**
 * O grafo da dashboard -- 72 módulos ES, 512 KB de JavaScript -- só serve depois de
 * autenticar, e quem fica parado no formulário de entrada não o paga. Carrega-se uma vez só,
 * dê a ordem o clique no login ou a sessão que já estava guardada.
 *
 * Uma carga que falhe não se recupera aqui: o browser guarda no mapa de módulos a falha por
 * URL, e um segundo `import()` do mesmo especificador resolve para a entrada nula sem voltar
 * à rede -- medido contra o hub local, duas tentativas e um só pedido. Quem trata da falha é
 * o `session.js`, que devolve o ecrã de entrada e pede para recarregar a página.
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
