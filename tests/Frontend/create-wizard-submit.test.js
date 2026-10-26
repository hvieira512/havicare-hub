import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/core/state.js");
const { initDeviceList } = await import("../../src/Dashboard/dashboard/devices/list.js");
const { initCreateWizard, createDeviceFromWizard } =
    await import("../../src/Dashboard/dashboard/devices/create-wizard.js");

/**
 * Criar um dispositivo retransmitido é escrever o dispositivo e depois autorizar cada gateway;
 * se a segunda falhar, o dispositivo já existe e reenviar só daria 409.
 */
const els = autoEls();

let hidden;
let warnings;

const answersFor = (gateways) => ({
    type: "diaper_sensor",
    identity: "eec5000202f9",
    model: { supplier: "MONIT", model: "MECS-PRO" },
    owner: { company: "hitcare", licenseId: "1001" },
    gateways,
});

const jsonResponse = (status, payload) => Response.json(payload, { status });

const respondWith = (perUrl) => {
    globalThis.fetch = async (url) => perUrl(String(url));
};

const emptyListing = () => jsonResponse(200, {
    data: [],
    pagination: { page: 1, totalPages: 1, total: 0, limit: 20 },
});

beforeEach(() => {
    hidden = 0;
    warnings = [];
    // O `toast` fala com o SweetAlert, que não existe fora do browser.
    globalThis.Swal = {
        fire: (options) => {
            warnings.push(options);
            return Promise.resolve({});
        },
    };
    state.selectedImei = "";
    state.selectedDetail = null;
    cacheElements(els);
    initDeviceList({ ui: {} });
    const wizardModal = {
        hide: () => {
            hidden += 1;
        },
        show: () => {},
    };
    cacheElements(els);
    initCreateWizard({ wizardModal });
});

test("creating without gateways closes the wizard and returns no error", async () => {
    respondWith((url) => (url.startsWith("/api/devices?") || url === "/api/devices"
        ? emptyListing()
        : jsonResponse(200, { status: "ok" })));

    const error = await createDeviceFromWizard(answersFor([]));

    assert.equal(error, null);
    assert.equal(hidden, 1, "o assistente fecha-se");
});

/** O dispositivo ficou criado: oferecer «Criar» outra vez só daria 409. */
test("a gateway that fails to authorize does not leave the wizard open", async () => {
    respondWith((url) => {
        if (url.includes("/links")) return jsonResponse(500, { error: { code: "gateway_gone" } });
        if (url.startsWith("/api/devices?") || url === "/api/devices") return emptyListing();
        return jsonResponse(200, { status: "ok" });
    });

    const error = await createDeviceFromWizard(answersFor(["c5e390f30bce"]));

    assert.equal(error, null, "não há erro a mostrar no assistente: ele fecha-se");
    assert.equal(hidden, 1, "o dispositivo existe, por isso o assistente fecha-se");
});

test("the gateway left unauthorized is reported, by name", async () => {
    respondWith((url) => {
        if (url.includes("/links")) return jsonResponse(500, { error: { code: "gateway_gone" } });
        if (url.startsWith("/api/devices?") || url === "/api/devices") return emptyListing();
        return jsonResponse(200, { status: "ok" });
    });

    await createDeviceFromWizard(answersFor(["c5e390f30bce", "d48c49f7909c"]));

    assert.equal(warnings.length, 1, "quem criou tem de saber o que ficou por fazer");
    const said = `${warnings[0].titleText} ${warnings[0].text}`;
    assert.match(said, /c5e390f30bce/);
    assert.match(said, /d48c49f7909c/, "os dois que falharam, e não só o primeiro");
});

/** Uma criação recusada é outra coisa: não há nada feito, e o assistente fica para se corrigir. */
test("if creation fails the wizard stays open with the error", async () => {
    respondWith(() => jsonResponse(409, { error: { code: "device_exists", message: "Device with this IMEI already exists" } }));

    const error = await createDeviceFromWizard(answersFor([]));

    assert.match(String(error), /Já existe/);
    assert.equal(hidden, 0, "não há nada criado: fica onde está");
});
