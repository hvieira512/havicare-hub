import {
    deleteNotification,
    getNotifications,
    markNotificationsRead,
} from "./api/index.js";
import {ago, esc} from "./format.js";
import {toast} from "./dialogs.js";

const POLL_INTERVAL_MS = 15_000;

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

const render = () => {
    if (notifications.length === 0) {
        elements.list.innerHTML =
            '<div class="list-group-item text-center text-secondary small p-4">Sem notificações.</div>';
        return;
    }

    elements.list.innerHTML = notifications.map((notification) => {
        const attempts = Number(notification.occurrenceCount) || 1;
        // A licença ganha ao modelo e à identidade: quando o hub a sabe, é a informação que
        // falta a quem vai registar o dispositivo. A identidade já é a linha de cima.
        const details = [
            notification.protocol,
            Number(notification.licenseId) > 0
                ? `licença ${notification.licenseId}`
                : notification.model || notification.ident,
        ].filter(Boolean).map(esc).join(" · ");
        const unreadClass = notification.readAt
            ? ""
            : " list-group-item-primary";

        return `
            <div class="list-group-item px-3 py-3${unreadClass}">
                <div class="d-flex align-items-start gap-2">
                    <button class="btn border-0 bg-transparent text-start p-0 flex-grow-1 min-width-0" type="button" data-notification-id="${Number(notification.id) || 0}">
                        <span class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-danger mt-1" aria-hidden="true"></i>
                            <span class="min-width-0 flex-grow-1">
                                <span class="d-block fw-semibold">Dispositivo não autorizado</span>
                                <span class="d-block font-monospace small text-break">${esc(notification.imei)}</span>
                                ${details === "" ? "" : `<span class="d-block small text-secondary text-break">${details}</span>`}
                                <span class="d-flex justify-content-between gap-2 small text-secondary mt-1">
                                    <span>${attempts > 1 ? `${attempts} tentativas` : "1 tentativa"}</span>
                                    <span>${esc(ago(notification.lastSeenAt))}</span>
                                </span>
                            </span>
                        </span>
                    </button>
                    <button class="btn btn-sm btn-outline-danger flex-shrink-0" type="button" data-notification-dismiss="${Number(notification.id) || 0}" title="Eliminar notificação" aria-label="Eliminar notificação">
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    </button>
                </div>
            </div>`;
    }).join("");
};

const load = async () => {
    if (
        document.body.dataset.dashboardAuthRequired === "true"
        && !window.hubDashboardApiToken?.access_token
    ) {
        return false;
    }

    const result = await getNotifications(20);
    if (result?.error) {
        if (notifications.length === 0) {
            elements.list.innerHTML =
                '<div class="list-group-item text-center text-danger small p-4">Não foi possível carregar as notificações.</div>';
        }
        return false;
    }

    notifications = Array.isArray(result?.data) ? result.data : [];
    render();
    renderBadge(result?.unreadCount);
    return true;
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
            ? {...notification, readAt}
            : notification
    );
    render();
    renderBadge(result?.unreadCount);
};

const dismissNotification = async (id, button) => {
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
    const result = await deleteNotification(id);
    if (result?.error) {
        button.disabled = false;
        button.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
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

const handleNotificationClick = (event) => {
    const dismissButton = event.target.closest("[data-notification-dismiss]");
    if (dismissButton) {
        event.preventDefault();
        event.stopPropagation();
        const id = Number(dismissButton.dataset.notificationDismiss);
        if (Number.isInteger(id) && id > 0) {
            void dismissNotification(id, dismissButton);
        }
        return;
    }

    const item = event.target.closest("[data-notification-id]");
    if (!item) {
        return;
    }

    const notification = notifications.find(
        (candidate) => Number(candidate.id) === Number(item.dataset.notificationId),
    );
    if (!notification) {
        return;
    }

    bootstrap.Dropdown.getOrCreateInstance(
        elements.dropdown.querySelector('[data-bs-toggle="dropdown"]'),
    ).hide();
    void addDevice(notification);
};

export function initNotifications({els, openAddDevice}) {
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
    window.setInterval(() => {
        void load();
    }, POLL_INTERVAL_MS);
    void load();
}
