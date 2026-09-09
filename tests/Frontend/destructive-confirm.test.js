import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

/**
 * O `confirm()` do browser devolvia um booleano de imediato; o `Swal.fire()` devolve uma
 * promessa. Trocar um pelo outro sem esperar transforma um apagar guardado num apagar
 * directo, e é isso que estes testes trancam: cancelar não pode chegar à API.
 *
 * A segunda metade tranca o que a caixa diz. «Apagar licença?» não diz qual, e a pergunta
 * fica sem resposta possível para quem tem catorze; e desligar um relógio à distância não
 * tinha caixa nenhuma -- um clique enganado deixava o aparelho apagado até alguém lhe chegar
 * ao botão.
 */
const calls = [];
const confirmations = [];
let answer = { isConfirmed: false };

globalThis.Swal = {
    fire: (options) => {
        confirmations.push(options);
        return Promise.resolve(answer);
    },
};
globalThis.fetch = (url, options) => {
    calls.push({ url, method: options?.method || "GET" });
    return Promise.resolve({
        ok: false,
        status: 500,
        text: () => Promise.resolve("{\"error\":{\"code\":\"boom\"}}"),
    });
};

const reset = () => {
    calls.length = 0;
    confirmations.length = 0;
};

const { deleteApiUser } = await import(
    "../../src/Dashboard/dashboard/settings/api-users.js",
);
const { companyDeletePrompt, licenseDeletePrompt } = await import(
    "../../src/Dashboard/dashboard/settings/companies.js",
);
const { dangerousCommandPrompt, saveDeviceConfiguration } = await import(
    "../../src/Dashboard/dashboard/devices/config/panel.js",
);
const { state } = await import("../../src/Dashboard/dashboard/state.js");

test("cancelar a confirmação não chega a chamar a API", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.deepEqual(calls, []);
});

test("confirmar apaga, e apaga o que se pediu", async () => {
    reset();
    answer = { isConfirmed: true };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.deepEqual(calls, [{ url: "/api/users/7", method: "DELETE" }]);
});

test("a caixa de apagar um utilizador diz qual é", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.match(confirmations[0].titleText, /hitcare/);
});

test("a caixa de apagar uma licença diz o número, o nome e o que fica para trás", () => {
    const prompt = licenseDeletePrompt({ license_id: 1001, name: "gucc.dev" }, 12);

    assert.match(prompt.title, /1001/);
    assert.match(prompt.title, /gucc\.dev/);
    assert.match(prompt.text, /12 dispositivos/);
});

test("uma licença sem dispositivos di-lo em vez de contar zero", () => {
    const prompt = licenseDeletePrompt({ license_id: 1001, name: "gucc.dev" }, 0);

    assert.doesNotMatch(prompt.text, /0 dispositivos/);
});

test("a caixa de apagar uma empresa diz o nome e as licenças que leva com ela", () => {
    const prompt = companyDeletePrompt({ name: "Hitecosystem" }, 3, 41);

    assert.match(prompt.title, /Hitecosystem/);
    assert.match(prompt.text, /3 licenças/);
    assert.match(prompt.text, /41 dispositivos/);
});

test("os comandos que não se desfazem têm caixa, e os inofensivos não", () => {
    const imei = "351266770073676";

    assert.match(dangerousCommandPrompt("power_off", imei).title, /Desligar/);
    assert.match(dangerousCommandPrompt("power_off", imei).title, new RegExp(imei));
    assert.equal(dangerousCommandPrompt("power_off", imei).confirmText, "Desligar");
    assert.ok(dangerousCommandPrompt("reset_device", imei));
    assert.ok(dangerousCommandPrompt("restart_device", imei));
    assert.ok(dangerousCommandPrompt("monitor_number", imei));

    assert.equal(dangerousCommandPrompt("find_device", imei), null);
    assert.equal(dangerousCommandPrompt("center_number", imei), null);
});

test("cancelar a caixa de desligar não envia o comando ao relógio", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };
    state.deviceModal.imei = "351266770073676";

    const section = document.createElement("div");
    section.dataset.configSection = "settings_system";
    section.dataset.configKey = "power_off";
    section.dataset.capabilityKey = "power_off";
    section.dataset.configTransient = "1";

    await saveDeviceConfiguration(section, "1");

    assert.deepEqual(calls, []);
    assert.match(confirmations[0].titleText, /351266770073676/);
});
