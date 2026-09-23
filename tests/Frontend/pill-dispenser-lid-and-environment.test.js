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

test("o ambiente diz se está dentro ou fora da gama", () => {
    assert.equal(
        uplinkCardContent("storage_environment", { outOfRange: true }).value,
        "Fora da gama",
    );
    assert.equal(
        uplinkCardContent("storage_environment", { outOfRange: false }).value,
        "Dentro da gama",
    );
});

/**
 * O cartão do ambiente explica-se: é o juízo do aparelho sobre a temperatura e a humidade
 * que ele próprio mede, e sem isso continua a ser um estado sem causa visível.
 */
test("o ambiente diz de onde tira a conclusão", () => {
    const { details } = uplinkCardContent("storage_environment", { outOfRange: true });

    assert.match(details, /[Tt]emperatura/);
    assert.match(details, /[Hh]umidade/);
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
