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
function filterWith(options, labels = {}) {
    const filter = new ServerSelectFloatingFilter();
    filter.init({
        options,
        labels,
        colDef: { headerName: "Empresa" },
        parentFilterInstance: (apply) => apply({ setModel: () => {} }),
        api: { onFilterChanged: () => {} },
    });

    return filter;
}

const optionTexts = (filter) => [...filter.getGui().options].map((o) => o.textContent);
const optionValues = (filter) => [...filter.getGui().options].map((o) => o.value);

test("as opções e as contagens vêm do descritor", () => {
    const filter = filterWith([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);

    assert.deepEqual(optionTexts(filter), ["Todos", "havicare (2)", "hitcare (4)"]);
});

test("uma resposta nova substitui as contagens antigas", () => {
    const filter = filterWith([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);

    filter.setOptions([{ value: "hitcare", count: 3 }]);

    assert.deepEqual(optionTexts(filter), ["Todos", "hitcare (3)"]);
});

/**
 * O valor escolhido é o que a faceta não conta, e por isso pode não vir na lista nova. Sem
 * esta regra o `<select>` saltava para "Todos" com o filtro ainda a estreitar a tabela.
 */
test("o valor escolhido fica na lista mesmo quando o servidor deixa de o mandar", () => {
    const filter = filterWith([{ value: "havicare", count: 2 }, { value: "hitcare", count: 4 }]);
    filter.onParentModelChanged({ value: "havicare" });

    filter.setOptions([{ value: "hitcare", count: 3 }]);

    assert.deepEqual(optionTexts(filter), ["Todos", "havicare (0)", "hitcare (3)"]);
    assert.equal(filter.getGui().value, "havicare");
});

test("as etiquetas traduzem o valor, e o valor continua a ser o que vai no pedido", () => {
    const filter = filterWith([{ value: "watch", count: 10 }], { watch: "Relógio" });

    assert.deepEqual(optionTexts(filter), ["Todos", "Relógio (10)"]);
    assert.deepEqual(optionValues(filter), ["", "watch"]);
});

test("uma contagem ausente não desenha parênteses vazios", () => {
    const filter = filterWith([{ value: "hitcare", count: null }]);

    assert.deepEqual(optionTexts(filter), ["Todos", "hitcare"]);
});
