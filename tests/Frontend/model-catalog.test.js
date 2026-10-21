import test from "node:test";
import assert from "node:assert/strict";

/** O carrossel do Bootstrap, reduzido a pôr o `active` no painel pedido. */
globalThis.bootstrap = {
    Carousel: {
        getOrCreateInstance: (element) => ({
            to: (index) => [...element.querySelectorAll(".carousel-item")]
                .forEach((item, at) => item.classList.toggle("active", at === index)),
        }),
    },
};

globalThis.fetch = async () => Response.json({ data: [] });

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels, showModelsPane } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const { backToModelList, loadSettingsModelsSection, renderModelsSection } = await import(
    "../../src/Dashboard/dashboard/settings/models/list.js",
);

/**
 * O catálogo desenha-se a partir da resposta de `/api/device-types/suppliers/models`, que
 * traz um grupo por cada tipo de dispositivo que existe -- tenha ou não modelos.
 */
const model = (id, commercialName, internalModel, supplier, deviceType) => ({
    id,
    supplier,
    internalModel,
    commercialName,
    deviceType,
    image: "",
});

const CATALOG = [
    {
        deviceType: "watch",
        suppliers: [
            {
                id: 3,
                name: "4P Touch",
                models: [
                    model(91, "D41", "D41", "4P Touch", "watch"),
                    model(92, "R03", "Y6S", "4P Touch", "watch"),
                ],
            },
            {
                id: 5,
                name: "Wonlex",
                models: [model(93, "HW20PRO", "HW20PRO", "Wonlex", "watch")],
            },
        ],
    },
    {
        deviceType: "gateway",
        suppliers: [
            {
                id: 4,
                name: "MOKO",
                models: [model(94, "MOKOSmart MKGW3", "MKGW3", "MOKO", "gateway")],
            },
        ],
    },
    {
        deviceType: "bracelet",
        suppliers: [
            {
                id: 4,
                name: "MOKO",
                models: [model(95, "MOKO W6B", "W6B", "MOKO", "bracelet")],
            },
        ],
    },
    // Um tipo sem fornecedores: a API devolve-o na mesma, para quem monta selectores.
    { deviceType: "radar", suppliers: [] },
];

function render(catalog = CATALOG, query = "") {
    const root = document.createElement("div");
    const summary = document.createElement("div");
    initSettingsModels({ els: { modelCatalog: root, modelsTabSummary: summary } });
    state.settingsModal.modelCatalog = catalog;
    state.settingsModal.modelsSearchQuery = query;
    renderModelsSection();
    return { root, summary };
}

test("the tree groups by type, and the supplier is row data, not a folder", () => {
    const { root } = render();

    const types = [...root.querySelectorAll(".card > .card-body > button")].map(
        (button) => button.textContent.replace(/\s+/g, " ").trim(),
    );
    assert.deepEqual(types, [
        "Relógio 3 modelos · 2 fornecedores",
        "Gateway 1 modelo · 1 fornecedor",
        "Pulseira 1 modelo · 1 fornecedor",
    ]);

    // Um nível só: os únicos `collapse` são os dos três tipos.
    assert.equal(root.querySelectorAll("[data-bs-toggle=\"collapse\"]").length, 3);
    assert.equal(root.querySelectorAll(".tree-row-nested").length, 0);
});

test("a type without models draws no empty frame", () => {
    const { root } = render();

    assert.equal(root.querySelectorAll(".card").length, 3);
    assert.doesNotMatch(root.innerHTML, /Radar/);
});

test("a supplier serving two types shows under both and counts once in the summary", () => {
    const { root, summary } = render();

    // A MOKO faz gateways e pulseiras: dois nós, um fornecedor.
    const mokoRows = [...root.querySelectorAll(".catalog-model")]
        .filter((row) => row.textContent.includes("MOKO"));
    assert.equal(mokoRows.length, 2);
    assert.equal(summary.textContent, "3 tipos · 3 fornecedores · 5 modelos");
});

