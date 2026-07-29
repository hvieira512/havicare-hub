import {
    clearDashboardApiToken,
    refreshAccessToken,
    setDashboardApiToken,
} from "../api/http.js";

const TOKEN_STORAGE_KEY = "hub-dashboard-api-token";
const LAST_ACTIVITY_STORAGE_KEY = "hub-dashboard-last-activity";
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

const renderAuthenticatedUsername = token => {
    const {authenticatedUsername} = elements();
    if (!authenticatedUsername) return;
    authenticatedUsername.textContent = String(token?.username || "Administrador");
};

const validAdminToken = token => {
    if (!token || typeof token !== "object" || token.role !== ADMIN_ROLE) {
        return false;
    }

    const accessToken = String(token.access_token || "");
    const refreshToken = String(token.refresh_token || "");
    const refreshExpiresAt = Date.parse(String(token.refresh_expires_at || ""));
    return accessToken !== ""
        && refreshToken !== ""
        && Number.isFinite(refreshExpiresAt)
        && refreshExpiresAt > Date.now();
};

const storeToken = token => {
    if (!validAdminToken(token)) {
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        return;
    }
    sessionStorage.setItem(TOKEN_STORAGE_KEY, JSON.stringify(token));
};

const restoreToken = () => {
    try {
        const token = JSON.parse(sessionStorage.getItem(TOKEN_STORAGE_KEY) || "null");
        return validAdminToken(token) ? token : null;
    } catch {
        return null;
    }
};

const clearTimers = () => {
    [warningTimer, logoutTimer].forEach(timer => {
        if (timer !== null) {
            window.clearTimeout(timer);
        }
    });
    warningTimer = null;
    logoutTimer = null;
};

const showTimeoutWarning = () => {
    if (warningVisible || !window.hubDashboardApiToken?.access_token) {
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
    }).then(result => {
        warningVisible = false;
        if (result.isConfirmed) {
            registerActivity(true);
            return;
        }
        if (result.dismiss === Swal.DismissReason.timer) {
            logout("A sessão terminou por inatividade. Inicie sessão novamente.");
        }
    });
};

const hideTimeoutWarning = () => {
    if (warningVisible) {
        warningVisible = false;
        Swal.close();
    }
};

const showToast = (type, message) => {
    void Swal.fire({
        toast: true,
        position: "top-end",
        icon: type === "danger" ? "error" : type,
        title: message,
        showConfirmButton: false,
        showCloseButton: true,
        timer: 1800,
        timerProgressBar: true,
    });
};

