import test from "node:test";
import assert from "node:assert/strict";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

/**
 * Com o separador escondido não se sonda, e ao voltar relê-se de imediato, como no
 * `devices/stream.js`.
 */
let hidden = false;
Object.defineProperty(document, "hidden", { configurable: true, get: () => hidden });

const requests = [];
globalThis.fetch = async (url) => {
    requests.push(String(url));
    return Response.json({});
};

/**
 * Fica com o que o código agendou em vez de deixar um temporizador a sério de pé, e repõe o
 * `setInterval` do anfitrião a seguir.
 */
const capturedInterval = (host, register) => {
    const ticks = [];
    const original = host.setInterval;
    host.setInterval = (callback) => {
        ticks.push(callback);
        return ticks.length;
    };
    try {
        register();
    } finally {
        host.setInterval = original;
    }
    return ticks;
};

const goHidden = () => {
    hidden = true;
    document.dispatchEvent(new Event("visibilitychange"));
};

const goVisible = () => {
    hidden = false;
    document.dispatchEvent(new Event("visibilitychange"));
};

/** Espera que as promessas já agendadas drenem: o pedido sai de dentro de um `async`. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const divWithId = (id) => {
    const element = document.createElement("div");
    element.id = id;
    document.body.appendChild(element);
    return element;
};

const { initNotifications } = await import("../../src/Dashboard/dashboard/notifications.js");
const { startSelectedDevicePolling } = await import("../../src/Dashboard/dashboard/app.js");
const { state } = await import("../../src/Dashboard/dashboard/state.js");

const notificationTicks = capturedInterval(window, () => {
    cacheElements({
        dashboardNotificationsDropdown: divWithId("dashboardNotificationsDropdown"),
        dashboardNotificationsBadge: divWithId("dashboardNotificationsBadge"),
        dashboardNotificationsSummary: divWithId("dashboardNotificationsSummary"),
        dashboardNotificationsList: divWithId("dashboardNotificationsList"),
    });
    initNotifications({ openAddDevice: () => {}, openDenylist: () => {} });
});

state.selectedImei = "351266770073676";
const deviceTicks = capturedInterval(globalThis, () => startSelectedDevicePolling());

const tickAll = async (ticks) => {
    ticks.forEach((tick) => tick());
    await settle();
};

test("a hidden tab does not poll notifications", async () => {
    goHidden();
    requests.length = 0;

    await tickAll(notificationTicks);

    assert.deepEqual(requests, [], "com a aba escondida o tique não pede nada");
});

test("returning to the tab reloads notifications at once", async () => {
    goHidden();
    requests.length = 0;

    goVisible();
    await settle();

    assert.ok(
        requests.some((url) => url.startsWith("/api/notifications")),
        "não se espera pelos 15 segundos seguintes",
    );
});

test("with the tab visible the notifications tick requests", async () => {
    goVisible();
    await settle();
    requests.length = 0;

    await tickAll(notificationTicks);

    assert.ok(requests.some((url) => url.startsWith("/api/notifications")));
});

test("a hidden tab does not reload the selected device", async () => {
    goHidden();
    requests.length = 0;

    await tickAll(deviceTicks);

    assert.deepEqual(requests, []);
});

test("returning to the tab reloads the selected device at once", async () => {
    goHidden();
    requests.length = 0;

    goVisible();
    await settle();

    assert.ok(
        requests.some((url) => url.includes("/api/devices/351266770073676")),
        "o dispositivo no ecrã não pode ficar meio minuto a mostrar o estado de antes",
    );
});

test("with the tab visible the device tick requests", async () => {
    goVisible();
    await settle();
    requests.length = 0;

    await tickAll(deviceTicks);

    assert.ok(requests.some((url) => url.includes("/api/devices/351266770073676")));
});
