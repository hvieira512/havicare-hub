import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const { renderModelsSection } = await import(
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

test("a árvore agrupa por tipo e o fornecedor é um dado da linha, não uma pasta", () => {
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

test("um tipo sem modelos não desenha uma moldura vazia", () => {
    const { root } = render();

    assert.equal(root.querySelectorAll(".card").length, 3);
    assert.doesNotMatch(root.innerHTML, /Radar/);
});

test("um fornecedor que serve dois tipos aparece nos dois, e conta uma vez no resumo", () => {
    const { root, summary } = render();

    // A MOKO faz gateways e pulseiras: são duas coisas diferentes de suportar, e é por
    // isso que a tabela `supplier_device_types` existe. Dois nós, um fornecedor.
    const mokoRows = [...root.querySelectorAll(".catalog-model")]
        .filter((row) => row.textContent.includes("MOKO"));
    assert.equal(mokoRows.length, 2);
    assert.equal(summary.textContent, "3 tipos · 3 fornecedores · 5 modelos");
});

test("a linha diz o fornecedor, e o nome interno só quando difere do comercial", () => {
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

test("a busca achata a árvore e devolve o tipo a cada linha", () => {
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

test("a busca encontra pelo tipo e pelo modelo interno, não só pelo nome comercial", () => {
    assert.equal(render(CATALOG, "pulseira").root.querySelectorAll(".catalog-model").length, 1);
    assert.equal(render(CATALOG, "y6s").root.querySelectorAll(".catalog-model").length, 1);
    assert.equal(render(CATALOG, "relógio").root.querySelectorAll(".catalog-model").length, 3);
});

test("uma busca sem resultados diz que não encontrou, e não fica em branco", () => {
    const { root, summary } = render(CATALOG, "nao-existe");

    assert.match(root.textContent, /Nenhum modelo encontrado/);
    assert.equal(summary.textContent, "Sem resultados");
});

test("cada tipo abre e fecha o seu grupo", () => {
    const { root } = render();

    const target = root
        .querySelector(".card-body > button[data-bs-target]")
        .getAttribute("data-bs-target");
    assert.equal(target, "#catalogType-watch");
    assert.equal(root.querySelectorAll(`${target} .catalog-model`).length, 3);
});
