import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

/**
 * O `Swal.fire()` devolve uma promessa, e cancelar não pode chegar à API. A caixa diz também o
 * que se apaga: «Apagar licença?» não diz qual.
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
    return Promise.resolve(Response.json({ error: { code: "boom" } }, { status: 500 }));
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

test("cancelling the confirmation never calls the API", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.deepEqual(calls, []);
});

test("confirming deletes, and deletes what was asked", async () => {
    reset();
    answer = { isConfirmed: true };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.deepEqual(calls, [{ url: "/api/users/7", method: "DELETE" }]);
});

test("the delete-user box says which user", async () => {
    reset();
    answer = { isConfirmed: false, dismiss: "cancel" };

    await deleteApiUser({ id: 7, username: "hitcare" });

    assert.match(confirmations[0].titleText, /hitcare/);
});

test("the delete-licence box says the number, the name and what is left behind", () => {
    const prompt = licenseDeletePrompt({ license_id: 1001, name: "gucc.dev" }, 12);

    assert.match(prompt.title, /1001/);
    assert.match(prompt.title, /gucc\.dev/);
    assert.match(prompt.text, /12 dispositivos/);
});

test("a licence without devices says so instead of counting zero", () => {
    const prompt = licenseDeletePrompt({ license_id: 1001, name: "gucc.dev" }, 0);

    assert.doesNotMatch(prompt.text, /0 dispositivos/);
});

test("the delete-company box says the name and the licences it takes along", () => {
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

test("irreversible commands get a box, harmless ones do not", () => {
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

test("the factory reset box does not promise a restart", () => {
    const section = sectionFor({
        key: "reset_device",
        label: "Reposição de fábrica",
        confirm: "Repõe o relógio ao estado de fábrica. Volta a apontar para o servidor do fornecedor e o hub deixa de o comandar até alguém de lá o voltar a configurar.",
    });

    const prompt = dangerousCommandPrompt(section, "868705080304889");

    assert.match(prompt.text, /fábrica/);
    assert.doesNotMatch(prompt.text, /arranca/);
});

test("a destructive card carries the dictionary's sentence for its protocol", () => {
    const html = renderConfigSection("wonlex-json", {
        key: "factoryResetCommand",
        capabilityKey: "reset_device",
        label: "Reposição de fábrica",
        input: "action",
        fields: [],
        transient: true,
        risk: "destructive",
    }, null);

    assert.match(html, /data-config-confirm="Repõe o relógio ao estado de fábrica\./);
    assert.match(html, /data-config-label="Reposição de fábrica"/);
});

test("a setting without a sentence gets no confirmation attribute", () => {
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

test("cancelling the power-off box does not send the command to the watch", async () => {
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

test("a device delete the API refuses keeps the modal open and says why", async () => {
    reset();
    answer = { isConfirmed: true };
    const { handleDeleteDeviceBtnClick, initDeviceModal } = await import(
        "../../src/Dashboard/dashboard/devices/device-modal.js",
    );
    const els = autoEls();
    els.deleteDeviceBtn.dataset.imei = "351266770073676";
    let hidden = false;
    initDeviceModal({
        els,
        deviceModal: {
            hide: () => {
                hidden = true;
            },
        },
    });

    await handleDeleteDeviceBtnClick();

    assert.equal(hidden, false);
    assert.notEqual(state.deviceModal.errorMessage, "");
});

test("a model delete the API refuses leaves the model in the catalog", async () => {
    reset();
    answer = { isConfirmed: true };
    const { initSettingsModels } = await import(
        "../../src/Dashboard/dashboard/settings/models/shell.js",
    );
    const { deleteCurrentModel } = await import(
        "../../src/Dashboard/dashboard/settings/models/detail.js",
    );
    initSettingsModels({ els: autoEls(), ui: {} });
    const model = { id: 92, commercialName: "R03" };
    state.settingsModal.currentCapabilitiesModel = model;
    state.settingsModal.modelCatalog = [{ suppliers: [{ models: [model] }] }];

    await deleteCurrentModel();

    assert.deepEqual(state.settingsModal.modelCatalog[0].suppliers[0].models, [model]);
    assert.equal(state.settingsModal.currentCapabilitiesModel, model);
});
