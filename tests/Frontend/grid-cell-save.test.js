import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { cellSaver } = await import("../../src/Dashboard/dashboard/grid.js");

/**
 * Repor o valor antigo pelo `setDataValue` é ele próprio uma alteração de célula, e o AG Grid
 * volta a disparar este evento por causa dela -- fora do turno síncrono, onde uma bandeira
 * levantada e baixada à volta da chamada já não a apanha. Com os dois valores recusados, a
 * recusa fica a alternar entre eles e cada volta é outra escrita: foram sete mil em dois
 * segundos. O recuo tem de mexer nos dados e repintar, sem passar pelo evento.
 */
function cellEvent(field, oldValue, newValue) {
    const data = { id: 4, [field]: newValue };

    return {
        data,
        colDef: { field },
        oldValue,
        node: {
            setDataValue: () => assert.fail("o recuo não pode passar pelo evento de alteração"),
        },
        api: { refreshCells: (options) => repaints.push(options) },
    };
}

let repaints = [];

test("uma gravação recusada repõe o valor antigo sem disparar outra alteração", async () => {
    repaints = [];
    const saved = [];
    const errors = [];
    const handler = cellSaver(async (row, field) => {
        saved.push(row[field]);
        throw new Error("must be of type ?int");
    }, (error) => errors.push(error.message));

    const event = cellEvent("username", "antigo", "novo");
    await handler(event);

    assert.deepEqual(saved, ["novo"], "a gravação recusada foi tentada uma vez e mais nenhuma");
    assert.equal(event.data.username, "antigo");
    assert.deepEqual(errors, ["must be of type ?int"]);
    assert.equal(repaints.length, 1, "a célula reposta é repintada, senão fica a mostrar o valor recusado");
    assert.deepEqual(repaints[0].columns, ["username"]);
    assert.equal(repaints[0].force, true);
});

test("uma gravação aceite não repõe nem repinta nada", async () => {
    repaints = [];
    const errors = [];
    const handler = cellSaver(async () => {}, (error) => errors.push(error.message));

    const event = cellEvent("username", "antigo", "novo");
    await handler(event);

    assert.equal(event.data.username, "novo");
    assert.deepEqual(errors, []);
    assert.deepEqual(repaints, []);
});

/** Falhar uma célula não pode calar a seguinte. */
test("depois de um recuo, a célula seguinte volta a gravar", async () => {
    const saved = [];
    const handler = cellSaver(async (row, field) => {
        saved.push(row[field]);
        if (saved.length === 1) {
            throw new Error("recusado");
        }
    }, () => {});

    await handler(cellEvent("username", "antigo", "novo"));
    await handler(cellEvent("role", "hub_admin", "license_client"));

    assert.deepEqual(saved, ["novo", "license_client"]);
});
