import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * O valor de cada cartão é o estado em que a coisa está, e não um «Sim» ou um «Não» que
 * obriga a reler o título para saber a que responde.
 */
test("o copo da medicação diz se está colocado ou retirado", () => {
    assert.equal(uplinkCardContent("medication_cup", { inserted: true }).value, "Colocado");
    assert.equal(uplinkCardContent("medication_cup", { inserted: false }).value, "Retirado");
});

/** O ambiente só chega quando dispara: o valor diz o que aconteceu, e não leva legenda fixa. */
test("o ambiente diz o que aconteceu", () => {
    const { value, details } = uplinkCardContent("storage_environment", { outOfRange: true });

    assert.match(value, /[Tt]emperatura/);
    assert.match(value, /[Hh]umidade/);
    assert.equal(details || "", "");
});

/** O sinal do dispensador é a `connectivity` genérica, com o formato dos gateways. */
test("o sinal desenha-se como conectividade", () => {
    const { value } = uplinkCardContent("connectivity", {
        interface: "cellular",
        signalStrengthDbm: -25,
    });

    assert.match(value, /-25 dBm/);
    assert.match(value, /[Rr]ede móvel/);
});

/** A corrente viaja com a bateria, que é a mesma pergunta feita do outro lado. */
test("a bateria diz se está ligada à corrente", () => {
    const { details } = uplinkCardContent("battery", {
        percent: 100,
        chargingState: "charging",
        mainsPowered: true,
    });

    assert.match(details, /corrente/i);
});
