import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { ServerSelectFloatingFilter } = await import("../../src/Dashboard/dashboard/grid.js");

/**
 * As contagens de uma faceta são recalculadas a cada pedido, sem o filtro da própria coluna
 * -- é o servidor que as manda em cada resposta. O cabeçalho tem de as acompanhar: ficar
 * com as do primeiro pedido faz o número mentir e esconde valores que entretanto passaram
 * a existir.
 */
function filtroDe(options, labels = {}) {
    const filtro = new ServerSelectFloatingFilter();
    filtro.init({
        options,
        labels,
        colDef: { headerName: "Empresa" },
        parentFilterInstance: (apply) => apply({ setModel: () => {} }),
        api: { onFilterChanged: () => {} },
    });

    return filtro;
}

const textos = (filtro) => [...filtro.getGui().options].map((o) => o.textContent);
const valores = (filtro) => [...filtro.getGui().options].map((o) => o.value);

test("as opções e as contagens vêm do descritor", () => {
    const filtro = filtroDe([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);

    assert.deepEqual(textos(filtro), ["Todos", "havicare (2)", "hitcare (4)"]);
});

test("uma resposta nova substitui as contagens antigas", () => {
    const filtro = filtroDe([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);

    filtro.setOptions([{ value: "hitcare", count: 3 }]);

    assert.deepEqual(textos(filtro), ["Todos", "hitcare (3)"]);
});

/**
 * O valor escolhido é o que a faceta não conta, e por isso pode não vir na lista nova. Sem
 * esta regra o `<select>` saltava para "Todos" com o filtro ainda a estreitar a tabela.
 */
test("o valor escolhido fica na lista mesmo quando o servidor deixa de o mandar", () => {
    const filtro = filtroDe([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);
    filtro.onParentModelChanged({ value: "havicare" });

    filtro.setOptions([{ value: "hitcare", count: 3 }]);

    assert.deepEqual(textos(filtro), ["Todos", "havicare (0)", "hitcare (3)"]);
    assert.equal(filtro.getGui().value, "havicare");
});

test("as etiquetas traduzem o valor, e o valor continua a ser o que vai no pedido", () => {
    const filtro = filtroDe([{ value: "watch", count: 10 }], { watch: "Relógio" });

    assert.deepEqual(textos(filtro), ["Todos", "Relógio (10)"]);
    assert.deepEqual(valores(filtro), ["", "watch"]);
});

test("uma contagem ausente não desenha parênteses vazios", () => {
    const filtro = filtroDe([{ value: "hitcare", count: null }]);

    assert.deepEqual(textos(filtro), ["Todos", "hitcare"]);
});
