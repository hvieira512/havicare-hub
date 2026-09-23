import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * O valor reportado de uma configuração, na lista de eventos.
 *
 * O `device_config` traz um mapa: a chave é a definição e o valor é o que o aparelho diz ter
 * lá dentro. Sem renderizador próprio caía no genérico, que lista os quatro primeiros campos
 * e passa cada um por `String()` -- o mapa saía como `Definições: [object Object]`, que não
 * diz nem que definição é nem em que estado ficou.
 */
test("as definições reportadas aparecem uma a uma", () => {
    const { details } = uplinkCardContent("device_config", {
        settings: { child_lock: { enabled: true } },
    });

    assert.doesNotMatch(details, /\[object/);
    assert.match(details, /Sim|Ligado/);
});

/** Com mais do que uma, o valor conta-as em vez de escolher uma ao acaso. */
test("o valor diz quantas definições vieram", () => {
    const { value } = uplinkCardContent("device_config", {
        settings: { child_lock: { enabled: true }, alarm_volume: { volume: 2 } },
    });

    assert.match(value, /2/);
});

/** Um mapa vazio não desenha uma linha em branco. */
test("sem definições não há detalhe nenhum", () => {
    const { details } = uplinkCardContent("device_config", { settings: {} });

    assert.equal(details, "");
});
