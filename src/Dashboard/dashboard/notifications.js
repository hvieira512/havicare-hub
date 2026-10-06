import {
    blockDevice,
    deleteNotification,
    getDashboardApiToken,
    getNotifications,
    markNotificationsRead,
} from "./api/index.js";
import { ago } from "./format.js";
import { html, raw } from "./html.js";
import { confirmDestructive, toast } from "./dialogs.js";
import { loadSettingsModal } from "./settings/index.js";

const POLL_INTERVAL_MS = 15_000;

/**
 * Cada `type` traz o seu título e o seu ícone. O identificador só se mostra quando é de facto
 * um dispositivo; para o hub, o que interessa é a razão.
 */
const NOTIFICATION_TYPES = {
    device_not_authorized: {
        title: "Dispositivo não autorizado",
        icon: "fa-triangle-exclamation",
        showsDevice: true,
        count: (n) => (n > 1 ? `${n} tentativas` : "1 tentativa"),
    },
    hub_unclean_restart: {
        title: "O hub reiniciou-se sozinho",
        icon: "fa-bolt",
        showsDevice: false,
        count: (n) => (n > 1 ? `${n} vezes` : "1 vez"),
    },
};

const DEFAULT_NOTIFICATION_TYPE = {
    title: "Notificação",
    icon: "fa-triangle-exclamation",
    showsDevice: true,
    count: (n) => (n > 1 ? `${n} ocorrências` : "1 ocorrência"),
};

const notificationType = (type) =>
    NOTIFICATION_TYPES[String(type || "")] || DEFAULT_NOTIFICATION_TYPE;

let initialized = false;
let elements = null;
let addDevice = null;
let notifications = [];

const renderBadge = (count) => {
    const normalized = Math.max(0, Number(count) || 0);
    elements.badge.textContent = normalized > 99 ? "99+" : String(normalized);
    elements.badge.classList.toggle("d-none", normalized === 0);
    elements.summary.textContent = normalized === 0
        ? ""
        : `${normalized} ${normalized === 1 ? "não lida" : "não lidas"}`;
};

/**
 * Uma notificação e o que se pode fazer com ela, por ordem: registar é a saída normal e leva
 * nome escrito; o vermelho está no que cala o aparelho de vez.
 */
