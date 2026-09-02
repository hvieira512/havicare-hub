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
function eventoDeCelula(field, oldValue, newValue) {
    const data = { id: 4, [field]: newValue };

    return {
        data,
        colDef: { field },
        oldValue,
        node: {
            setDataValue: () => assert.fail("o recuo não pode passar pelo evento de alteração"),
        },
        api: { refreshCells: (options) => refrescadas.push(options) },
    };
}

let refrescadas = [];

test("uma gravação recusada repõe o valor antigo sem disparar outra alteração", async () => {
    refrescadas = [];
    const gravados = [];
    const erros = [];
    const handler = cellSaver(async (row, field) => {
        gravados.push(row[field]);
        throw new Error("must be of type ?int");
    }, (error) => erros.push(error.message));

    const evento = eventoDeCelula("username", "antigo", "novo");
    await handler(evento);

    assert.deepEqual(gravados, ["novo"], "a gravação recusada foi tentada uma vez e mais nenhuma");
    assert.equal(evento.data.username, "antigo");
    assert.deepEqual(erros, ["must be of type ?int"]);
    assert.equal(refrescadas.length, 1, "a célula reposta é repintada, senão fica a mostrar o valor recusado");
    assert.deepEqual(refrescadas[0].columns, ["username"]);
    assert.equal(refrescadas[0].force, true);
});

test("uma gravação aceite não repõe nem repinta nada", async () => {
    refrescadas = [];
    const erros = [];
    const handler = cellSaver(async () => {}, (error) => erros.push(error.message));

    const evento = eventoDeCelula("username", "antigo", "novo");
    await handler(evento);

    assert.equal(evento.data.username, "novo");
    assert.deepEqual(erros, []);
    assert.deepEqual(refrescadas, []);
});

/** Falhar uma célula não pode calar a seguinte. */
test("depois de um recuo, a célula seguinte volta a gravar", async () => {
    const gravados = [];
    const handler = cellSaver(async (row, field) => {
        gravados.push(row[field]);
        if (gravados.length === 1) {
            throw new Error("recusado");
        }
    }, () => {});

    await handler(eventoDeCelula("username", "antigo", "novo"));
    await handler(eventoDeCelula("role", "hub_admin", "license_client"));

    assert.deepEqual(gravados, ["novo", "license_client"]);
});