const closeDashboardOverlays = () => {
    document.querySelectorAll("#dashboardApp .modal.show").forEach(modal => {
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
        .forEach(backdrop => backdrop.remove());
    document.body.classList.remove("modal-open");
    document.body.style.removeProperty("overflow");
    document.body.style.removeProperty("padding-right");
};

const setLoginBusy = busy => {
    const {loginSubmit, loginSubmitLabel, loginSubmitLoading} = elements();
    if (loginSubmit) {
        loginSubmit.disabled = busy;
        loginSubmit.setAttribute("aria-busy", String(busy));
    }
    loginSubmitLabel?.classList.toggle("d-none", busy);
    loginSubmitLoading?.classList.toggle("d-none", !busy);
};

const showLogin = message => {
    const {app, login, loginForm, loginUsername} = elements();
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
    if (message !== "") {
        showToast("warning", message);
    }
    window.setTimeout(() => loginUsername?.focus(), 0);
};

const startDashboard = async () => {
    const {app, login} = elements();
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
        await onAuthenticated();
    }
};

const logout = (message = "") => {
    clearTimers();
    hideTimeoutWarning();
    sessionStorage.removeItem(TOKEN_STORAGE_KEY);
    sessionStorage.removeItem(LAST_ACTIVITY_STORAGE_KEY);
    clearDashboardApiToken();
    showLogin(message);
};

const scheduleIdleTimers = () => {
    clearTimers();
    const idleMs = Date.now() - lastActivityAt;
    if (idleMs >= LOGOUT_AFTER_MS) {
        logout("A sessão terminou por inatividade. Inicie sessão novamente.");
        return;
    }
    if (idleMs >= WARNING_AFTER_MS) {
        showTimeoutWarning();
    } else {
        warningTimer = window.setTimeout(
            showTimeoutWarning,
            WARNING_AFTER_MS - idleMs,
        );
    }
    logoutTimer = window.setTimeout(
        () => logout("A sessão terminou por inatividade. Inicie sessão novamente."),
        LOGOUT_AFTER_MS - idleMs,
    );
};

const registerActivity = (force = false) => {
    if (!window.hubDashboardApiToken?.access_token) return;
    if (warningVisible && !force) return;
    const now = Date.now();
    lastActivityAt = now;
    hideTimeoutWarning();
    scheduleIdleTimers();
    if (now - lastActivityWriteAt >= ACTIVITY_WRITE_THROTTLE_MS) {
        sessionStorage.setItem(LAST_ACTIVITY_STORAGE_KEY, String(now));
        lastActivityWriteAt = now;
    }
};

const bindActivityTracking = () => {
    ACTIVITY_EVENTS.forEach(eventName => {
        window.addEventListener(eventName, () => registerActivity(), {passive: true});
    });
    window.addEventListener("focus", () => registerActivity());
    document.addEventListener("visibilitychange", () => {
        if (
            document.visibilityState !== "visible"
            || !authRequired()
            || !window.hubDashboardApiToken?.access_token
        ) {
            return;
        }
        const idleMs = Date.now() - lastActivityAt;
        if (idleMs >= LOGOUT_AFTER_MS) {
            logout("A sessão terminou por inatividade. Inicie sessão novamente.");
        } else {
            scheduleIdleTimers();
        }
    });
};

const login = async event => {
    event.preventDefault();
    const {loginUsername, loginPassword} = elements();
    const username = String(loginUsername?.value || "").trim();
    const password = String(loginPassword?.value || "");
    if (username === "" || password === "") {
        showToast("danger", "Preencha o utilizador e a palavra-passe.");
        return;
    }

    setLoginBusy(true);
    try {
        const response = await fetch("/api/auth/login", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({username, password}),
        });
        const payload = await response.json();
        const token = payload?.token;
        if (!response.ok || !token?.access_token) {
            showToast("danger", "Utilizador ou palavra-passe inválidos.");
            return;
        }
        if (token.role !== ADMIN_ROLE) {
            showToast("danger", "Esta conta não tem permissões de administrador do Hub.");
            return;
        }

        setDashboardApiToken(token);
        renderAuthenticatedUsername(token);
        storeToken(token);
        lastActivityAt = Date.now();
        lastActivityWriteAt = lastActivityAt;
        sessionStorage.setItem(LAST_ACTIVITY_STORAGE_KEY, String(lastActivityAt));
        scheduleIdleTimers();
        showToast("success", "Autenticação concluída. Bem-vindo ao Hub.");
        await startDashboard();
    } catch {
        showToast("danger", "Não foi possível contactar o Hub. Volte a tentar.");
    } finally {
        setLoginBusy(false);
    }
};

const restoreSession = async () => {
    const token = restoreToken();
    if (!token) {
        showLogin("");
        return;
    }

    const storedActivity = Number(sessionStorage.getItem(LAST_ACTIVITY_STORAGE_KEY));
    lastActivityAt = Number.isFinite(storedActivity) && storedActivity > 0
        ? storedActivity
        : Date.now();
    if (Date.now() - lastActivityAt >= LOGOUT_AFTER_MS) {
        logout("A sessão terminou por inatividade. Inicie sessão novamente.");
        return;
    }

    setDashboardApiToken(token);
    renderAuthenticatedUsername(token);
    const expiresAt = Date.parse(String(token.expires_at || ""));
    const accessExpired = !Number.isFinite(expiresAt) || expiresAt <= Date.now();
    const usernameMissing = String(token.username || "").trim() === "";
    if (accessExpired || usernameMissing) {
        const refreshedToken = await refreshAccessToken();
        if (refreshedToken && refreshedToken.role === ADMIN_ROLE) {
            renderAuthenticatedUsername(refreshedToken);
        } else if (accessExpired) {
            logout("A sessão expirou. Inicie sessão novamente.");
            return;
        }
    }

    storeToken(window.hubDashboardApiToken);
    scheduleIdleTimers();
    await startDashboard();
};

export async function initializeDashboardSession(startAuthenticatedDashboard) {
    onAuthenticated = startAuthenticatedDashboard;
    const {loginForm, logoutButton} = elements();

    loginForm?.addEventListener("submit", login);
    logoutButton?.addEventListener("click", () => logout(""));
    window.addEventListener("hub-dashboard-api-token-updated", () => {
        storeToken(window.hubDashboardApiToken);
        renderAuthenticatedUsername(window.hubDashboardApiToken);
    });
    window.addEventListener("hub-dashboard-auth-required", () => {
        logout("A sessão expirou. Inicie sessão novamente.");
    });
    bindActivityTracking();

    if (!authRequired()) {
        renderAuthenticatedUsername(null);
        await startDashboard();
        return;
    }
    await restoreSession();
}
