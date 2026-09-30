import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

/**
 * Fechar o modal a meio da ficha de um modelo e voltar a abri-lo deixava a ficha no ecrã,
 * com a imagem, os campos e os interruptores -- mas o estado tinha sido limpo, e o
 * «Guardar capacidades» respondia «Selecione um modelo» a quem estava a olhar para ele.
 */
const slides = [];

/** O carrossel do Bootstrap, reduzido ao que estes módulos lhe pedem. */
globalThis.bootstrap = {
    Carousel: class {
        constructor(element) {
            this._element = element;
        }

        to(index) {
            slides.push(index);
        }
    },
};

globalThis.fetch = () =>
    Promise.resolve({
        ok: true,
        status: 200,
        text: () => Promise.resolve("{\"data\":[]}"),
    });

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const { backToModelList, loadSettingsModelsSection } = await import(
    "../../src/Dashboard/dashboard/settings/models/list.js",
);

const elementWithId = (id) => {
    const element = document.createElement("div");
    element.id = id;
    document.body.append(element);
    return element;
};

const els = {
    modelsCarousel: (() => {
        const carousel = elementWithId("modelsCarousel");
        carousel.innerHTML =
            "<div class=\"carousel-inner\"><div class=\"carousel-item\"></div>" +
            "<div class=\"carousel-item\"></div><div class=\"carousel-item active\"></div></div>";
        return carousel;
    })(),
    modelsBreadcrumb: elementWithId("modelsBreadcrumb"),
    modelsBreadcrumbModels: elementWithId("modelsBreadcrumbModels"),
    modelsBreadcrumbNew: elementWithId("modelsBreadcrumbNew"),
    modelsBreadcrumbCurrent: elementWithId("modelsBreadcrumbCurrent"),
    settingsCloseBtn: elementWithId("settingsCloseBtn"),
    modelDetailActionBar: elementWithId("modelDetailActionBar"),
    modelCatalog: elementWithId("modelCatalog"),
    modelsTabSummary: elementWithId("modelsTabSummary"),
    modelsListSearch: elementWithId("modelsListSearch"),
};

const onTheModelSheet = () => {
    slides.length = 0;
    els.modelDetailActionBar.className = "d-flex";
    els.modelsBreadcrumb.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.textContent = "R03";
    state.settingsModal.modelsCarousel = new bootstrap.Carousel(els.modelsCarousel);
    state.settingsModal.currentCapabilitiesModel = { id: 92 };
};

test("voltar à lista fecha a ficha, a migalha e a barra de gravar", () => {
    initSettingsModels({ els, ui: {} });
    onTheModelSheet();
    state.settingsModal.sectionLoaded.models = true;

    backToModelList();

    assert.deepEqual(slides, [0]);
    assert.equal(els.modelDetailActionBar.className, "d-none");
    assert.equal(els.modelsBreadcrumb.classList.contains("d-none"), true);
    assert.equal(state.settingsModal.currentCapabilitiesModel, null);
});

/**
 * Carregar o separador é o que acontece ao reabrir o modal, e o ecrã de entrada dele é a
 * lista. Sem isto o carrossel ficava na ficha, que o estado já não tinha como preencher.
 */
test("carregar o catálogo traz o carrossel de volta à lista", async () => {
    initSettingsModels({ els, ui: {} });
    onTheModelSheet();

    await loadSettingsModelsSection();

    assert.deepEqual(slides, [0], "o carrossel ficou na ficha de um modelo que já não existe");
    assert.equal(els.modelDetailActionBar.className, "d-none");
    assert.equal(state.settingsModal.currentCapabilitiesModel, null);
});
