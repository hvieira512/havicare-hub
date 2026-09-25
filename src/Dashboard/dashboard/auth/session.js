import {
    authHeaders,
    clearDashboardApiToken,
    getDashboardApiToken,
    requestSessionToken,
    setDashboardApiToken,
} from "../api/http.js";
import { toast } from "../dialogs.js";
import {
    clearStorageKey,
    LAST_ACTIVITY_STORAGE_KEY,
    loadTextStorage,
    saveTextStorage,
} from "../storage.js";

const ADMIN_ROLE = "hub_admin";
const WARNING_AFTER_MS = 15 * 60 * 1000;
const LOGOUT_AFTER_MS = 20 * 60 * 1000;
const ACTIVITY_WRITE_THROTTLE_MS = 1000;
const ACTIVITY_EVENTS = ["pointerdown", "keydown", "scroll", "touchstart"];

let warningTimer = null;
let logoutTimer = null;
let lastActivityAt = 0;
let lastActivityWriteAt = 0;
let warningVisible = false;
let dashboardStarted = false;
let onAuthenticated = async () => {};

const elements = () => ({
    app: document.getElementById("dashboardApp"),
    login: document.getElementById("dashboardLogin"),
    loginForm: document.getElementById("dashboardLoginForm"),
    loginUsername: document.getElementById("dashboardLoginUsername"),
    loginPassword: document.getElementById("dashboardLoginPassword"),
    loginSubmit: document.getElementById("dashboardLoginSubmit"),
    loginSubmitLabel: document.querySelector(".dashboard-login-submit-label"),
    loginSubmitLoading: document.querySelector(".dashboard-login-submit-loading"),
    authenticatedUsername: document.getElementById("dashboardAuthenticatedUsername"),
    logoutButton: document.getElementById("dashboardLogoutBtn"),
});

const authRequired = () => document.body.dataset.dashboardAuthRequired === "true";

const renderAuthenticatedUsername = (token) => {
    const { authenticatedUsername } = elements();
    if (!authenticatedUsername) return;
    authenticatedUsername.textContent = String(token?.username || "Administrador");
};

/**
 * O que a dashboard aceita como sessão: um token de acesso de administrador por expirar.
 *
 * Já não há token de renovação a validar aqui -- esse vive no cookie `HttpOnly` e este código
 * nunca o vê.
 */
export const validAdminToken = (token) => {
    if (!token || typeof token !== "object" || token.role !== ADMIN_ROLE) {
        return false;
    }

    const expiresAt = Date.parse(String(token.expires_at || ""));
    return String(token.access_token || "") !== "" &&
        Number.isFinite(expiresAt) &&
        expiresAt > Date.now();
};

const clearTimers = () => {
    [warningTimer, logoutTimer].forEach((timer) => {
        if (timer !== null) {
            window.clearTimeout(timer);
        }
    });
    warningTimer = null;
    logoutTimer = null;
};

const showTimeoutWarning = () => {
    if (warningVisible || !getDashboardApiToken()?.access_token) {
        return;
    }
    const remainingMs = Math.max(0, lastActivityAt + LOGOUT_AFTER_MS - Date.now());
    warningVisible = true;
    void Swal.fire({
        icon: "warning",
        title: "A sessão está prestes a terminar",
        text: "Não foi detetada atividade. Confirme para continuar a utilizar o Hub.",
        confirmButtonText: "Continuar sessão",
        timer: remainingMs,
        timerProgressBar: true,
        allowOutsideClick: false,
        allowEscapeKey: false,
        reverseButtons: true,
    }).then((result) => {
        warningVisible = false;
        if (result.isConfirmed) {
            registerActivity(true);
            return;
        }
        if (result.dismiss === Swal.DismissReason.timer) {
            // Não termina já: o `scheduleIdleTimers` relê a atividade partilhada, e só termina
            // se também não tiver havido nada nos outros separadores.
            scheduleIdleTimers();
        }
    });
};

const hideTimeoutWarning = () => {
    if (warningVisible) {
        warningVisible = false;
        Swal.close();
    }
};

export const closeDashboardOverlays = () => {
    document.querySelectorAll("#dashboardApp .modal.show").forEach((modal) => {
        const instance = window.bootstrap?.Modal?.getInstance(modal);
        instance?.hide();
        modal.classList.remove("show");
        modal.style.display = "none";
        modal.setAttribute("aria-hidden", "true");
        modal.removeAttribute("aria-modal");
        modal.removeAttribute("role");
    });

    document
        .querySelectorAll(".modal-backdrop, .offcanvas-backdrop")
        .forEach((backdrop) => backdrop.remove());
    document.body.classList.remove("modal-open");
    document.body.style.removeProperty("overflow");
    document.body.style.removeProperty("padding-right");
};

const SHOW_PASSWORD = "Mostrar a palavra-passe";
const HIDE_PASSWORD = "Ocultar a palavra-passe";

const passwordToggles = () => [...document.querySelectorAll("[data-password-toggle]")];

