import {
    blockDevice,
    deleteNotification,
    getNotifications,
    markNotificationsRead,
} from "./api/index.js";
import { isSignedIn } from "./api/http.js";
import { ago, when } from "./core/format.js";
import { countLabel } from "./core/i18n.js";
import { html } from "./core/html.js";
import { apiError, confirmDestructive, toast } from "./core/dialogs.js";
import { els, whileBusy, whileVisible } from "./core/dom.js";

const SPINNER = "<span class=\"spinner-border spinner-border-sm\" aria-hidden=\"true\"></span>";

const POLL_INTERVAL_MS = 15_000;

/** O título e o ícone de cada `type`; o identificador só se mostra quando é um dispositivo. */
const NOTIFICATION_TYPES = {
    device_not_authorized: {
        title: "Dispositivo não autorizado",
        icon: "fa-triangle-exclamation",
        showsDevice: true,
        count: (n) => countLabel(n, "tentativa", "tentativas"),
    },
    hub_unclean_restart: {
        title: "O hub reiniciou-se sozinho",
        icon: "fa-bolt",
        showsDevice: false,
        count: (n) => countLabel(n, "vez", "vezes"),
    },
};

const DEFAULT_NOTIFICATION_TYPE = {
    title: "Notificação",
    icon: "fa-triangle-exclamation",
    showsDevice: true,
    count: (n) => countLabel(n, "ocorrência", "ocorrências"),
};

const notificationType = (type) =>
    NOTIFICATION_TYPES[String(type || "")] || DEFAULT_NOTIFICATION_TYPE;

let elements = null;
let addDevice = null;
let openDenylist = null;
let notifications = [];

const renderBadge = (count) => {
    const normalized = Math.max(0, Number(count) || 0);
    elements.badge.textContent = normalized > 99 ? "99+" : String(normalized);
    elements.badge.classList.toggle("d-none", normalized === 0);
    elements.summary.textContent = normalized === 0
        ? ""
        : countLabel(normalized, "não lida", "não lidas");
};

function restartDetails(reason) {
    if (!reason?.pid) return "O processo anterior terminou sem se desligar em condições";
    return `O processo ${reason.pid}, arrancado em ${when(reason.startedAt)}, terminou sem se desligar em condições`;
}

/** Uma notificação e o que se pode fazer com ela: registar à frente, e o vermelho no que cala o aparelho. */
export function notificationRow(notification) {
    const id = Number(notification.id) || 0;
    const attempts = Number(notification.occurrenceCount) || 1;
    const kind = notificationType(notification.type);
    // A licença ganha ao modelo: é o que falta a quem vai registar o dispositivo.
    const details = kind.showsDevice
        ? [
                notification.protocol,
                Number(notification.licenseId) > 0
                    ? `licença ${notification.licenseId}`
                    : notification.model || notification.ident,
            ].filter(Boolean).join(" · ")
        // Para o hub, a razão é a notícia: o processo que caiu e quando tinha arrancado.
        : restartDetails(notification.reason);
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
                    ${deviceLine}
                    ${detailsLine}
                    <span class="d-flex justify-content-between gap-2 small text-secondary mt-1">
                        <span>${kind.count(attempts)}</span>
                        <span>${ago(notification.lastSeenAt)}</span>
                    </span>
                    <div class="d-flex gap-2 mt-2">
                        ${deviceActions}
                        <button class="btn btn-sm btn-outline-secondary flex-shrink-0 ms-auto" type="button" data-notification-dismiss="${id}" title="Eliminar notificação" aria-label="Eliminar notificação">
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
}

/** O que o sino mostra; vazio, diz o que apareceria ali, e o rodapé leva aos bloqueados. */
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
    if (!isSignedIn()) {
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
    if (!isSignedIn()) {
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
    const result = await whileBusy(button, () => deleteNotification(id), SPINNER);
    if (!result) return;
    if (result.error) {
        toast(
            "error",
            "Não foi possível eliminar a notificação",
            apiError(result),
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
    const { isConfirmed } = await confirmDestructive(
        `Bloquear o dispositivo ${notification.imei}?`,
        "O que enviar deixa de ser aceite, e as notificações dele são apagadas.",
        "Bloquear",
    );
    if (!isConfirmed) return;

    const result = await whileBusy(
        button,
        () => blockDevice(notification.imei, notification.protocol || ""),
        SPINNER,
    );
    if (!result) return;
    if (result.error) {
        toast(
            "error",
            "Não foi possível bloquear o dispositivo",
            apiError(result),
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
        openDenylist();
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

export function initNotifications(context) {
    elements = {
        dropdown: els.dashboardNotificationsDropdown,
        badge: els.dashboardNotificationsBadge,
        summary: els.dashboardNotificationsSummary,
        list: els.dashboardNotificationsList,
    };
    addDevice = context.openAddDevice;
    openDenylist = context.openDenylist;

    elements.dropdown.addEventListener("shown.bs.dropdown", () => {
        void handleDropdownShown();
    });
    elements.list.addEventListener("click", handleNotificationClick);
    window.addEventListener("hub-dashboard-api-token-updated", () => {
        void load();
    });
    whileVisible(() => void refreshBadge(), POLL_INTERVAL_MS);
    void load();
}