export function notificationRow(notification) {
    const id = Number(notification.id) || 0;
    const attempts = Number(notification.occurrenceCount) || 1;
    const kind = notificationType(notification.type);
    // A licença ganha ao modelo e à identidade: quando o hub a sabe, é a informação que
    // falta a quem vai registar o dispositivo. A identidade já é a linha de cima.
    const details = kind.showsDevice
        ? [
                notification.protocol,
                Number(notification.licenseId) > 0
                    ? `licença ${notification.licenseId}`
                    : notification.model || notification.ident,
            ].filter(Boolean).join(" · ")
        // Para o hub, a razão é a notícia: diz qual foi o processo que caiu e quando
        // tinha arrancado.
        : String(notification.reason || "");
    const unreadClass = notification.readAt
        ? ""
        : " list-group-item-primary";
    const detailsLine = details === ""
        ? ""
        : html`<span class="d-block small text-secondary text-break">${details}</span>`;
    const deviceLine = kind.showsDevice
        ? html`<span class="d-block font-monospace small text-truncate" title="${notification.imei}">${notification.imei}</span>`
        : "";
    // Só um aparelho com identidade se regista ou se cala; um aviso do próprio hub dispensa-se.
    const deviceActions = kind.showsDevice
        ? html`<button class="btn btn-sm btn-outline-primary flex-shrink-0" type="button" data-notification-register="${id}" title="Registar dispositivo">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Registar
                </button>
                <button class="btn btn-sm btn-outline-danger btn-quiet-danger flex-shrink-0" type="button" data-notification-block="${id}" title="Bloquear dispositivo" aria-label="Bloquear dispositivo">
                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                </button>`
        : "";

    return html`
        <div class="list-group-item px-3 py-3${unreadClass}">
            <div class="d-flex align-items-start gap-2">
                <i class="fa-solid ${kind.icon} text-danger mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div class="min-w-0 flex-grow-1">
                    <span class="d-block fw-semibold">${kind.title}</span>
                    ${raw(deviceLine)}
                    ${raw(detailsLine)}
                    <span class="d-flex justify-content-between gap-2 small text-secondary mt-1">
                        <span>${kind.count(attempts)}</span>
                        <span>${ago(notification.lastSeenAt)}</span>
                    </span>
                    <div class="d-flex gap-2 mt-2">
                        ${raw(deviceActions)}
                        <button class="btn btn-sm btn-outline-secondary flex-shrink-0 ms-auto" type="button" data-notification-dismiss="${id}" title="Eliminar notificação" aria-label="Eliminar notificação">
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
}

/**
 * O que o sino mostra. Vazio, diz o que apareceria ali em vez de só dizer que não há nada; e
 * o rodapé leva sempre ao sítio onde o que se bloqueou vai parar.
 */
export function notificationsPanel(rows) {
    const empty = html`
        <div class="list-group-item text-center px-3 py-4">
            <i class="fa-solid fa-bell-slash fa-lg text-secondary mb-2" aria-hidden="true"></i>
            <span class="d-block">Nada por ver</span>
            <span class="d-block small text-secondary">Aparecem aqui os aparelhos não autorizados e os reinícios do hub.</span>
        </div>`;
    const footer = html`
        <button class="list-group-item list-group-item-action position-sticky bottom-0 bg-body border-top small text-start" type="button" data-notification-denylist>
            Ver bloqueados <i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>
        </button>`;

    return (rows.length ? rows.map(notificationRow).join("") : empty) + footer;
}

const render = () => {
    elements.list.innerHTML = notificationsPanel(notifications);
};

const load = async () => {
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !getDashboardApiToken()?.access_token
    ) {
        return false;
    }

    const result = await getNotifications(20);
    if (result?.error) {
        if (notifications.length === 0) {
            elements.list.innerHTML =
                "<div class=\"list-group-item text-center text-danger small p-4\">Não foi possível carregar as notificações.</div>";
        }
        return false;
    }

    notifications = Array.isArray(result?.data) ? result.data : [];
    render();
    renderBadge(result?.unreadCount);
    return true;
};

/** O poll de fundo só pede a contagem para a pastilha; a lista refaz-se quando o menu abre. */
const refreshBadge = async () => {
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !getDashboardApiToken()?.access_token
    ) {
        return;
    }
    const result = await getNotifications(1);
    if (!result?.error) {
        renderBadge(result?.unreadCount);
    }
};

const handleDropdownShown = async () => {
    if (!await load()) {
        return;
    }

    const unreadIds = notifications
        .filter((notification) => !notification.readAt)
        .map((notification) => Number(notification.id))
        .filter((id) => Number.isInteger(id) && id > 0);
    if (unreadIds.length === 0) {
        return;
    }

    const result = await markNotificationsRead(unreadIds);
    if (result?.error) {
        return;
    }

    const readAt = new Date().toISOString();
    notifications = notifications.map((notification) =>
        unreadIds.includes(Number(notification.id))
            ? { ...notification, readAt }
            : notification,
    );
    render();
    renderBadge(result?.unreadCount);
};

const dismissNotification = async (id, button) => {
    button.disabled = true;
    button.innerHTML = "<span class=\"spinner-border spinner-border-sm\" aria-hidden=\"true\"></span>";
    const result = await deleteNotification(id);
    if (result?.error) {
        button.disabled = false;
        button.innerHTML = "<i class=\"fa-solid fa-trash-can\" aria-hidden=\"true\"></i>";
        toast(
            "error",
            "Não foi possível eliminar a notificação",
            result.error.message || "Por favor, volte a tentar.",
        );
        return;
    }

    notifications = notifications.filter(
        (notification) => Number(notification.id) !== id,
    );
    render();
    renderBadge(result?.unreadCount);
};

const blockDeviceAction = async (notification, button) => {
    // O botão está a um clique do «dispensar», e o que faz não se parece nada com ele.
    const { isConfirmed } = await confirmDestructive(
        `Bloquear o dispositivo ${notification.imei}?`,
        "O que enviar deixa de ser aceite, e as notificações dele são apagadas.",
        "Bloquear",
    );
    if (!isConfirmed) return;

    button.disabled = true;
    const original = button.innerHTML;
    button.innerHTML = "<span class=\"spinner-border spinner-border-sm\" aria-hidden=\"true\"></span>";
    const result = await blockDevice(notification.imei, notification.protocol || "");
    if (result?.error) {
        button.disabled = false;
        button.innerHTML = original;
        toast(
            "error",
            "Não foi possível bloquear o dispositivo",
            result.error.message || "Por favor, volte a tentar.",
        );
        return;
    }

    // O bloqueio limpou as notificações deste aparelho no servidor; recarregar reflete-o.
    await load();
};

const closeDropdown = () => {
    bootstrap.Dropdown.getOrCreateInstance(
        elements.dropdown.querySelector("[data-bs-toggle=\"dropdown\"]"),
    ).hide();
};

const registerDeviceAction = (notification) => {
    closeDropdown();
    void addDevice(notification);
};

const handleNotificationClick = (event) => {
    const button = event.target.closest(
        "[data-notification-dismiss], [data-notification-block], [data-notification-register], [data-notification-denylist]",
    );
    if (!button) return;

    event.preventDefault();
    event.stopPropagation();

    if (button.dataset.notificationDenylist !== undefined) {
        closeDropdown();
        void loadSettingsModal("denylist");
        return;
    }

    const { notificationDismiss, notificationBlock, notificationRegister } = button.dataset;
    const id = Number(notificationDismiss ?? notificationBlock ?? notificationRegister);
    if (!Number.isInteger(id) || id <= 0) return;

    if (notificationDismiss !== undefined) {
        void dismissNotification(id, button);
        return;
    }

    const notification = notifications.find(
        (candidate) => Number(candidate.id) === id,
    );
    if (!notification) return;

    if (notificationBlock !== undefined) void blockDeviceAction(notification, button);
    else registerDeviceAction(notification);
};

export function initNotifications({ els, openAddDevice }) {
    elements = {
        dropdown: els.dashboardNotificationsDropdown,
        badge: els.dashboardNotificationsBadge,
        summary: els.dashboardNotificationsSummary,
        list: els.dashboardNotificationsList,
    };
    addDevice = openAddDevice;

    if (initialized) {
        void load();
        return;
    }
    initialized = true;

    elements.dropdown.addEventListener("shown.bs.dropdown", () => {
        void handleDropdownShown();
    });
    elements.list.addEventListener("click", handleNotificationClick);
    window.addEventListener("hub-dashboard-api-token-updated", () => {
        void load();
    });
    // Um separador escondido não sonda, como o `devices/stream.js`, e ao voltar relê-se já.
    const refreshBadgeWhenVisible = () => {
        if (!document.hidden) {
            void refreshBadge();
        }
    };
    window.setInterval(refreshBadgeWhenVisible, POLL_INTERVAL_MS);
    document.addEventListener("visibilitychange", refreshBadgeWhenVisible);
    void load();
}
