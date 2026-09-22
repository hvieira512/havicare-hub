import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state, resetDetailFiltersDraft, updateDetailFiltersDraft } =
    await import("../../src/Dashboard/dashboard/state.js");
const { initDetailFilters, syncDetailFilterControls } =
    await import("../../src/Dashboard/dashboard/devices/detail-filters.js");

/**
 * Cada nome pedido devolve um elemento a sério, criado à medida: o `syncDetailFilterControls`
 * escreve em três campos e a seguir redesenha as pastilhas, que tocam noutros tantos.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) {
            target[name] = document.createElement(
                name.startsWith("detailFilter") ? "input" : "div",
            );
        }
        return target[name];
    },
});

beforeEach(() => {
    initDetailFilters({ els, onChange: () => {} });
    state.detailFilters = { from: "2026-09-20T08:00", to: "", type: "all", q: "" };
    resetDetailFiltersDraft();
});

/**
 * O rascunho é o que está nos campos antes de se carregar em «Aplicar». Apagar a data e
 * esperar por uma mensagem do stream -- que redesenha o ecrã -- devolvia a data ao campo,
 * porque um `""` de propósito não se distinguia de «não há rascunho».
 */
test("uma data apagada no rascunho não é ressuscitada pelo valor aplicado", () => {
    updateDetailFiltersDraft({ from: "" });

    syncDetailFilterControls();

    assert.equal(els.detailFilterFrom.value, "", "o campo devia ficar vazio");
});

test("sem rascunho, os campos mostram o que está aplicado", () => {
    state.detailFiltersDraft = null;

    syncDetailFilterControls();

    assert.equal(els.detailFilterFrom.value, "2026-09-20T08:00");
});

test("um rascunho por aplicar sobrepõe-se ao valor aplicado", () => {
    updateDetailFiltersDraft({ from: "2026-09-21T09:30" });

    syncDetailFilterControls();

    assert.equal(els.detailFilterFrom.value, "2026-09-21T09:30");
});