const showPassword = (button, visible) => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;

    input.setAttribute("type", visible ? "text" : "password");
    const label = visible ? HIDE_PASSWORD : SHOW_PASSWORD;
    const glyph = button.querySelector("i");
    glyph?.classList.toggle("fa-eye", !visible);
    glyph?.classList.toggle("fa-eye-slash", visible);
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label);
    button.setAttribute("aria-pressed", String(visible));
};

export const initializePasswordVisibility = () => {
    passwordToggles().forEach((button) => button.addEventListener("click", () => {
        showPassword(button, button.getAttribute("aria-pressed") !== "true");
    }));
};

/** Uma palavra-passe revelada não sobrevive à saída: o ecrã seguinte é de outra pessoa. */
export const resetPasswordVisibility = () => {
    passwordToggles().forEach((button) => showPassword(button, false));
};

const setLoginBusy = (busy) => {
    const { loginSubmit, loginSubmitLabel, loginSubmitLoading } = elements();
    if (loginSubmit) {
        loginSubmit.disabled = busy;
        loginSubmit.setAttribute("aria-busy", String(busy));
    }
    loginSubmitLabel?.classList.toggle("d-none", busy);
    loginSubmitLoading?.classList.toggle("d-none", !busy);
};

const showLogin = (message) => {
    const { app, login, loginForm, loginUsername } = elements();
    closeDashboardOverlays();
    if (app) {
        app.hidden = true;
        app.classList.add("d-none");
    }
    if (login) {
        login.hidden = false;
        login.classList.remove("d-none");
    }
    loginForm?.reset();
    resetPasswordVisibility();
    if (message !== "") {
        toast("warning", message);
    }
    window.setTimeout(() => loginUsername?.focus(), 0);
};

const startDashboard = async () => {
    const { app, login } = elements();
    if (login) {
        login.hidden = true;
        login.classList.add("d-none");
    }
    if (app) {
        app.hidden = false;
        app.classList.remove("d-none");
    }
    if (!dashboardStarted) {
        dashboardStarted = true;
        try {
            await onAuthenticated();
        } catch {
            // A aplicação não chegou a arrancar -- o grafo dela entra por `import()` e esse
            // pedido pode falhar. A mensagem pede para recarregar e não para tentar de novo:
            // o browser guarda a falha no mapa de módulos, e só um documento novo a desfaz.
            dashboardStarted = false;
            showLogin("Não foi possível carregar a aplicação. Recarregue a página.");
        }
    }
};

/** O cookie é `HttpOnly` e só o Hub o apaga: falhado o pedido, não há nada a fazer daqui. */
const LOGOUT_FAILED_MESSAGE =
    "A sessão pode continuar aberta no Hub: o pedido para a fechar não chegou lá. " +
    "Volte a entrar e a sair quando houver ligação.";

const revokeSession = async () => {
    try {
        return (await fetch("/api/auth/logout", {
            method: "POST",
            credentials: "same-origin",
            headers: authHeaders(),
        })).ok;
    } catch {
        return false;
    }
};

/**
 * Fecha a sessão. Com `notifyServer`, manda apagar o cookie e revogar os dois tokens.
 *
 * Sem o pedido, o cookie ficava e o separador seguinte voltava a entrar sem palavra-passe. O
 * `notifyServer` a falso é para quem já soube por outro separador que a sessão acabou.
 *
 * Espera-se pela resposta: o pedido era disparado sem olhar, e uma saída que não chegasse ao
 * Hub deixava a sessão aberta lá com o ecrã de entrada à frente.
 */
const logout = async (message = "", notifyServer = true) => {
    clearTimers();
    hideTimeoutWarning();
    const revoked = notifyServer ? await revokeSession() : true;
    clearStorageKey(LAST_ACTIVITY_STORAGE_KEY);
    clearDashboardApiToken();
    // Sai-se sempre: ficar na dashboard a pedido de sair é pior do que sair mal.
    showLogin(revoked ? message : LOGOUT_FAILED_MESSAGE);
};

const IDLE_MESSAGE = "A sessão terminou por inatividade. Inicie sessão novamente.";

const scheduleIdleTimers = () => {
    clearTimers();
    // A atividade é de toda a gente: um separador esquecido relê o que os outros escreveram
    // antes de terminar a sessão, ou fechava-a por baixo de quem estava a trabalhar ao lado.
    const shared = Number(loadTextStorage(LAST_ACTIVITY_STORAGE_KEY));
    if (Number.isFinite(shared) && shared > lastActivityAt) {
        lastActivityAt = shared;
    }

    const idleMs = Date.now() - lastActivityAt;
    if (idleMs >= LOGOUT_AFTER_MS) {
        void logout(IDLE_MESSAGE);
        return;
    }
    if (idleMs >= WARNING_AFTER_MS) {
        showTimeoutWarning();
    } else {
        // Houve atividade -- aqui ou noutro separador -- e o aviso que estivesse aberto
        // deixou de ser verdade.
        hideTimeoutWarning();
        warningTimer = window.setTimeout(
            scheduleIdleTimers,
            WARNING_AFTER_MS - idleMs,
        );
    }
    logoutTimer = window.setTimeout(scheduleIdleTimers, LOGOUT_AFTER_MS - idleMs);
};

