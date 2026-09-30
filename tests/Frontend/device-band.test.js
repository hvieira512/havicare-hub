import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { setSelectedDetail, state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderSelection } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/**
 * A banda do telemóvel é o que sobra do cartão da identidade abaixo do `lg`: o IMEI, e por
 * baixo o estado, o modelo e a licença.
 */
function detailEls() {
    const tags = { telemetryColumnTab: "button", detailFilterType: "select" };
    return new Proxy({}, {
        get: (cache, id) => {
            cache[id] ??= document.createElement(tags[id] || "div");
            return cache[id];
        },
    });
}

const device = {
    imei: "351266770073676",
    online: true,
    company: "havicare",
    licenseId: 1,
    licenseName: "besenior.havicare",
};

const model = {
    supplier: "4P Touch",
    internalModel: "Y6M",
    deviceType: "watch",
};

function select(els, detail) {
    initDeviceDetailView({ els });
    setSelectedDetail(detail);
    state.selectedDetail.recent = { telemetry: [], events: [], commands: [] };
    renderSelection();
}

test("a banda diz o IMEI, o estado, o modelo e a licença do dispositivo escolhido", () => {
    const els = detailEls();

    select(els, { device, model, capabilities: {} });

    assert.equal(els.deviceBandTitle.textContent, "351266770073676");
    assert.equal(els.deviceBandMeta.textContent, "Ligado · Y6M · besenior.havicare");
    assert.equal(els.deviceBandDot.classList.contains("bg-success"), true);
    assert.equal(els.deviceBand.classList.contains("d-none"), false);
});

test("desligado e sem licença, a banda continua a dizer o que sabe", () => {
    const els = detailEls();

    select(els, {
        device: { ...device, online: false, company: "null", licenseId: 0, licenseName: "" },
        model,
        capabilities: {},
    });

    assert.equal(els.deviceBandMeta.textContent, "Desligado · Y6M · Sem licença");
    assert.equal(els.deviceBandDot.classList.contains("bg-success"), false);
});

test("sem dispositivo escolhido, a banda e os separadores saem", () => {
    const els = detailEls();
    initDeviceDetailView({ els });
    state.selectedDetail = null;

    renderSelection();

    assert.equal(els.deviceBand.classList.contains("d-none"), true);
    assert.equal(els.deviceTabs.classList.contains("d-none"), true);
});
