import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

/**
 * Dois temporizadores sondavam o servidor com o separador escondido: as notificações de 15 em
 * 15 segundos e o dispositivo escolhido de 30 em 30. Numa dashboard deixada aberta o dia todo
 * num separador de fundo são centenas de pedidos por nada.
 *
 * O padrão é o do `devices/stream.js`, que fecha o stream no `visibilitychange` e volta a
 * ligar ao regressar: escondido não se sonda, e ao voltar relê-se de imediato -- esperar pelo
 * tique seguinte deixava até meio minuto de dados velhos no ecrã, que é pior do que o próprio
 * polling.
 */
let hidden = false;
Object.defineProperty(document, "hidden", { configurable: true, get: () => hidden });

const requests = [];
globalThis.fetch = async (url) => {
    requests.push(String(url));
    return { ok: true, status: 200, text: async () => "{}" };
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
    initNotifications({
        els: {
            dashboardNotificationsDropdown: divWithId("dashboardNotificationsDropdown"),
            dashboardNotificationsBadge: divWithId("dashboardNotificationsBadge"),
            dashboardNotificationsSummary: divWithId("dashboardNotificationsSummary"),
            dashboardNotificationsList: divWithId("dashboardNotificationsList"),
        },
        openAddDevice: () => {},
    });
});

state.selectedImei = "351266770073676";
const deviceTicks = capturedInterval(globalThis, () => startSelectedDevicePolling());

const tickAll = async (ticks) => {
    ticks.forEach((tick) => tick());
    await settle();
};

test("o separador escondido não sonda as notificações", async () => {
    goHidden();
    requests.length = 0;

    await tickAll(notificationTicks);

    assert.deepEqual(requests, [], "com a aba escondida o tique não pede nada");
});

test("voltar ao separador relê as notificações de imediato", async () => {
    goHidden();
    requests.length = 0;

    goVisible();
    await settle();

    assert.ok(
        requests.some((url) => url.startsWith("/api/notifications")),
        "não se espera pelos 15 segundos seguintes",
    );
});

test("com o separador à vista o tique das notificações pede", async () => {
    goVisible();
    await settle();
    requests.length = 0;

    await tickAll(notificationTicks);

    assert.ok(requests.some((url) => url.startsWith("/api/notifications")));
});

test("o separador escondido não relê o dispositivo escolhido", async () => {
    goHidden();
    requests.length = 0;

    await tickAll(deviceTicks);

    assert.deepEqual(requests, []);
});

test("voltar ao separador relê o dispositivo escolhido de imediato", async () => {
    goHidden();
    requests.length = 0;

    goVisible();
    await settle();

    assert.ok(
        requests.some((url) => url.includes("/api/devices/351266770073676")),
        "o dispositivo no ecrã não pode ficar meio minuto a mostrar o estado de antes",
    );
});

test("com o separador à vista o tique do dispositivo pede", async () => {
    goVisible();
    await settle();
    requests.length = 0;

    await tickAll(deviceTicks);

    assert.ok(requests.some((url) => url.includes("/api/devices/351266770073676")));
});