const registerActivity = (force = false) => {
    if (!getDashboardApiToken()?.access_token) return;
    if (warningVisible && !force) return;
    const now = Date.now();
    lastActivityAt = now;
    hideTimeoutWarning();
    scheduleIdleTimers();
    if (now - lastActivityWriteAt >= ACTIVITY_WRITE_THROTTLE_MS) {
        saveTextStorage(LAST_ACTIVITY_STORAGE_KEY, String(now));
        lastActivityWriteAt = now;
    }
};

const bindActivityTracking = () => {
    ACTIVITY_EVENTS.forEach((eventName) => {
        window.addEventListener(eventName, () => registerActivity(), { passive: true });
    });
    window.addEventListener("focus", () => registerActivity());
    document.addEventListener("visibilitychange", () => {
        if (
            document.visibilityState !== "visible" ||
            !authRequired() ||
            !getDashboardApiToken()?.access_token
        ) {
            return;
        }
        scheduleIdleTimers();
    });

    // Terminar sessão num separador termina-a em todos: o `storage` só dispara nos outros, e
    // a chave apagada é o sinal. Sem isto, o outro separador ficava a mostrar dados com um
    // token que ainda valia até expirar.
    window.addEventListener("storage", (event) => {
        if (event.key === LAST_ACTIVITY_STORAGE_KEY && event.newValue === null && getDashboardApiToken()) {
            void logout("A sessão foi terminada noutro separador.", false);
        }
    });
};

const login = async (event) => {
    event.preventDefault();
    const { loginUsername, loginPassword } = elements();
    const username = String(loginUsername?.value || "").trim();
    const password = String(loginPassword?.value || "");
    if (username === "" || password === "") {
        toast("danger", "Preencha o utilizador e a palavra-passe.");
        return;
    }

    setLoginBusy(true);
    try {
        // O `session: "cookie"` é o que pede a sessão de browser: o Hub devolve só o token de
        // acesso e guarda a renovação no cookie `HttpOnly`, em vez de a entregar ao script.
        const response = await fetch("/api/auth/login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ username, password, session: "cookie" }),
        });
        const payload = await response.json().catch(() => null);
        const token = payload?.token;
        if (!response.ok || !token?.access_token) {
            // Um 5xx (ou uma página não-JSON de um proxy) foi contactado e falhou: não é
            // credencial errada, e dizê-lo assim enganava.
            toast("danger", response.status >= 500
                ? "O Hub respondeu com um erro. Volte a tentar."
                : "Utilizador ou palavra-passe inválidos.");
            return;
        }
        if (token.role !== ADMIN_ROLE) {
            toast("danger", "Esta conta não tem permissões de administrador do Hub.");
            return;
        }

        setDashboardApiToken(token);
        renderAuthenticatedUsername(token);
        lastActivityAt = Date.now();
        lastActivityWriteAt = lastActivityAt;
        saveTextStorage(LAST_ACTIVITY_STORAGE_KEY, String(lastActivityAt));
        scheduleIdleTimers();
        toast("success", "Autenticação concluída. Bem-vindo ao Hub.");
        await startDashboard();
    } catch {
        toast("danger", "Não foi possível contactar o Hub. Volte a tentar.");
    } finally {
        setLoginBusy(false);
    }
};

/**
 * A sessão não está em lado nenhum que este código possa ler: pergunta-se ao Hub, que a
 * reconhece pelo cookie. É isto que faz um separador novo abrir já autenticado, e o que
 * devolve um token de acesso novo a cada separador.
 */
const restoreSession = async () => {
    let token = null;
    try {
        const response = await requestSessionToken();
        const payload = await response.json().catch(() => null);
        token = response.ok ? payload?.token || null : null;
    } catch {
        showLogin("Não foi possível contactar o Hub. Recarregue a página.");
        return;
    }

    if (!validAdminToken(token)) {
        showLogin("");
        return;
    }

    const storedActivity = Number(loadTextStorage(LAST_ACTIVITY_STORAGE_KEY));
    lastActivityAt = Number.isFinite(storedActivity) && storedActivity > 0
        ? storedActivity
        : Date.now();
    if (Date.now() - lastActivityAt >= LOGOUT_AFTER_MS) {
        await logout(IDLE_MESSAGE);
        return;
    }

    setDashboardApiToken(token);
    renderAuthenticatedUsername(token);
    scheduleIdleTimers();
    await startDashboard();
};

export async function initializeDashboardSession(startAuthenticatedDashboard) {
    onAuthenticated = startAuthenticatedDashboard;
    const { loginForm, logoutButton } = elements();

    loginForm?.addEventListener("submit", login);
    logoutButton?.addEventListener("click", () => void logout(""));
    initializePasswordVisibility();
    window.addEventListener("hub-dashboard-api-token-updated", () => {
        renderAuthenticatedUsername(getDashboardApiToken());
    });
    window.addEventListener("hub-dashboard-auth-required", () => {
        void logout("A sessão expirou. Inicie sessão novamente.");
    });
    bindActivityTracking();

    if (!authRequired()) {
        renderAuthenticatedUsername(null);
        await startDashboard();
        return;
    }
    await restoreSession();
}
