import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { detailFilterChipLabels, filterDetailItems } =
    await import("../../src/Dashboard/dashboard/devices/detail.js");

/**
 * A actividade fala português em todo o lado menos onde interessa.
 *
 * A linha mostra "Bateria", o select do tipo mostra "Bateria", mas a pesquisa comparava com
 * o `fieldLabel`, que é o dicionário dos campos do payload -- `speedKmh`, `pressType` -- e
 * não das capacidades. Sem entrada para `battery`, caía no `titleize` e o que estava lá era
 * "Battery": procurar por "bateria" não devolvia nada. A pastilha do filtro aplicado tinha o
 * mesmo problema e mostrava BATTERY ao lado de um select que dizia Bateria.
 */

function battery(percent) {
    return {
        _source: "telemetry",
        raw: {},
        payload: {
            type: "battery",
            occurredAt: "2026-09-08T10:00:00Z",
            data: { percent },
        },
    };
}

beforeEach(() => {
    // A etiqueta vem do catálogo de capacidades, que é o mesmo que enche o select do tipo.
    state.capabilityCatalogByType.watch = [
        { key: "battery", label: "Bateria", section: "telemetry" },
    ];
    state.selectedDetail = { model: { deviceType: "watch" } };
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
});

test("procurar pela etiqueta em português encontra a linha", () => {
    state.detailFilters = { ...state.detailFilters, q: "bateria" };

    assert.equal(filterDetailItems([battery(70)]).length, 1);
});

test("procurar pela chave em inglês continua a encontrá-la", () => {
    // Quem opera o hub conhece as chaves do contrato, e elas não deixam de servir.
    state.detailFilters = { ...state.detailFilters, q: "battery" };

    assert.equal(filterDetailItems([battery(70)]).length, 1);
});

test("a pastilha do filtro de tipo mostra a etiqueta e não a chave", () => {
    const labels = detailFilterChipLabels({
        from: "",
        to: "",
        type: "battery",
        q: "",
    });

    assert.deepEqual(labels, [{ key: "type", label: "Bateria" }]);
});

test("o texto procurado aparece na pastilha como foi escrito", () => {
    const labels = detailFilterChipLabels({
        from: "",
        to: "",
        type: "all",
        q: " bateria ",
    });

    assert.deepEqual(labels, [{ key: "q", label: "\"bateria\"" }]);
});
