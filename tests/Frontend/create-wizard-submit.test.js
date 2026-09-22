import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList } = await import("../../src/Dashboard/dashboard/devices/list.js");
const { initCreateWizard, createDeviceFromWizard } =
    await import("../../src/Dashboard/dashboard/devices/create-wizard.js");

/**
 * Criar um dispositivo retransmitido são duas escritas em sequência: o dispositivo, e depois
 * a autorização de cada gateway escolhido. A segunda pode falhar com a primeira já feita.
 *
 * A partir daí o dispositivo **existe** e não há como o desfazer daqui. Devolver o erro sem
 * fechar deixava o assistente aberto com o botão activo: carregar outra vez reenviava a mesma
 * criação, o servidor recusava-a com 409, e a mensagem passava a «Já existe um dispositivo
 * com esta identidade» -- a contradizer a que estava no ecrã, e sem saída nenhuma.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

let hidden;
let warnings;

const answersFor = (gateways) => ({
    type: "diaper_sensor",
    identity: "eec5000202f9",
    model: { supplier: "MONIT", model: "MECS-PRO" },
    owner: { company: "hitcare", licenseId: "1001" },
    gateways,
});

/** O `toast` fala com o SweetAlert, que não existe fora do browser. */
const jsonResponse = (status, payload) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify(payload),
    headers: { get: () => "application/json" },
});

const respondWith = (perUrl) => {
    globalThis.fetch = async (url) => perUrl(String(url));
};

const emptyListing = jsonResponse(200, {
    data: [],
    pagination: { page: 1, total_pages: 1, total: 0, limit: 20 },
});

beforeEach(() => {
    hidden = 0;
    warnings = [];
    globalThis.Swal = {
        fire: (options) => {
            warnings.push(options);
            return Promise.resolve({});
        },
    };
    state.selectedImei = "";
    state.selectedDetail = null;
    initDeviceList({ els, ui: {}, onChange: () => {} });
    const wizardModal = {
        hide: () => {
            hidden += 1;
        },
        show: () => {},
    };
    initCreateWizard({ els, wizardModal });
});

test("criar sem gateways fecha o assistente e não devolve erro", async () => {
    respondWith((url) => (url.startsWith("/api/devices?") || url === "/api/devices"
        ? emptyListing
        : jsonResponse(200, { status: "ok" })));

    const error = await createDeviceFromWizard(answersFor([]));

    assert.equal(error, null);
    assert.equal(hidden, 1, "o assistente fecha-se");
});

/**
 * O dispositivo ficou criado: o assistente não pode continuar a oferecer «Criar», que só
 * podia dar 409 e uma segunda mensagem a contradizer a primeira.
 */
test("um gateway que falhe a autorizar não deixa o assistente aberto", async () => {
    respondWith((url) => {
        if (url.includes("/links")) return jsonResponse(500, { error: { code: "gateway_gone" } });
        if (url.startsWith("/api/devices?") || url === "/api/devices") return emptyListing;
        return jsonResponse(200, { status: "ok" });
    });

    const error = await createDeviceFromWizard(answersFor(["c5e390f30bce"]));

    assert.equal(error, null, "não há erro a mostrar no assistente: ele fecha-se");
    assert.equal(hidden, 1, "o dispositivo existe, por isso o assistente fecha-se");
});

test("o gateway que ficou por autorizar é dito, e nomeado", async () => {
    respondWith((url) => {
        if (url.includes("/links")) return jsonResponse(500, { error: { code: "gateway_gone" } });
        if (url.startsWith("/api/devices?") || url === "/api/devices") return emptyListing;
        return jsonResponse(200, { status: "ok" });
    });

    await createDeviceFromWizard(answersFor(["c5e390f30bce", "d48c49f7909c"]));

    assert.equal(warnings.length, 1, "quem criou tem de saber o que ficou por fazer");
    const said = `${warnings[0].titleText} ${warnings[0].text}`;
    assert.match(said, /c5e390f30bce/);
    assert.match(said, /d48c49f7909c/, "os dois que falharam, e não só o primeiro");
});

/** Uma criação recusada é outra coisa: não há nada feito, e o assistente fica para se corrigir. */
test("se a criação falhar, o assistente fica aberto com o erro", async () => {
    respondWith(() => jsonResponse(409, { error: { code: "conflict" } }));

    const error = await createDeviceFromWizard(answersFor([]));

    assert.match(String(error), /Já existe/);
    assert.equal(hidden, 0, "não há nada criado: fica onde está");
});
