import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

/**
 * O `confirm()` do browser devolvia um booleano de imediato; o `Swal.fire()` devolve uma
 * promessa. Trocar um pelo outro sem esperar transforma um apagar guardado num apagar
 * directo, e é isso que estes testes trancam: cancelar não pode chegar à API.
 *
 * A segunda metade tranca o que a caixa diz: «Apagar licença?» não diz qual, e quem tem
 * catorze não tem como responder.
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
const { renderConfigSection } = await import(
    "../../src/Dashboard/dashboard/devices/config/index.js",
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

/**
 * Uma secção como o painel a desenha. O que a caixa diz sai dos atributos que a definição
 * do protocolo lá pôs, e não de uma tabela indexada pela chave da capacidade.
 */
const sectionFor = ({ key, label, confirm = "" }) => {
    const section = document.createElement("div");
    section.dataset.configSection = "settings_system";
    section.dataset.configKey = key;
    section.dataset.capabilityKey = key;
    section.dataset.configLabel = label;
    section.dataset.configTransient = "1";
    if (confirm !== "") {
        section.dataset.configConfirm = confirm;
    }
    return section;
};

test("os comandos que não se desfazem têm caixa, e os inofensivos não", () => {
    const imei = "351266770073676";
    const powerOff = sectionFor({
        key: "power_off",
        label: "Desligar dispositivo",
        confirm: "O relógio desliga-se e só volta a ligar no botão do próprio aparelho.",
    });

    assert.match(dangerousCommandPrompt(powerOff, imei).title, /Desligar/);
    assert.match(dangerousCommandPrompt(powerOff, imei).title, new RegExp(imei));
    assert.equal(dangerousCommandPrompt(powerOff, imei).confirmText, "Desligar dispositivo");
    assert.match(dangerousCommandPrompt(powerOff, imei).text, /botão do próprio aparelho/);

    const findDevice = sectionFor({ key: "find_device", label: "Encontrar dispositivo" });
    assert.equal(dangerousCommandPrompt(findDevice, imei), null);
});

/**
 * O caso que motivou isto: a mesma capacidade `reset_device` é uma reposição de fábrica na
 * Wonlex e um reinício no 4P Touch, e a caixa escolhida pela chave prometia um reinício a
 * quem estava a devolver o relógio ao servidor do fornecedor.
 */
test("a caixa da reposição de fábrica não promete um reinício", () => {
    const section = sectionFor({
        key: "reset_device",
        label: "Reposição de fábrica",
        confirm: "Repõe o relógio ao estado de fábrica. Volta a apontar para o servidor do fornecedor e o hub deixa de o comandar até alguém de lá o voltar a configurar.",
    });

    const prompt = dangerousCommandPrompt(section, "868705080304889");

    assert.match(prompt.text, /fábrica/);
    assert.doesNotMatch(prompt.text, /arranca/);
});

test("o cartão leva consigo a frase que a definição declarou", () => {
    const html = renderConfigSection("wonlex-json", {
        key: "resetCommand",
        capabilityKey: "reset_device",
        label: "Reposição de fábrica",
        input: "action",
        fields: [],
        transient: true,
        confirm: "Repõe o relógio ao estado de fábrica.",
    }, null);

    assert.match(html, /data-config-confirm="Repõe o relógio ao estado de fábrica\."/);
    assert.match(html, /data-config-label="Reposição de fábrica"/);
});

test("uma definição sem frase não leva atributo de confirmação", () => {
    const html = renderConfigSection("wonlex-json", {
        key: "findDeviceCommand",
        capabilityKey: "find_device",
        label: "Encontrar dispositivo",
        input: "action",
        fields: [],
        transient: true,
    }, null);

    assert.doesNotMatch(html, /data-config-confirm/);
});

test("cancelar a caixa de desligar não envia o comando ao relógio", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };
    state.deviceModal.imei = "351266770073676";

    const section = sectionFor({
        key: "power_off",
        label: "Desligar dispositivo",
        confirm: "O relógio desliga-se e só volta a ligar no botão do próprio aparelho.",
    });

    await saveDeviceConfiguration(section, "1");

    assert.deepEqual(calls, []);
    assert.match(confirmations[0].titleText, /351266770073676/);
});
