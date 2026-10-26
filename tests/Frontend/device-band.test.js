import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { setSelectedDetail, state } = await import("../../src/Dashboard/dashboard/state.js");
const { renderSelection } = await import(
    "../../src/Dashboard/dashboard/devices/detail/detail.js",
);

/**
 * A banda do telemóvel é o que sobra do cartão da identidade abaixo do `lg`: o IMEI, e por
 * baixo o estado, o modelo e a licença.
 */
function detailEls() {
    const tags = { telemetryColumnTab: "button", detailFilterType: "select" };
    return autoEls((name) => tags[name] || "div");
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
    cacheElements(els);
    setSelectedDetail(detail);
    state.selectedDetail.recent = { telemetry: [], events: [], commands: [] };
    renderSelection();
}

test("the band says the IMEI, status, model and licence of the chosen device", () => {
    const els = detailEls();

    select(els, { device, model, capabilities: {} });

    assert.equal(els.deviceBandTitle.textContent, "351266770073676");
    assert.equal(els.deviceBandMeta.textContent, "Y6M · besenior.havicare");
    assert.equal(els.deviceBandDot.getAttribute("aria-label"), "Ligado");
    assert.equal(els.deviceBand.classList.contains("d-none"), false);
});

test("offline and without a licence, the band still says what it knows", () => {
    const els = detailEls();

    select(els, {
        device: { ...device, online: false, company: "null", licenseId: 0, licenseName: "" },
        model,
        capabilities: {},
    });

    assert.equal(els.deviceBandMeta.textContent, "Y6M · Sem licença");
    assert.equal(els.deviceBandDot.getAttribute("aria-label"), "Desligado");
});

test("an on-demand device between readings says when it last read, and not that it is offline", () => {
    const els = detailEls();

    select(els, {
        device: { ...device, online: false, presence: "idle", lastSeenAt: new Date(Date.now() - 3 * 3600 * 1000).toISOString() },
        model,
        capabilities: {},
    });

    assert.equal(els.deviceBandDot.getAttribute("aria-label"), "Última leitura há 3 h");
    assert.doesNotMatch(els.selectedDeviceBadge.textContent, /Desligado/);
    assert.match(els.selectedDeviceBadge.textContent, /Última leitura/);
});

test("with no device chosen, the band and the tabs go away", () => {
    const els = detailEls();
    cacheElements(els);
    state.selectedDetail = null;

    renderSelection();

    assert.equal(els.deviceBand.classList.contains("d-none"), true);
    assert.equal(els.deviceTabs.classList.contains("d-none"), true);
});
