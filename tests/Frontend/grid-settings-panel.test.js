import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { buildSettingsPanel } = await import("../../src/Dashboard/dashboard/grid.js");

/**
 * O painel fecha-se por um clique fora, e o que está dentro dele não é fora. Reconstruir a
 * lista das colunas tira do documento o próprio botão que se carregou, e a partir daí o
 * `contains` do ouvinte de fecho responde que não -- o painel desaparecia por se lhe mexer.
 */
function fakeColumn(colId, headerName, visible = true) {
    let shown = visible;

    return {
        getColId: () => colId,
        getColDef: () => ({ field: colId, headerName }),
        isVisible: () => shown,
        setVisible: (next) => {
            shown = next;
        },
    };
}

function fakeGrid() {
    const columns = [fakeColumn("company_name", "Empresa"), fakeColumn("name", "Nome")];
    const calls = [];

    return {
        columns,
        calls,
        api: {
            getColumns: () => columns,
            setColumnsVisible: (ids, visible) => {
                columns.filter((c) => ids.includes(c.getColId())).forEach((c) => c.setVisible(visible));
                calls.push(`visivel:${ids.join(",")}=${visible}`);
            },
            autoSizeAllColumns: () => calls.push("larguras"),
            setFilterModel: () => calls.push("filtros"),
            applyColumnState: () => calls.push("colunas"),
        },
    };
}

function openPanel() {
    const grid = fakeGrid();
    const host = document.createElement("div");
    document.body.appendChild(host);
    const anchor = document.createElement("button");
    host.appendChild(anchor);

    const panel = buildSettingsPanel(grid.api, host, []);
    host.appendChild(panel.element);
    panel.toggle(anchor);

    return { ...grid, panel, host };
}

const isOpen = (panel) => panel.element.classList.contains("show");
const actionNamed = (panel, label) =>
    [...panel.element.querySelectorAll("button.dropdown-item")]
        .find((b) => b.textContent.trim() === label);

test("carregar numa acção do painel não o fecha", () => {
    for (const label of ["Ajustar larguras", "Limpar filtros", "Limpar ordenação", "Repor colunas"]) {
        const { panel } = openPanel();

        actionNamed(panel, label).dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

        assert.equal(isOpen(panel), true, `o painel fechou-se ao carregar em "${label}"`);
    }
});

test("esconder uma coluna deixa o painel aberto e a caixa desmarcada", () => {
    const { panel, calls } = openPanel();
    const checkbox = panel.element.querySelector("input[type=checkbox]");

    // Um clique na etiqueta alterna a caixa e sobe até ao documento, que é onde o ouvinte
    // de fecho o vê.
    checkbox.closest("label").dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

    assert.deepEqual(calls, ["visivel:company_name=false"]);
    assert.equal(isOpen(panel), true);
});

test("um clique fora fecha-o", () => {
    const { panel } = openPanel();

    document.body.dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

    assert.equal(isOpen(panel), false);
});
