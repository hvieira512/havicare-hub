import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * A tampa e o ambiente de armazenamento, cada um no seu cartão.
 *
 * Vinham dentro do estado do dispositivo, ao lado do sinal, e chegavam ao ecrã como
 * «Tampa aberta: Não · Ligado à corrente: Sim · Ambiente fora da gama: Não» — três coisas sem
 * relação nenhuma, numa linha com o nome de nenhuma delas, e a última com um nome que não
 * dizia o que era.
 *
 * O valor de cada cartão é o estado em que a coisa está, e não um «Sim» ou um «Não» que
 * obriga a reler o título para saber a que respondem.
 */
test("a tampa diz se está aberta ou fechada", () => {
    assert.equal(uplinkCardContent("lid_state", { open: true }).value, "Aberta");
    assert.equal(uplinkCardContent("lid_state", { open: false }).value, "Fechada");
});

/**
 * O ambiente é um alerta e só chega quando dispara, e por isso o valor diz o que aconteceu em
 * vez de dizer em que estado se está — e não leva legenda fixa por baixo, que se repetia
 * linha após linha sem nunca mudar.
 */
test("o ambiente diz o que aconteceu", () => {
    const { value, details } = uplinkCardContent("storage_environment", { outOfRange: true });

    assert.match(value, /[Tt]emperatura/);
    assert.match(value, /[Hh]umidade/);
    assert.equal(details || "", "");
});

/** O estado do dispositivo fica com o sinal, e já não carrega o resto. */
test("o estado do dispositivo é só o sinal", () => {
    const { value, details } = uplinkCardContent("device_status", {
        gsmSignalDbm: -25,
        signalLevel: 3,
    });

    assert.equal(value, "-25 dBm");
    assert.doesNotMatch(details, /Tampa|corrente|gama/);
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
