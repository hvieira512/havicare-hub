import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { buildSettingsPanel } = await import("../../src/Dashboard/dashboard/grid.js");

/**
 * O painel fecha-se por um clique fora, e o que está dentro dele não é fora. Reconstruir a
 * lista das colunas tira do documento o próprio botão que se carregou, e a partir daí o
 * `contains` do ouvinte de fecho responde que não -- o painel desaparecia por se lhe mexer.
 */
function colunaFalsa(colId, headerName, visible = true) {
    let shown = visible;

    return {
        getColId: () => colId,
        getColDef: () => ({ field: colId, headerName }),
        isVisible: () => shown,
        setVisible: (next) => { shown = next; },
    };
}

function grelhaFalsa() {
    const columns = [colunaFalsa("company_name", "Empresa"), colunaFalsa("name", "Nome")];
    const feito = [];

    return {
        columns,
        feito,
        api: {
            getColumns: () => columns,
            setColumnsVisible: (ids, visible) => {
                columns.filter((c) => ids.includes(c.getColId())).forEach((c) => c.setVisible(visible));
                feito.push(`visivel:${ids.join(",")}=${visible}`);
            },
            autoSizeAllColumns: () => feito.push("larguras"),
            setFilterModel: () => feito.push("filtros"),
            applyColumnState: () => feito.push("colunas"),
        },
    };
}

function painelAberto() {
    const grelha = grelhaFalsa();
    const host = document.createElement("div");
    document.body.appendChild(host);
    const anchor = document.createElement("button");
    host.appendChild(anchor);

    const painel = buildSettingsPanel(grelha.api, host, []);
    host.appendChild(painel.element);
    painel.toggle(anchor);

    return { ...grelha, painel, host };
}

const aberto = (painel) => painel.element.classList.contains("show");
const accao = (painel, texto) =>
    [...painel.element.querySelectorAll("button.dropdown-item")]
        .find((b) => b.textContent.trim() === texto);

test("carregar numa acção do painel não o fecha", () => {
    for (const texto of ["Ajustar larguras", "Limpar filtros", "Limpar ordenação", "Repor colunas"]) {
        const { painel } = painelAberto();

        accao(painel, texto).dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

        assert.equal(aberto(painel), true, `o painel fechou-se ao carregar em "${texto}"`);
    }
});

test("esconder uma coluna deixa o painel aberto e a caixa desmarcada", () => {
    const { painel, feito } = painelAberto();
    const caixa = painel.element.querySelector("input[type=checkbox]");

    // Um clique na etiqueta alterna a caixa e sobe até ao documento, que é onde o ouvinte
    // de fecho o vê.
    caixa.closest("label").dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

    assert.deepEqual(feito, ["visivel:company_name=false"]);
    assert.equal(aberto(painel), true);
});

test("um clique fora fecha-o", () => {
    const { painel } = painelAberto();

    document.body.dispatchEvent(new window.MouseEvent("click", { bubbles: true }));

    assert.equal(aberto(painel), false);
});