test("the row shows the supplier, and the internal name only when it differs from the commercial one", () => {
    const { root } = render();

    const rows = [...root.querySelectorAll(".catalog-model")];
    assert.equal(rows.length, 5);
    assert.deepEqual(
        rows.map((row) => row.dataset.id),
        ["91", "92", "93", "94", "95"],
    );
    assert.deepEqual(
        rows.map((row) => row.textContent.replace(/\s+/g, " ").trim()),
        [
            "D41 · 4P Touch",
            "R03 · 4P Touch · interno Y6S",
            "HW20PRO · Wonlex",
            "MOKOSmart MKGW3 · MOKO · interno MKGW3",
            "MOKO W6B · MOKO · interno W6B",
        ],
    );
});

test("search flattens the tree and puts the type on each row", () => {
    const { root, summary } = render(CATALOG, "moko");

    assert.equal(root.querySelectorAll(".card").length, 1);
    // Nenhum cabeçalho de grupo: um resultado dentro de um grupo fechado não é resultado.
    assert.equal(root.querySelectorAll("[data-bs-toggle=\"collapse\"]").length, 0);
    assert.deepEqual(
        [...root.querySelectorAll(".catalog-model")].map((row) =>
            row.textContent.replace(/\s+/g, " ").trim(),
        ),
        [
            "MOKOSmart MKGW3 · MOKO · Gateway · interno MKGW3",
            "MOKO W6B · MOKO · Pulseira · interno W6B",
        ],
    );
    assert.equal(summary.textContent, "2 resultados de 5");
});

test("search matches by type and internal model, not only commercial name", () => {
    assert.equal(render(CATALOG, "pulseira").root.querySelectorAll(".catalog-model").length, 1);
    assert.equal(render(CATALOG, "y6s").root.querySelectorAll(".catalog-model").length, 1);
    assert.equal(render(CATALOG, "relógio").root.querySelectorAll(".catalog-model").length, 3);
});

test("a search with no results says so instead of staying blank", () => {
    const { root, summary } = render(CATALOG, "nao-existe");

    assert.match(root.textContent, /Nenhum modelo encontrado/);
    assert.equal(summary.textContent, "Sem resultados");
});

const elementWithId = (id) => {
    const element = document.createElement("div");
    element.id = id;
    document.body.append(element);
    return element;
};

const els = {
    modelsCarousel: (() => {
        const carousel = elementWithId("modelsCarousel");
        carousel.innerHTML = "<div class=\"carousel-inner\"><div class=\"carousel-item\"></div>" +
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

const panesShown = () => [...els.modelsCarousel.querySelectorAll(".carousel-item")]
    .map((item) => item.classList.contains("active"));

const onTheModelSheet = () => {
    showModelsPane(2);
    els.modelDetailActionBar.className = "d-flex";
    els.modelsBreadcrumb.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.textContent = "R03";

    state.settingsModal.currentCapabilitiesModel = { id: 92 };
};

test("going back to the list closes the sheet, the breadcrumb and the save bar", () => {
    initSettingsModels({ els, ui: {} });
    onTheModelSheet();
    state.settingsModal.sectionLoaded.models = true;

    backToModelList();

    assert.deepEqual(panesShown(), [true, false, false]);
    assert.equal(els.modelDetailActionBar.className, "d-none");
    assert.equal(els.modelsBreadcrumb.classList.contains("d-none"), true);
    assert.equal(state.settingsModal.currentCapabilitiesModel, null);
});

/** Carregar o separador é o que reabrir o modal faz, e a entrada dele é a lista. */
test("loading the catalog brings the panes back to the list", async () => {
    initSettingsModels({ els, ui: {} });
    onTheModelSheet();

    await loadSettingsModelsSection();

    assert.deepEqual(panesShown(), [true, false, false], "ficou na ficha de um modelo que já não existe");
    assert.equal(els.modelDetailActionBar.className, "d-none");
    assert.equal(state.settingsModal.currentCapabilitiesModel, null);
});
