import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";

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

/** A corrente viaja com a bateria, e diz-se no canto do ícone: cheio na ficha não carrega. */
test("a bateria diz se está ligada à corrente", () => {
    const { iconBadge } = uplinkCardContent("battery", {
        percent: 100,
        chargingState: "full",
        mainsPowered: true,
    });

    assert.equal(iconBadge, "fa-plug");
});
